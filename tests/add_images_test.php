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
require_once($CFG->dirroot . '/mod/lightboxgallery/locallib.php');
require_once($CFG->dirroot . '/mod/lightboxgallery/imageclass.php');

/**
 * Tests for adding uploaded images and zips to a gallery.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_add_images')]
final class add_images_test extends \advanced_testcase {
    /** @var \stdClass The gallery record. */
    private $gallery;

    /** @var \stdClass */
    private $cm;

    /** @var \context_module */
    private $context;

    /** @var int The next draft item id to upload into. */
    private $draftitemid = 1000;

    /**
     * Create an empty gallery for each test.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        lightboxgallery_image::set_thumbnail_budget(lightboxgallery_image::SYNC_THUMBNAIL_LIMIT);

        $course = $this->getDataGenerator()->create_course();
        $gallery = $this->getDataGenerator()->create_module('lightboxgallery', ['course' => $course->id]);
        $this->gallery = $DB->get_record('lightboxgallery', ['id' => $gallery->id], '*', MUST_EXIST);
        $this->cm = get_coursemodule_from_instance('lightboxgallery', $gallery->id, $course->id, false, MUST_EXIST);
        $this->context = \context_module::instance($this->cm->id);
    }

    /**
     * Encode a blank PNG.
     *
     * @param int $width
     * @param int $height
     * @return string
     */
    private function png(int $width = 40, int $height = 20): string {
        ob_start();
        imagepng(imagecreatetruecolor($width, $height));
        return ob_get_clean();
    }

    /**
     * Put a file in the current user's draft area, as an upload would.
     *
     * @param string $filename
     * @param string $content
     * @return \stored_file
     */
    private function upload(string $filename, string $content): \stored_file {
        global $USER;
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $this->draftitemid++,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * Upload a zip holding the given files.
     *
     * @param string $filename
     * @param string[] $files Contents, keyed by path inside the zip.
     * @return \stored_file
     */
    private function upload_zip(string $filename, array $files): \stored_file {
        $path = make_request_directory() . '/' . $filename;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return $this->upload($filename, file_get_contents($path));
    }

    /**
     * The gallery's image filenames, sorted.
     *
     * @return string[]
     */
    private function gallery_filenames(): array {
        $names = [];
        foreach (get_file_storage()->get_area_files($this->context->id, 'mod_lightboxgallery', 'gallery_images', 0,
                'filename', false) as $file) {
            $names[] = $file->get_filename();
        }
        return $names;
    }

    /**
     * Add uploaded files to the gallery.
     *
     * @param \stored_file[] $files
     * @param int $resize A lightboxgallery_resize_options() key, or 0 for none.
     * @return void
     */
    private function add(array $files, int $resize = 0): void {
        lightboxgallery_add_images($files, $this->context, $this->cm, $this->gallery, $resize);
    }

    /**
     * An uploaded image is added with its filename as its caption and a thumbnail.
     */
    public function test_add_image(): void {
        global $DB;

        $this->add([$this->upload('bus.png', $this->png())]);

        $this->assertSame(['bus.png'], $this->gallery_filenames());
        $this->assertSame('bus.png', $DB->get_field('lightboxgallery_image_meta', 'description',
            ['gallery' => $this->gallery->id, 'image' => 'bus.png', 'metatype' => 'caption']));
        $this->assertNotFalse(get_file_storage()->get_file($this->context->id, 'mod_lightboxgallery', 'gallery_thumbs', 0,
            '/', 'bus.png.png'));
    }

    /**
     * A zip's images are added, including ones in folders, and anything else is ignored.
     */
    public function test_add_zip(): void {
        $zip = $this->upload_zip('photos.zip', [
            'one.png' => $this->png(),
            'more/two.png' => $this->png(),
            'notes.txt' => 'Not an image',
        ]);

        $this->add([$zip]);

        $this->assertSame(['one.png', 'two.png'], $this->gallery_filenames());
        $this->assertTrue(get_file_storage()->is_area_empty($this->context->id, 'mod_lightboxgallery', 'unpacktemp'));
    }

    /**
     * Images uploaded alongside a zip, or in several zips, are all added.
     */
    public function test_add_images_and_zips_together(): void {
        $this->add([
            $this->upload('single.png', $this->png()),
            $this->upload_zip('first.zip', ['one.png' => $this->png()]),
            $this->upload_zip('second.zip', ['two.png' => $this->png()]),
        ]);

        $this->assertSame(['one.png', 'single.png', 'two.png'], $this->gallery_filenames());
    }

    /**
     * An upload with the same name as an existing image leaves the existing one alone.
     */
    public function test_existing_image_is_kept(): void {
        $this->getDataGenerator()->get_plugin_generator('mod_lightboxgallery')->create_image($this->gallery, 'bus.png', 10, 10);

        $this->add([$this->upload('bus.png', $this->png(40, 20))]);

        $file = get_file_storage()->get_file($this->context->id, 'mod_lightboxgallery', 'gallery_images', 0, '/', 'bus.png');
        $this->assertSame(10, $file->get_imageinfo()['width']);
    }

    /**
     * Auto-resize shrinks large uploads to fit and leaves small ones alone.
     */
    public function test_auto_resize(): void {
        // Option 4 is 640x480.
        $this->add([$this->upload('large.png', $this->png(1280, 640)), $this->upload('small.png', $this->png(40, 20))], 4);

        $fs = get_file_storage();
        $large = $fs->get_file($this->context->id, 'mod_lightboxgallery', 'gallery_images', 0, '/', 'large.png');
        $small = $fs->get_file($this->context->id, 'mod_lightboxgallery', 'gallery_images', 0, '/', 'small.png');
        $this->assertSame(['width' => 640, 'height' => 320],
            array_intersect_key($large->get_imageinfo(), ['width' => 0, 'height' => 0]));
        $this->assertSame(['width' => 40, 'height' => 20],
            array_intersect_key($small->get_imageinfo(), ['width' => 0, 'height' => 0]));
    }
}
