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

namespace mod_lightboxgallery;

use lightboxgallery_image;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/lightboxgallery/imageclass.php');

/**
 * Tests for the flip, rotate and resize edits on lightboxgallery_image.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(lightboxgallery_image::class)]
final class image_edit_test extends \advanced_testcase {
    /** @var \stdClass The gallery record. */
    private $gallery;

    /** @var \stdClass The gallery's course module. */
    private $cm;

    /** @var \context_module The gallery's context. */
    private $context;

    /**
     * Create an empty gallery for each test.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $gallery = $this->getDataGenerator()->create_module('lightboxgallery', ['course' => $course->id]);
        $this->gallery = $DB->get_record('lightboxgallery', ['id' => $gallery->id], '*', MUST_EXIST);
        $this->cm = get_coursemodule_from_instance('lightboxgallery', $gallery->id, $course->id, false, MUST_EXIST);
        $this->context = \context_module::instance($this->cm->id);
    }

    /**
     * Build a 40x20 image in the given format, with a transparent top-left pixel where the format allows.
     *
     * @param string $format png, gif or jpeg.
     * @return string The encoded image.
     */
    private function make_image_content(string $format): string {
        $image = imagecreatetruecolor(40, 20);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
        imagesetpixel($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));

        ob_start();
        switch ($format) {
            case 'png':
                imagepng($image);
                break;
            case 'gif':
                imagegif($image);
                break;
            default:
                imagejpeg($image);
        }
        return ob_get_clean();
    }

    /**
     * Add an image file to the gallery.
     *
     * @param string $filename
     * @param string $format png, gif or jpeg.
     * @return lightboxgallery_image
     */
    private function add_image(string $filename, string $format): lightboxgallery_image {
        $storedfile = get_file_storage()->create_file_from_string([
            'contextid' => $this->context->id,
            'component' => 'mod_lightboxgallery',
            'filearea' => 'gallery_images',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], $this->make_image_content($format));

        return new lightboxgallery_image($storedfile, $this->gallery, $this->cm);
    }

    /**
     * Fetch an image file from the gallery.
     *
     * @param string $filename
     * @return \stored_file|false
     */
    private function get_image_file(string $filename) {
        return get_file_storage()->get_file($this->context->id, 'mod_lightboxgallery', 'gallery_images', 0, '/', $filename);
    }

    /**
     * Data provider for test_edit_keeps_file_and_metadata.
     *
     * @return array
     */
    public static function edit_provider(): array {
        return [
            'rotate png' => ['png', 'rotate', 20, 40],
            'rotate gif' => ['gif', 'rotate', 20, 40],
            'rotate jpeg' => ['jpeg', 'rotate', 20, 40],
            'flip png' => ['png', 'flip', 40, 20],
            'flip gif' => ['gif', 'flip', 40, 20],
            'resize jpeg' => ['jpeg', 'resize', 20, 10],
        ];
    }

    /**
     * An edit changes the content but keeps the file, its name, its format and its metadata.
     *
     * Before the fix, "trip.day1.png" was renamed to "trip.png", which already exists here,
     * so the edit threw after the original had been deleted.
     *
     * @param string $format
     * @param string $edit
     * @param int $expectedwidth
     * @param int $expectedheight
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('edit_provider')]
    public function test_edit_keeps_file_and_metadata(string $format, string $edit, int $expectedwidth,
            int $expectedheight): void {
        global $DB;

        $filename = 'trip.day1.' . $format;
        $this->add_image('trip.' . $format, $format);
        $image = $this->add_image($filename, $format);
        $original = $this->get_image_file($filename);
        $image->set_caption('Day one');
        $tagid = $image->add_tag('beach');

        switch ($edit) {
            case 'rotate':
                $result = $image->rotate_image(90);
                break;
            case 'flip':
                $result = $image->flip_image('horizontal');
                break;
            case 'resize':
                $result = $image->resize_image(20, 10);
                break;
        }

        $this->assertSame($filename, $result);

        $edited = $this->get_image_file($filename);
        $this->assertNotFalse($edited);
        $this->assertEquals($original->get_id(), $edited->get_id());
        $this->assertNotEquals($original->get_contenthash(), $edited->get_contenthash());
        $this->assertSame($original->get_mimetype(), $edited->get_mimetype());

        $info = $edited->get_imageinfo();
        $this->assertEquals($expectedwidth, $info['width']);
        $this->assertEquals($expectedheight, $info['height']);
        $this->assertEquals($expectedwidth, $image->width);
        $this->assertEquals($expectedheight, $image->height);

        // The neighbouring file, which started with identical content, is untouched and still readable.
        $neighbour = $this->get_image_file('trip.' . $format);
        $this->assertNotFalse($neighbour);
        $this->assertEquals($original->get_contenthash(), $neighbour->get_contenthash());
        $this->assertNotEmpty($neighbour->get_content());

        // Captions and tags still belong to the image.
        $this->assertTrue($DB->record_exists('lightboxgallery_image_meta',
            ['gallery' => $this->gallery->id, 'image' => $filename, 'metatype' => 'caption']));
        $this->assertTrue($DB->record_exists('lightboxgallery_image_meta', ['id' => $tagid, 'image' => $filename]));

        // The thumbnail was regenerated, and no temporary files are left behind.
        $fs = get_file_storage();
        $this->assertNotFalse($fs->get_file($this->context->id, 'mod_lightboxgallery', 'gallery_thumbs', 0, '/',
            $filename . '.png'));
        $this->assertTrue($fs->is_area_empty($this->context->id, 'mod_lightboxgallery', 'edittemp', false));
    }

    /**
     * Data provider for test_flip_direction_and_transparency.
     *
     * @return array
     */
    public static function flip_provider(): array {
        // The transparent pixel starts in the top-left corner of the 40x20 image.
        return [
            'horizontal' => ['horizontal', 39, 0],
            'vertical' => ['vertical', 0, 19],
        ];
    }

    /**
     * Flipping mirrors the image the right way and keeps PNG transparency.
     *
     * @param string $direction
     * @param int $x Where the transparent pixel should end up.
     * @param int $y
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('flip_provider')]
    public function test_flip_direction_and_transparency(string $direction, int $x, int $y): void {
        $image = $this->add_image('mirror.png', 'png');

        $image->flip_image($direction);

        $gd = imagecreatefromstring($this->get_image_file('mirror.png')->get_content());
        $this->assertEquals(127, (imagecolorat($gd, $x, $y) >> 24) & 0x7F);
        $this->assertEquals(0, (imagecolorat($gd, 0, 0) >> 24) & 0x7F);
    }

    /**
     * Rotating a PNG keeps its transparency.
     */
    public function test_rotate_keeps_png_transparency(): void {
        $image = $this->add_image('clear.png', 'png');

        $image->rotate_image(90);

        // A 90 degree anticlockwise rotation moves the top-left pixel to the bottom-left.
        $gd = imagecreatefromstring($this->get_image_file('clear.png')->get_content());
        $alpha = (imagecolorat($gd, 0, imagesy($gd) - 1) >> 24) & 0x7F;
        $this->assertEquals(127, $alpha);
    }
}
