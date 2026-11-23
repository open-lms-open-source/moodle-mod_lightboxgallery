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
require_once($CFG->dirroot . '/mod/lightboxgallery/locallib.php');
require_once($CFG->dirroot . '/mod/lightboxgallery/imageadd_form.php');

/**
 * Tests for images the gallery can't decode: damaged files, and files too large to process.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(lightboxgallery_image::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_add_images')]
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_index_image_url')]
final class image_decode_test extends \advanced_testcase {
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
        $this->setAdminUser();
        lightboxgallery_image::set_thumbnail_budget(lightboxgallery_image::SYNC_THUMBNAIL_LIMIT);

        $course = $this->getDataGenerator()->create_course();
        $gallery = $this->getDataGenerator()->create_module('lightboxgallery', ['course' => $course->id]);
        $this->gallery = $DB->get_record('lightboxgallery', ['id' => $gallery->id], '*', MUST_EXIST);
        $this->cm = get_coursemodule_from_instance('lightboxgallery', $gallery->id, $course->id, false, MUST_EXIST);
        $this->context = \context_module::instance($this->cm->id);
    }

    /**
     * Reset the thumbnail budget so it can't leak into other tests.
     */
    protected function tearDown(): void {
        lightboxgallery_image::set_thumbnail_budget(lightboxgallery_image::SYNC_THUMBNAIL_LIMIT);
        parent::tearDown();
    }

    /**
     * Build a PNG whose header is valid but whose pixel data is garbage.
     *
     * The header is all getimagesize() reads, so the file passes is_valid_image() and
     * reports its dimensions, but GD can't decode it. A large size costs nothing here.
     *
     * @param int $width
     * @param int $height
     * @return string
     */
    private function damaged_png(int $width, int $height): string {
        $chunk = function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . $chunk('IDAT', str_repeat("\xff", 64))
            . $chunk('IEND', '');
    }

    /**
     * Encode a working PNG.
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
     * Store a file in the gallery's image area.
     *
     * @param string $filename
     * @param string $content
     * @return \stored_file
     */
    private function store(string $filename, string $content): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => $this->context->id,
            'component' => 'mod_lightboxgallery',
            'filearea' => 'gallery_images',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * Put a file in the current user's draft area, as an upload would.
     *
     * @param string $filename
     * @param string $content
     * @param int $itemid
     * @return \stored_file
     */
    private function upload(string $filename, string $content, int $itemid): \stored_file {
        global $USER;
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * Get an image's stored thumbnail.
     *
     * @param string $filename The image's filename.
     * @return \stored_file|false
     */
    private function get_thumbnail(string $filename) {
        return get_file_storage()->get_file(
            $this->context->id,
            'mod_lightboxgallery',
            'gallery_thumbs',
            0,
            '/',
            $filename . '.png'
        );
    }

    /**
     * Data provider for the unprocessable image tests.
     *
     * @return array
     */
    public static function unprocessable_provider(): array {
        return [
            'damaged' => [40, 20],
            'too large' => [30000, 30000],
        ];
    }

    /**
     * Showing an image that can't be decoded gives it a placeholder thumbnail instead of failing.
     *
     * Before the fix, the thumbnail was made with an unchecked imagecreatefromstring(), so the
     * gallery page threw on every load; a too-large image ran PHP out of memory.
     *
     * @param int $width
     * @param int $height
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unprocessable_provider')]
    public function test_unprocessable_image_gets_placeholder_thumbnail(int $width, int $height): void {
        $file = $this->store('broken.png', $this->damaged_png($width, $height));

        $image = new lightboxgallery_image($file, $this->gallery, $this->cm);
        $this->assertDebuggingCalled();

        $thumbnail = $this->get_thumbnail('broken.png');
        $this->assertNotFalse($thumbnail);
        $info = $thumbnail->get_imageinfo();
        $this->assertEquals(lightboxgallery_image::THUMBNAIL_WIDTH, $info['width']);
        $this->assertEquals(lightboxgallery_image::THUMBNAIL_HEIGHT, $info['height']);
        $this->assertNotNull($image->get_thumbnail_url());

        // The placeholder is kept, so the next request doesn't try to decode the image again.
        new lightboxgallery_image($file, $this->gallery, $this->cm);
        $this->assertDebuggingNotCalled();
        $this->assertEquals($thumbnail->get_id(), $this->get_thumbnail('broken.png')->get_id());
    }

    /**
     * A thumbnail left for the background task falls back to the placeholder in the same way.
     */
    public function test_ensure_thumbnail_on_damaged_image(): void {
        lightboxgallery_image::set_thumbnail_budget(0);
        $file = $this->store('broken.png', $this->damaged_png(40, 20));
        $image = new lightboxgallery_image($file, $this->gallery, $this->cm);
        $this->assertTrue($image->is_thumbnail_pending());

        $image->ensure_thumbnail();
        $this->assertDebuggingCalled();
        $this->assertFalse($image->is_thumbnail_pending());
        $this->assertNotFalse($this->get_thumbnail('broken.png'));
    }

    /**
     * Editing an image that can't be decoded fails with a clear exception and leaves the image alone.
     *
     * @param int $width
     * @param int $height
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unprocessable_provider')]
    public function test_edit_unprocessable_image(int $width, int $height): void {
        $content = $this->damaged_png($width, $height);
        $file = $this->store('broken.png', $content);
        $image = new lightboxgallery_image($file, $this->gallery, $this->cm);
        $this->assertDebuggingCalled();

        foreach (['rotate', 'flip', 'resize'] as $edit) {
            try {
                switch ($edit) {
                    case 'rotate':
                        $image->rotate_image(90);
                        break;
                    case 'flip':
                        $image->flip_image('horizontal');
                        break;
                    default:
                        $image->resize_image(10, 10);
                }
                $this->fail("$edit should have thrown");
            } catch (\moodle_exception $e) {
                $this->assertEquals('imagenotprocessed', $e->errorcode, $edit);
            }
        }

        $stored = get_file_storage()->get_file($this->context->id, 'mod_lightboxgallery', 'gallery_images', 0, '/', 'broken.png');
        $this->assertEquals(sha1($content), $stored->get_contenthash());
    }

    /**
     * Rotation only accepts the right angles the form offers.
     */
    public function test_rotate_rejects_other_angles(): void {
        $file = $this->store('photo.png', $this->png(40, 20));
        $image = new lightboxgallery_image($file, $this->gallery, $this->cm);
        $hash = $file->get_contenthash();

        try {
            $image->rotate_image(45);
            $this->fail('A 45 degree rotation should have thrown');
        } catch (\moodle_exception $e) {
            $this->assertEquals('invalidparameter', $e->errorcode);
        }
        $stored = get_file_storage()->get_file($this->context->id, 'mod_lightboxgallery', 'gallery_images', 0, '/', 'photo.png');
        $this->assertEquals($hash, $stored->get_contenthash());

        $image->rotate_image(-90);
        $info = get_file_storage()->get_file($this->context->id, 'mod_lightboxgallery', 'gallery_images', 0, '/', 'photo.png')
            ->get_imageinfo();
        $this->assertEquals([20, 40], [$info['width'], $info['height']]);
    }

    /**
     * The upload check reports damaged headers and oversized images, and passes normal ones.
     */
    public function test_get_processing_problem(): void {
        $this->assertNull(lightboxgallery_image::get_processing_problem($this->store('ok.png', $this->png())));

        $problem = lightboxgallery_image::get_processing_problem($this->store('huge.png', $this->damaged_png(30000, 30000)));
        $this->assertStringContainsString('huge.png', $problem);
        $this->assertStringContainsString('900', $problem);
    }

    /**
     * The upload form refuses an image too large to process.
     */
    public function test_upload_form_refuses_large_image(): void {
        global $PAGE;
        $PAGE->set_url('/mod/lightboxgallery/imageadd.php', ['id' => $this->cm->id]);
        $draftitemid = file_get_unused_draft_itemid();
        $this->upload('huge.png', $this->damaged_png(30000, 30000), $draftitemid);
        $this->upload('ok.png', $this->png(), $draftitemid);

        $form = new \mod_lightboxgallery_imageadd_form(null, ['id' => $this->cm->id, 'gallery' => $this->gallery]);
        $errors = $form->validation(['image' => $draftitemid, 'id' => $this->cm->id], []);

        $this->assertStringContainsString('huge.png', $errors['image']);
    }

    /**
     * Adding images skips ones too large to process, and ones that turn out to be damaged when resized.
     */
    public function test_add_images_skips_unprocessable(): void {
        $files = [
            $this->upload('huge.png', $this->damaged_png(30000, 30000), 1),
            $this->upload('damaged.png', $this->damaged_png(2000, 1500), 2),
            $this->upload('ok.png', $this->png(2000, 1500), 3),
        ];

        // Resize to 640x480, so every image that's added gets decoded.
        $problems = lightboxgallery_add_images($files, $this->context, $this->cm, $this->gallery, 4);
        $this->assertDebuggingCalled();

        $this->assertCount(2, $problems);
        $this->assertStringContainsString('huge.png', $problems[0]);
        $this->assertStringContainsString('damaged.png', $problems[1]);

        $fs = get_file_storage();
        $this->assertFalse($fs->get_file($this->context->id, 'mod_lightboxgallery', 'gallery_images', 0, '/', 'huge.png'));
        $this->assertFalse($fs->get_file($this->context->id, 'mod_lightboxgallery', 'gallery_images', 0, '/', 'damaged.png'));
        $this->assertFalse($this->get_thumbnail('damaged.png'));
        $ok = $fs->get_file($this->context->id, 'mod_lightboxgallery', 'gallery_images', 0, '/', 'ok.png');
        $this->assertEquals(640, $ok->get_imageinfo()['width']);
    }

    /**
     * A gallery whose first image is damaged still gets an index picture.
     */
    public function test_index_image_with_damaged_first_image(): void {
        $this->store('a-broken.png', $this->damaged_png(40, 20));

        $url = lightboxgallery_index_image_url($this->gallery->course, $this->gallery);
        // One for the image's thumbnail, one for the index picture.
        $this->assertdebuggingcalledcount(2);
        $this->assertInstanceOf(\moodle_url::class, $url);

        $index = get_file_storage()->get_file($this->context->id, 'mod_lightboxgallery', 'gallery_index', 0, '/', 'index.png');
        $this->assertNotFalse($index);
    }
}
