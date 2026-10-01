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
use mod_lightboxgallery\local\gallery_page;
use mod_lightboxgallery\task\generate_thumbnails;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/lightboxgallery/locallib.php');
require_once($CFG->dirroot . '/mod/lightboxgallery/imageclass.php');

/**
 * Tests that pages only generate a few thumbnails and leave the rest to a background task.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(lightboxgallery_image::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(generate_thumbnails::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_index_thumbnail')]
final class thumbnail_generation_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /** @var \stdClass The gallery record. */
    private $gallery;

    /** @var int */
    private $cmid;

    /** @var \context_module */
    private $context;

    /**
     * Create an empty gallery for each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        lightboxgallery_image::set_thumbnail_budget(lightboxgallery_image::SYNC_THUMBNAIL_LIMIT);
        $this->create_gallery();
    }

    /**
     * Create the test gallery.
     *
     * @param array $options Extra gallery settings.
     * @return void
     */
    private function create_gallery(array $options = []): void {
        global $DB;
        $this->course = $this->getDataGenerator()->create_course();
        $gallery = $this->getDataGenerator()->create_module(
            'lightboxgallery',
            array_merge(['course' => $this->course->id, 'extinfo' => 1], $options)
        );
        $this->gallery = $DB->get_record('lightboxgallery', ['id' => $gallery->id], '*', MUST_EXIST);
        $this->cmid = $gallery->cmid;
        $this->context = \context_module::instance($gallery->cmid);
    }

    /**
     * Store images in the gallery without making thumbnails, as a restore or a file-area edit would.
     *
     * @param int $count
     * @return void
     */
    private function add_images(int $count): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_lightboxgallery');
        for ($i = 1; $i <= $count; $i++) {
            $generator->create_image($this->gallery, sprintf('photo%02d.png', $i));
        }
    }

    /**
     * How many thumbnails the gallery has.
     *
     * @return int
     */
    private function count_thumbnails(): int {
        return count(get_file_storage()->get_area_files(
            $this->context->id,
            'mod_lightboxgallery',
            'gallery_thumbs',
            0,
            'filename',
            false
        ));
    }

    /**
     * Render the gallery page as a viewer would.
     *
     * @return string
     */
    private function render_gallery(): string {
        $cm = get_fast_modinfo($this->course)->get_cm($this->cmid);
        return (new gallery_page($cm, $this->gallery))->display_images();
    }

    /**
     * The queued thumbnail tasks.
     *
     * @return generate_thumbnails[]
     */
    private function queued_tasks(): array {
        return \core\task\manager::get_adhoc_tasks(generate_thumbnails::class);
    }

    /**
     * A few missing thumbnails are generated while the page renders, with nothing queued.
     */
    public function test_few_missing_thumbnails_are_generated_immediately(): void {
        $this->add_images(3);

        $html = $this->render_gallery();

        $this->assertSame(3, $this->count_thumbnails());
        $this->assertStringNotContainsString('lightbox-gallery-image-pending', $html);
        $this->assertEmpty($this->queued_tasks());
    }

    /**
     * Past the limit, the rest are shown as placeholders and one task is queued to make them.
     */
    public function test_many_missing_thumbnails_are_left_to_the_task(): void {
        $this->add_images(lightboxgallery_image::SYNC_THUMBNAIL_LIMIT + 5);

        $html = $this->render_gallery();

        $this->assertSame(lightboxgallery_image::SYNC_THUMBNAIL_LIMIT, $this->count_thumbnails());
        $this->assertSame(5, substr_count($html, 'lightbox-gallery-image-pending'));
        $tasks = $this->queued_tasks();
        $this->assertCount(1, $tasks);
        $this->assertEquals($this->cmid, reset($tasks)->get_custom_data()->cmid);

        // Viewing again before the task has run doesn't queue a second task.
        lightboxgallery_image::set_thumbnail_budget(0);
        $this->render_gallery();
        $this->assertCount(1, $this->queued_tasks());

        // The task makes every remaining thumbnail and the index image.
        ob_start();
        $this->runAdhocTasks(generate_thumbnails::class);
        ob_end_clean();
        $this->assertSame(lightboxgallery_image::SYNC_THUMBNAIL_LIMIT + 5, $this->count_thumbnails());
        $this->assertNotFalse(get_file_storage()->get_file(
            $this->context->id,
            'mod_lightboxgallery',
            'gallery_index',
            0,
            '/',
            'index.png'
        ));

        // With the budget spent, the page still shows every thumbnail.
        lightboxgallery_image::set_thumbnail_budget(0);
        $this->assertStringNotContainsString('lightbox-gallery-image-pending', $this->render_gallery());
    }

    /**
     * A pending thumbnail has no URL, and a single-image page can still generate it.
     */
    public function test_ensure_thumbnail_ignores_budget(): void {
        $this->add_images(1);
        lightboxgallery_image::set_thumbnail_budget(0);
        $file = get_file_storage()->get_file(
            $this->context->id,
            'mod_lightboxgallery',
            'gallery_images',
            0,
            '/',
            'photo01.png'
        );
        $cm = get_coursemodule_from_id('lightboxgallery', $this->cmid, 0, false, MUST_EXIST);

        $image = new lightboxgallery_image($file, $this->gallery, $cm);
        $this->assertTrue($image->is_thumbnail_pending());
        $this->assertNull($image->get_thumbnail_url());

        $image->ensure_thumbnail();
        $this->assertFalse($image->is_thumbnail_pending());
        $this->assertStringContainsString('/gallery_thumbs/0/photo01.png.png', $image->get_thumbnail_url()->out(false));
        $this->assertSame(1, $this->count_thumbnails());
    }

    /**
     * Thumbnails are made for galleries that don't show extended image info.
     */
    public function test_thumbnails_without_extended_info(): void {
        $this->create_gallery(['extinfo' => 0]);
        $this->add_images(2);

        $html = $this->render_gallery();

        $this->assertSame(2, $this->count_thumbnails());
        $this->assertStringContainsString('/gallery_thumbs/0/photo01.png.png', $html);
    }

    /**
     * The index image is made from the first image when there's budget left.
     */
    public function test_index_image_generated(): void {
        $this->add_images(1);

        $html = lightboxgallery_index_thumbnail($this->course->id, $this->gallery);

        $this->assertStringContainsString('/gallery_index/0/index.png', $html);
        $this->assertNotFalse(get_file_storage()->get_file(
            $this->context->id,
            'mod_lightboxgallery',
            'gallery_index',
            0,
            '/',
            'index.png'
        ));
    }

    /**
     * With the budget spent, the index page shows the default picture and queues the task.
     */
    public function test_index_image_over_budget(): void {
        $this->add_images(1);
        lightboxgallery_image::set_thumbnail_budget(0);

        $html = lightboxgallery_index_thumbnail($this->course->id, $this->gallery);

        $this->assertStringNotContainsString('/gallery_index/', $html);
        $this->assertFalse(get_file_storage()->get_file(
            $this->context->id,
            'mod_lightboxgallery',
            'gallery_index',
            0,
            '/',
            'index.png'
        ));
        $this->assertCount(1, $this->queued_tasks());
    }

    /**
     * A gallery whose image area holds only folders gets the default index picture instead of an error.
     */
    public function test_index_image_with_no_images(): void {
        get_file_storage()->create_directory($this->context->id, 'mod_lightboxgallery', 'gallery_images', 0, '/sub/');

        $html = lightboxgallery_index_thumbnail($this->course->id, $this->gallery);

        $this->assertStringContainsString('/gallery_index/0/index.png', $html);
    }

    /**
     * The task does nothing for a gallery that has since been deleted.
     */
    public function test_task_for_deleted_gallery(): void {
        $task = new generate_thumbnails();
        $task->set_custom_data(['cmid' => $this->cmid]);
        course_delete_module($this->cmid);

        $this->expectOutputRegex('/no longer exists/');
        $task->execute();
    }
}
