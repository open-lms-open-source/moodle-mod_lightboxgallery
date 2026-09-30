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
require_once($CFG->dirroot . '/mod/lightboxgallery/edit/base.class.php');
require_once($CFG->dirroot . '/mod/lightboxgallery/edit/resize/resize.class.php');

/**
 * Tests for resizing gallery images.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(lightboxgallery_image::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\edit_resize::class)]
final class image_resize_test extends \advanced_testcase {
    /** @var \stdClass The gallery record. */
    private $gallery;

    /** @var \stdClass The gallery's course module. */
    private $cm;

    /** @var \context_module */
    private $context;

    /**
     * Create an empty gallery for each test.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        lightboxgallery_image::set_thumbnail_budget(lightboxgallery_image::SYNC_THUMBNAIL_LIMIT);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $gallery = $this->getDataGenerator()->create_module('lightboxgallery', ['course' => $course->id]);
        $this->gallery = $DB->get_record('lightboxgallery', ['id' => $gallery->id], '*', MUST_EXIST);
        $this->cm = get_coursemodule_from_instance('lightboxgallery', $gallery->id, $course->id, false, MUST_EXIST);
        $this->context = \context_module::instance($this->cm->id);
    }

    /**
     * Clear any form submission a test set up.
     */
    protected function tearDown(): void {
        $_POST = [];
        parent::tearDown();
    }

    /**
     * Add a PNG of the given size to the gallery.
     *
     * @param int $width
     * @param int $height
     * @return lightboxgallery_image
     */
    private function add_image(int $width, int $height): lightboxgallery_image {
        $storedfile = $this->getDataGenerator()->get_plugin_generator('mod_lightboxgallery')->create_image($this->gallery, 'photo.png', $width, $height);
        return new lightboxgallery_image($storedfile, $this->gallery, $this->cm);
    }

    /**
     * The stored photo's current size.
     *
     * @return int[] [width, height]
     */
    private function stored_size(): array {
        $file = get_file_storage()->get_file($this->context->id, 'mod_lightboxgallery', 'gallery_images', 0, '/', 'photo.png');
        $info = $file->get_imageinfo();
        return [$info['width'], $info['height']];
    }

    /**
     * Data provider for test_fit_dimensions.
     *
     * @return array
     */
    public static function fit_dimensions_provider(): array {
        return [
            'same aspect, shrink' => [4000, 3000, 1024, 768, true, [1024, 768]],
            'wide photo keeps its aspect' => [1600, 900, 1024, 768, true, [1024, 576]],
            'tall photo keeps its aspect' => [900, 1600, 1024, 768, true, [432, 768]],
            'small photo, no enlarging' => [640, 480, 1280, 1024, false, [640, 480]],
            'small photo, enlarging' => [640, 480, 1280, 1024, true, [1280, 960]],
            'capped at the maximum size' => [3000, 2000, 6000, 4000, true, [4096, 2731]],
            'never below one pixel' => [4000, 1, 100, 100, true, [100, 1]],
        ];
    }

    /**
     * Sizes fit inside the box, keep their aspect ratio and respect the maximum.
     *
     * @param int $width
     * @param int $height
     * @param int $maxwidth
     * @param int $maxheight
     * @param bool $enlarge
     * @param array $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('fit_dimensions_provider')]
    public function test_fit_dimensions(int $width, int $height, int $maxwidth, int $maxheight, bool $enlarge,
            array $expected): void {
        $this->assertSame($expected, lightboxgallery_image::fit_dimensions($width, $height, $maxwidth, $maxheight, $enlarge));
    }

    /**
     * Resizing to a box of a different shape scales the whole image instead of cropping it.
     */
    public function test_resize_fits_instead_of_cropping(): void {
        $image = $this->add_image(40, 20);

        $image->resize_image(20, 20);

        $this->assertSame([20, 10], $this->stored_size());
    }

    /**
     * An upload-style resize leaves a smaller image untouched.
     */
    public function test_resize_without_enlarging_leaves_small_image(): void {
        $image = $this->add_image(40, 20);
        $before = get_file_storage()->get_file($this->context->id, 'mod_lightboxgallery', 'gallery_images', 0, '/',
            'photo.png')->get_contenthash();

        $image->resize_image(1280, 1024, false);

        $after = get_file_storage()->get_file($this->context->id, 'mod_lightboxgallery', 'gallery_images', 0, '/',
            'photo.png')->get_contenthash();
        $this->assertSame($before, $after);
    }

    /**
     * Scaling up repeatedly stops at the maximum size.
     */
    public function test_scaling_up_is_capped(): void {
        $image = $this->add_image(3000, 1500);

        $image->resize_image(6000, 3000);
        $this->assertSame([4096, 2048], $this->stored_size());

        $image->resize_image(8192, 4096);
        $this->assertSame([4096, 2048], $this->stored_size());
    }

    /**
     * Run the resize tool's form handler with the given submission.
     *
     * @param array $post
     * @return void
     */
    private function submit_resize_form(array $post): void {
        $_POST = $post;
        $tool = new \edit_resize($this->gallery, $this->cm, 'photo.png', 'resize');
        $tool->process_form();
    }

    /**
     * The resize tool applies a listed scale.
     */
    public function test_form_applies_scale(): void {
        $this->add_image(40, 20);

        $this->submit_resize_form(['button' => get_string('edit_resizescale', 'lightboxgallery'), 'scale' => 50]);

        $this->assertSame([20, 10], $this->stored_size());
    }

    /**
     * Data provider for test_form_rejects_bad_input.
     *
     * @return array
     */
    public static function bad_form_provider(): array {
        return [
            'unlisted scale' => [['button' => 'scale', 'scale' => 1000]],
            'unknown size' => [['button' => 'resize', 'size' => 99]],
            'unknown button' => [['button' => 'Something else']],
        ];
    }

    /**
     * The resize tool refuses values it didn't offer, and leaves the image alone.
     *
     * @param array $post The submission; 'scale' and 'resize' buttons are replaced with the real labels.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_form_provider')]
    public function test_form_rejects_bad_input(array $post): void {
        $this->add_image(40, 20);
        $labels = [
            'scale' => get_string('edit_resizescale', 'lightboxgallery'),
            'resize' => get_string('edit_resize', 'lightboxgallery'),
        ];
        $post['button'] = $labels[$post['button']] ?? $post['button'];

        try {
            $this->submit_resize_form($post);
            $this->fail('Expected the submission to be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidparameter', $e->errorcode);
        }
        $this->assertSame([40, 20], $this->stored_size());
    }
}
