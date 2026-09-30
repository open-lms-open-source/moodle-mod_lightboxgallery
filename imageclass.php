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
define('THUMBNAIL_WIDTH', 162);
/**
 *
 */
define('THUMBNAIL_HEIGHT', 132);
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

        $this->thumbnail = $thumbnail;

        // If we weren't given a thumbnail, double check if it exists before generating one.
        if (!$thumbnail && (!$this->thumbnail = $this->get_thumbnail())) {
            $this->thumbnail = $this->create_thumbnail();
        }
        if ($this->thumbnail) {
            $this->thumburl->param('mtime', $this->thumbnail->get_timemodified());
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
        imagepng($this->get_image_resized(THUMBNAIL_HEIGHT, THUMBNAIL_WIDTH, $offsetx, $offsety));
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
     * Get the form for the editing options.
     *
     * @return string
     * @throws coding_exception
     */
    private function get_editing_options_form() {
        global $CFG;

        $options = $this->get_editing_options();

        $customselect = 'custom-select';
        if ($CFG->version >= 2024041400) {
            $customselect = 'form-select ms-1';
        }
        $html = '<form action="' . $CFG->wwwroot . '/mod/lightboxgallery/imageedit.php" method="post"/>' .
                    '<input type="hidden" name="id" value="' . $this->cmid . '" />' .
                    '<input type="hidden" name="image" value="' . $this->storedfile->get_filename() . '" />' .
                    '<input type="hidden" name="page" value="0" />' .
                    '<select name="tab" class="lightbox-edit-select ' . $customselect . ' mb-1" style="width: ' . THUMBNAIL_WIDTH .
                    'px;" ' . 'onchange="submit();">' .
                    '<option disabled selected>' . get_string('edit_choose', 'lightboxgallery') . '</option>';
        foreach ($options as $option) {
            $html .= '<option value="' . $option . '">' . get_string('edit_' . $option, 'lightboxgallery') . '</option>';
        }
        $html .= '</select>' .
                '</form>';

        return $html;
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
     * Get the image display HTML.
     *
     * @param bool $editing
     * @return string
     * @throws coding_exception
     * @throws dml_exception
     */
    public function get_image_display_html($editing = false) {
        if ($this->gallery->captionfull) {
            $caption = $this->get_image_caption();
        } else {
            $caption = lightboxgallery_resize_text($this->get_image_caption(), MAX_IMAGE_LABEL);
        }
        $timemodified = userdate($this->storedfile->get_timemodified(), get_string('strftimedatetimeshort', 'langconfig'));
        $filesize = round($this->storedfile->get_filesize() / 100) / 10;

        // Hide the caption.
        if ($this->gallery->captionpos == LIGHTBOXGALLERY_POS_HID) {
            $caption = ''; // Hide by cleaning the content (looks better than cleaning the whole div).
        }
        $posclass = ($this->gallery->captionpos == LIGHTBOXGALLERY_POS_TOP) ? 'top' : 'bottom';
        $captiondiv = html_writer::tag('div', s($caption), ['class' => "lightbox-gallery-image-caption $posclass"]);

        $html = '<div class="lightbox-gallery-image-container">' .
                    '<div class="lightbox-gallery-image-wrapper">' .
                        '<div class="lightbox-gallery-image-frame">';
        if ($this->gallery->captionpos == LIGHTBOXGALLERY_POS_TOP) {
            $html .= $captiondiv;
        }
        $html .= '<a class="lightbox-gallery-image-thumbnail" href="' .
                 $this->imageurl . '" rel="lightbox_gallery" title="' . s($caption) .
                 '" style="background-image: url(\'' . $this->thumburl .
                 '\'); width: ' . THUMBNAIL_WIDTH . 'px; height: ' . THUMBNAIL_HEIGHT . 'px;"></a>';
        if ($this->gallery->captionpos == LIGHTBOXGALLERY_POS_BOT || $this->gallery->captionpos == LIGHTBOXGALLERY_POS_HID) {
            $html .= $captiondiv;
        }
        $html .= $this->gallery->extinfo ? '<div class="lightbox-gallery-image-extinfo">' . $timemodified .
                 '<br/>' . $filesize . 'KB ' . $this->width . 'x' . $this->height . 'px</div>' : '';
        $html .= ($editing ? $this->get_editing_options_form() : '');
        $html .= '</div>' .
                    '</div>' .
                '</div>';

        return $html;
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
    private function get_image_resized($height = THUMBNAIL_HEIGHT, $width = THUMBNAIL_WIDTH, $offsetx = 0, $offsety = 0) {
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
     * @return \core\url
     */
    public function get_thumbnail_url() {
        return $this->thumburl;
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
     * Resize the image to a given width and height.
     *
     * @param int $width
     * @param int $height
     * @return string The image filename, which is unchanged.
     * @throws dml_exception
     * @throws file_exception
     * @throws moodle_exception
     * @throws stored_file_creation_exception
     */
    public function resize_image($width, $height) {
        $this->replace_content($this->encode_image($this->get_image_resized($height, $width)));
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
     * Update the image meta file name in the database.
     *
     * @param stdClass $old
     * @param stdClass $new
     * @return void
     * @throws dml_exception
     */
    public function update_meta_file($old, $new) {
        global $DB;

        if ($old == $new) {
            return;
        }

        $sql = 'UPDATE {lightboxgallery_image_meta} SET image = ?
                WHERE image = ? AND gallery = ?';
        $DB->execute($sql, [$new, $old, $this->gallery->id]);
    }

    /**
     * Copy the content of the stored file to a temporary location.
     *
     * @return bool|string
     */
    public function copy_content_to_temp() {
        return $this->storedfile->copy_content_to_temp();
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
