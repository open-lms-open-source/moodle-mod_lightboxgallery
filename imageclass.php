<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/gdlib.php');

/**
 *
 */
define('LIGHTBOXGALLERY_POS_HID', 2);
/**
 *
 */
define('LIGHTBOXGALLERY_POS_TOP', 1);
/**
 *
 */
define('LIGHTBOXGALLERY_POS_BOT', 0);

/**
 * Main image class with all image manipulations as methods
 *
 * @package   mod_lightboxgallery
 * @copyright 2010 John Kelsh
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lightboxgallery_image {
    /** @var int The width of a thumbnail, in pixels. */
    public const THUMBNAIL_WIDTH = 162;

    /** @var int The height of a thumbnail, in pixels. */
    public const THUMBNAIL_HEIGHT = 132;

    /** @var int How many characters of a caption to show when the gallery doesn't show full captions. */
    public const CAPTION_PREVIEW_LENGTH = 13;

    /** @var int The largest width or height, in pixels, that a resize can produce. */
    public const MAX_DIMENSION = 4096;

    /** @var int How many thumbnails one request may generate before the rest are left to a background task. */
    public const SYNC_THUMBNAIL_LIMIT = 10;

    /** @var int|null How many more thumbnails this request may generate; null for no limit. */
    private static $thumbnailbudget = self::SYNC_THUMBNAIL_LIMIT;

    /** @var int[] Course modules this request has already queued thumbnail generation for. */
    private static $queuedcmids = [];

    /** @var bool Whether this image's thumbnail is waiting to be generated in the background. */
    private $thumbnailpending = false;

    /**
     * The course module object.
     *
     * @var course_module
     */
    private $cm;

    /**
     * The course module ID.
     *
     * @var int
     */
    private $cmid;

    /**
     * The course module context.
     *
     * @var \core\context\module|false
     */
    private $context;

    /**
     * The gallery object.
     *
     * @var stdClass
     */
    private $gallery;

    /**
     * The URL for the image.
     *
     * @var \core\url
     */
    private $imageurl;

    /**
     * A quick lookup cache of this images metadata. Mainly useful during initial display.
     * @var mixed|null
     */
    private $metadata = null;

    /**
     * The filepool object.
     *
     * @var stored_file
     */
    private $storedfile;

    /**
     * The tags for this image.
     * @var array
     */
    private $tags;
    /**
     * @var bool|mixed|stored_file
     */
    private $thumbnail;
    /**
     * The URL for the thumbnail.
     * @var \core\url
     */
    private $thumburl;

    /**
     * @var mixed|null
     */
    public $height = null;
    /**
     * @var mixed|null
     */
    public $width = null;

    /**
     * Constructor.
     *
     * @param stdClass $storedfile
     * @param stdClass $gallery
     * @param context_module $cm
     * @param stdClass|null $metadata
     * @param bool|null $thumbnail
     * @param bool|null  $loadextrainfo
     */
    public function __construct($storedfile, $gallery, $cm, $metadata = null, $thumbnail = false, $loadextrainfo = true) {
        global $CFG;

        $this->storedfile = &$storedfile;
        $this->gallery = &$gallery;
        $this->cm = &$cm;
        $this->cmid = $cm->id;
        $this->context = context_module::instance($cm->id);

        $this->imageurl = moodle_url::make_pluginfile_url(
            $this->context->id,
            'mod_lightboxgallery',
            'gallery_images',
            $this->storedfile->get_itemid(),
            $this->storedfile->get_filepath(),
            $this->storedfile->get_filename()
        );
        $this->imageurl->param('mtime', $this->storedfile->get_timemodified());

        $this->thumburl = moodle_url::make_pluginfile_url(
            $this->context->id,
            'mod_lightboxgallery',
            'gallery_thumbs',
            0,
            $this->storedfile->get_filepath(),
            $this->storedfile->get_filename() . '.png'
        );

        if ($this->storedfile->get_mimetype() == 'image/svg+xml') {
            $this->thumburl = $this->imageurl;
        }

        if ($loadextrainfo) {
            $imageinfo = $this->storedfile->get_imageinfo();
            $this->height = $imageinfo['height'];
            $this->width = $imageinfo['width'];
        }

        // If we weren't given a thumbnail, double check if it exists before generating one.
        // Only a few are generated per request; the rest are left to a background task.
        $thumbnail = $thumbnail ?: $this->get_thumbnail();
        if ($thumbnail) {
            $this->use_thumbnail($thumbnail);
        } else if ($this->storedfile->get_mimetype() == 'image/svg+xml' || self::claim_thumbnail_budget()) {
            $this->use_thumbnail($this->create_thumbnail());
        } else {
            $this->thumbnailpending = true;
            self::queue_thumbnail_generation($this->cmid);
        }

        $this->metadata = $metadata;
    }

    /**
     * Add a tag to the image.
     *
     * @param stdClass $tag
     * @return bool|int
     * @throws dml_exception
     */
    public function add_tag($tag) {
        global $DB;

        $imagemeta = new stdClass();
        $imagemeta->gallery = $this->cm->instance;
        $imagemeta->image = $this->storedfile->get_filename();
        $imagemeta->metatype = 'tag';
        $imagemeta->description = $tag;

        return $DB->insert_record('lightboxgallery_image_meta', $imagemeta);
    }

    /**
     * Create a thumbnail of the image.
     *
     * @param int $offsetx
     * @param int $offsety
     * @return stored_file
     * @throws file_exception
     * @throws stored_file_creation_exception
     */
    public function create_thumbnail($offsetx = 0, $offsety = 0) {
        if ($this->storedfile->get_mimetype() != 'image/svg+xml') {
            $this->load_dimensions();
        }
        if (
            $this->storedfile->get_mimetype() == 'image/svg+xml'
            || $this->width === null || $this->height === null
        ) {
            // We can't resize SVG or files we don't know the dimensions of.
            return $this->storedfile;
        }

        $fileinfo = [
            'contextid' => $this->context->id,
            'component' => 'mod_lightboxgallery',
            'filearea' => 'gallery_thumbs',
            'itemid' => 0,
            'filepath' => $this->storedfile->get_filepath(),
            'filename' => $this->storedfile->get_filename() . '.png', ];

        ob_start();
        imagepng($this->get_image_resized(self::THUMBNAIL_HEIGHT, self::THUMBNAIL_WIDTH, $offsetx, $offsety));
        $thumbnail = ob_get_clean();

        $this->delete_thumbnail();
        $fs = get_file_storage();
        return $fs->create_file_from_string($fileinfo, $thumbnail);
    }

    /**
     * Create the index file.
     *
     * @return stored_file
     * @throws file_exception
     * @throws stored_file_creation_exception
     */
    public function create_index() {
        global $CFG;

        $fileinfo = [
            'contextid' => $this->context->id,
            'component' => 'mod_lightboxgallery',
            'filearea' => 'gallery_index',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'index.png', ];

        $this->load_dimensions();
        $base = imagecreatefrompng($CFG->dirroot . '/mod/lightboxgallery/pix/index.png');
        $transparent = imagecolorat($base, 0, 0);

        $shrunk = imagerotate($this->get_image_resized(48, 48, 0, 0), 351, $transparent);

        imagecolortransparent($base, $transparent);

        imagecopy($base, $shrunk, 2, 3, 0, 0, imagesx($shrunk), imagesy($shrunk));

        ob_start();
        imagepng($base);
        $index = ob_get_clean();

        $fs = get_file_storage();
        return $fs->create_file_from_string($fileinfo, $index);
    }

    /**
     * Delete the file.
     *
     * @param bool|null $meta
     * @return void
     * @throws dml_exception
     */
    public function delete_file($meta = true) {
        global $DB;

        $this->delete_thumbnail();

        // Delete all image_meta records for this file.
        if ($meta) {
            $DB->delete_records('lightboxgallery_image_meta', [
                'gallery' => $this->cm->instance,
                'image' => $this->storedfile->get_filename(), ]);
        }

        $this->storedfile->delete();
    }

    /**
     * Delete a tag from this image.
     *
     * The tag must belong to this image in this gallery; any other id is ignored.
     *
     * @param int $tagid The lightboxgallery_image_meta id of the tag.
     * @return bool
     * @throws dml_exception
     */
    public function delete_tag($tagid) {
        global $DB;

        return $DB->delete_records('lightboxgallery_image_meta', [
            'id' => $tagid,
            'gallery' => $this->gallery->id,
            'image' => $this->storedfile->get_filename(),
            'metatype' => 'tag',
        ]);
    }

    /**
     * Delete the thumbnail file.
     *
     * @return void
     */
    private function delete_thumbnail() {
        if (isset($this->thumbnail) && is_object($this->thumbnail)) {
            $this->thumbnail->delete();
            unset($this->thumbnail);
        }
    }

    /**
     * Flip the image in a given direction.
     *
     * @param string $direction
     * @return string The image filename, which is unchanged.
     * @throws dml_exception
     * @throws file_exception
     * @throws moodle_exception
     * @throws stored_file_creation_exception
     */
    public function flip_image($direction) {
        $this->replace_content($this->encode_image($this->get_image_flipped($direction)));
        return $this->storedfile->get_filename();
    }

    /**
     * Get the list of editing options allowed for this image.
     * Not all editing types are supported for all image formats.
     *
     * @return string
     * @throws coding_exception
     */
    public function get_editing_options() {
        global $CFG;

        $options = [
            'caption',
            'delete',
            'flip',
            'resize',
            'rotate',
            'tag',
            'thumbnail',
        ];

        if ($this->storedfile->get_mimetype() == 'image/svg+xml') {
            $options = [
                'caption',
                'delete',
                'tag',
            ];
        }

        return $options;
    }

    /**
     * Get the image caption.
     *
     * @return string
     * @throws dml_exception
     */
    public function get_image_caption() {
        global $DB;
        $caption = '';

        if ($this->metadata !== null) {
            foreach ($this->metadata as $metarecord) {
                if ($metarecord->metatype == 'caption') {
                    return $metarecord->description;
                }
            }
        }

        if (
            $imagemeta = $DB->get_record(
                'lightboxgallery_image_meta',
                [
                    'gallery' => $this->gallery->id,
                    'image' => $this->storedfile->get_filename(),
                    'metatype' => 'caption',
                ]
            )
        ) {
            $caption = $imagemeta->description;
        }

        return $caption;
    }

    /**
     * Get the image's tile for the gallery page.
     *
     * @param bool $editing Whether to show the edit menu.
     * @param int $page The page of the gallery being shown, so editing returns to it.
     * @return string
     */
    public function get_image_display_html($editing = false, $page = 0) {
        global $OUTPUT;

        return $OUTPUT->render(new \mod_lightboxgallery\output\image_tile($this, $this->gallery, (bool) $editing, (int) $page));
    }

    /**
     * Get the image flipped in a given direction.
     *
     * @param string $direction
     * @return false|GdImage|resource
     */
    private function get_image_flipped($direction) {
        $image = imagecreatefromstring($this->storedfile->get_content());
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imageflip($image, $direction == 'vertical' ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);

        return $image;
    }

    /**
     * Get the image resized to a given width and height.
     *
     * @param int $height
     * @param int $width
     * @param int $offsetx
     * @param int $offsety
     * @return false|GdImage|resource
     */
    private function get_image_resized(
        $height = self::THUMBNAIL_HEIGHT,
        $width = self::THUMBNAIL_WIDTH,
        $offsetx = 0,
        $offsety = 0
    ) {
        raise_memory_limit(MEMORY_EXTRA);
        $image = imagecreatefromstring($this->storedfile->get_content());
        $resized = imagecreatetruecolor($width, $height);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        $cx = $this->width / 2;
        $cy = $this->height / 2;

        $ratiow = $width / $this->width;
        $ratioh = $height / $this->height;

        if ($ratiow < $ratioh) {
            $srcw = floor($width / $ratioh);
            $srch = $this->height;
            $srcx = floor($cx - ($srcw / 2)) + $offsetx;
            $srcy = $offsety;
        } else {
            $srcw = $this->width;
            $srch = floor($height / $ratiow);
            $srcx = $offsetx;
            $srcy = floor($cy - ($srch / 2)) + $offsety;
        }

        imagecopyresampled($resized, $image, 0, 0, $srcx, $srcy, $width, $height, $srcw, $srch);

        return $resized;
    }

    /**
     * Get the whole image scaled to a given width and height, without cropping.
     *
     * @param int $width
     * @param int $height
     * @return GdImage
     */
    private function get_image_scaled($width, $height) {
        raise_memory_limit(MEMORY_EXTRA);
        $image = imagecreatefromstring($this->storedfile->get_content());
        $scaled = imagecreatetruecolor($width, $height);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, $height, $this->width, $this->height);

        return $scaled;
    }

    /**
     * Get the image rotated by a given angle.
     *
     * @param int $angle
     * @return false|GdImage|resource
     */
    private function get_image_rotated($angle) {
        $image = imagecreatefromstring($this->storedfile->get_content());
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $rotated = imagerotate($image, $angle, $transparent);

        return $rotated;
    }

    /**
     * Get the image URL.
     *
     * @return \core\url
     */
    public function get_image_url() {
        return $this->imageurl;
    }

    /**
     * Get the image's file.
     *
     * @return stored_file
     */
    public function get_stored_file() {
        return $this->storedfile;
    }

    /**
     * Get the gallery's course module id.
     *
     * @return int
     */
    public function get_cmid() {
        return $this->cmid;
    }

    /**
     * Get the image tags.
     *
     * @return array
     * @throws dml_exception
     */
    public function get_tags() {
        global $DB;

        if (isset($this->tags)) {
            return $this->tags;
        }

        $tags = [];
        if ($this->metadata !== null) {
            foreach ($this->metadata as $metarecord) {
                if ($metarecord->metatype == 'tag') {
                    $tags[$metarecord->id] = $metarecord;
                }
            }
        } else {
            $tags = $DB->get_records(
                'lightboxgallery_image_meta',
                ['gallery' => $this->gallery->id, 'image' => $this->storedfile->get_filename(), 'metatype' => 'tag']
            );
        }

        return $this->tags = $tags;
    }

    /**
     * Get the thumbnail file.
     *
     * @return bool|stored_file
     */
    private function get_thumbnail() {
        $fs = get_file_storage();

        if (
            $thumbnail = $fs->get_file(
                $this->context->id,
                'mod_lightboxgallery',
                'gallery_thumbs',
                '0',
                '/',
                $this->storedfile->get_filename() . '.png'
            )
        ) {
            return $thumbnail;
        }

        return false;
    }

    /**
     * Get the thumbnail URL.
     *
     * @return \core\url|null Null while the thumbnail is waiting to be generated in the background;
     *     call ensure_thumbnail() first on a page that must show it.
     */
    public function get_thumbnail_url() {
        return $this->thumbnailpending ? null : $this->thumburl;
    }

    /**
     * Generate this image's thumbnail now if it was left for the background task.
     *
     * For pages about a single image, which should always show its thumbnail. Pages that
     * list many images rely on the per-request limit instead.
     *
     * @return void
     * @throws file_exception
     * @throws stored_file_creation_exception
     */
    public function ensure_thumbnail() {
        if (!$this->thumbnailpending) {
            return;
        }
        $this->use_thumbnail($this->create_thumbnail());
        $this->thumbnailpending = false;
    }

    /**
     * Set the thumbnail this image displays with.
     *
     * @param stored_file $thumbnail The thumbnail, or the image itself when it has no separate thumbnail.
     * @return void
     */
    private function use_thumbnail($thumbnail) {
        $this->thumbnail = $thumbnail;
        if ($thumbnail === $this->storedfile) {
            // There's no separate thumbnail, so show the image itself.
            $this->thumburl = $this->imageurl;
        } else {
            $this->thumburl->param('mtime', $thumbnail->get_timemodified());
        }
    }

    /**
     * Whether this image's thumbnail is waiting to be generated in the background.
     *
     * @return bool
     */
    public function is_thumbnail_pending() {
        return $this->thumbnailpending;
    }

    /**
     * Load the image's width and height, if they weren't loaded when it was constructed.
     *
     * @return void
     */
    private function load_dimensions() {
        if ($this->width !== null && $this->height !== null) {
            return;
        }
        if ($imageinfo = $this->storedfile->get_imageinfo()) {
            $this->width = $imageinfo['width'];
            $this->height = $imageinfo['height'];
        }
    }

    /**
     * Set how many thumbnails this request may still generate.
     *
     * @param int|null $budget The number allowed, or null for no limit (as the background task uses).
     * @return void
     */
    public static function set_thumbnail_budget(?int $budget): void {
        self::$thumbnailbudget = $budget;
        self::$queuedcmids = [];
    }

    /**
     * Use up one of this request's thumbnail generations, if any are left.
     *
     * @return bool True if the caller may generate a thumbnail or index image now.
     */
    public static function claim_thumbnail_budget(): bool {
        if (self::$thumbnailbudget === null) {
            return true;
        }
        if (self::$thumbnailbudget > 0) {
            self::$thumbnailbudget--;
            return true;
        }
        return false;
    }

    /**
     * Queue a background task to generate a gallery's missing thumbnails and index image.
     *
     * @param int $cmid The gallery's course module id.
     * @return void
     */
    public static function queue_thumbnail_generation(int $cmid): void {
        if (isset(self::$queuedcmids[$cmid])) {
            return;
        }
        self::$queuedcmids[$cmid] = true;

        $task = new \mod_lightboxgallery\task\generate_thumbnails();
        $task->set_custom_data(['cmid' => $cmid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Encode a GD image in the same format as the stored file.
     *
     * Keeping the original format means the filename, and so the image's
     * captions and tags, never need to change after an edit.
     *
     * @param GdImage $image
     * @return string The encoded image.
     * @throws moodle_exception If the format can't be written.
     */
    protected function encode_image($image) {
        $mimetype = $this->storedfile->get_mimetype();

        ob_start();
        switch ($mimetype) {
            case 'image/png':
                imagesavealpha($image, true);
                $result = imagepng($image);
                break;
            case 'image/gif':
                $result = imagegif($image);
                break;
            case 'image/jpeg':
                $result = imagejpeg($image);
                break;
            case 'image/webp':
                imagesavealpha($image, true);
                $result = function_exists('imagewebp') && imagewebp($image);
                break;
            default:
                $result = false;
        }
        $content = ob_get_clean();

        if (!$result || $content === '') {
            throw new moodle_exception('invalidfiletype', 'error', '', $this->storedfile->get_filename());
        }

        return $content;
    }

    /**
     * Replace the image's content in place.
     *
     * The new content is stored before the original is touched, and the swap is a
     * single update of the existing file record, so a failure leaves the original
     * image intact. The file keeps its id, name, captions and tags.
     *
     * @param string $content The new image content, in the same format as the original.
     * @return void
     * @throws dml_exception
     * @throws file_exception
     * @throws stored_file_creation_exception
     */
    protected function replace_content($content) {
        $fs = get_file_storage();

        $tempfile = $fs->create_file_from_string([
            'contextid' => $this->context->id,
            'component' => 'mod_lightboxgallery',
            'filearea' => 'edittemp',
            'itemid' => $this->storedfile->get_id(),
            'filepath' => '/',
            'filename' => random_string(20),
            'userid' => $this->storedfile->get_userid(),
        ], $content);

        try {
            $this->storedfile->replace_file_with($tempfile);
            $this->storedfile->set_timemodified(time());
        } finally {
            $tempfile->delete();
        }

        $this->set_stored_file($this->storedfile);
        $this->thumbnail = $this->create_thumbnail();
    }

    /**
     * Work out the size of an image scaled to fit inside a box, keeping its aspect ratio.
     *
     * The result is never larger than MAX_DIMENSION on either side.
     *
     * @param int $width The image's current width.
     * @param int $height The image's current height.
     * @param int $maxwidth The box's width.
     * @param int $maxheight The box's height.
     * @param bool $enlarge Whether a smaller image may be scaled up to fill the box.
     * @return int[] [width, height]
     */
    public static function fit_dimensions(int $width, int $height, int $maxwidth, int $maxheight, bool $enlarge): array {
        $maxwidth = min($maxwidth, self::MAX_DIMENSION);
        $maxheight = min($maxheight, self::MAX_DIMENSION);

        $ratio = min($maxwidth / $width, $maxheight / $height);
        if (!$enlarge) {
            $ratio = min($ratio, 1);
        }

        return [max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio))];
    }

    /**
     * Resize the image to fit inside a box, keeping its aspect ratio.
     *
     * @param int $width The box's width.
     * @param int $height The box's height.
     * @param bool $enlarge Whether a smaller image may be scaled up to fill the box.
     * @return string The image filename, which is unchanged.
     * @throws dml_exception
     * @throws file_exception
     * @throws moodle_exception
     * @throws stored_file_creation_exception
     */
    public function resize_image($width, $height, $enlarge = true) {
        if (empty($this->width) || empty($this->height)) {
            throw new moodle_exception('invalidfiletype', 'error', '', $this->storedfile->get_filename());
        }

        [$newwidth, $newheight] = self::fit_dimensions($this->width, $this->height, (int) $width, (int) $height, $enlarge);
        if ($newwidth != $this->width || $newheight != $this->height) {
            $this->replace_content($this->encode_image($this->get_image_scaled($newwidth, $newheight)));
        }

        return $this->storedfile->get_filename();
    }

    /**
     * Rotate the image by a given angle.
     *
     * @param int $angle
     * @return string The image filename, which is unchanged.
     * @throws dml_exception
     * @throws file_exception
     * @throws moodle_exception
     * @throws stored_file_creation_exception
     */
    public function rotate_image($angle) {
        $this->replace_content($this->encode_image($this->get_image_rotated($angle)));
        return $this->storedfile->get_filename();
    }

    /**
     * Set the image caption in the database.
     *
     * @param string $caption
     * @return bool|int
     * @throws dml_exception
     */
    public function set_caption($caption) {
        global $DB;

        $imagemeta = new stdClass();
        $imagemeta->gallery = $this->cm->instance;
        $imagemeta->image = $this->storedfile->get_filename();
        $imagemeta->metatype = 'caption';
        $imagemeta->description = $caption;

        if (
            $meta = $DB->get_record('lightboxgallery_image_meta', ['gallery' => $this->cm->instance,
                'image' => $this->storedfile->get_filename(), 'metatype' => 'caption', ])
        ) {
            $imagemeta->id = $meta->id;
            return $DB->update_record('lightboxgallery_image_meta', $imagemeta);
        } else {
            return $DB->insert_record('lightboxgallery_image_meta', $imagemeta);
        }
    }

    /**
     * Set the stored file.
     *
     * @param stdClass $storedfile
     * @return void
     */
    public function set_stored_file($storedfile) {
        $this->storedfile = $storedfile;
        $imageinfo = $this->storedfile->get_imageinfo();

        $this->height = $imageinfo['height'];
        $this->width = $imageinfo['width'];
    }
}
