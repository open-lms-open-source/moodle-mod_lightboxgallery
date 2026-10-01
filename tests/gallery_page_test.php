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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/lightboxgallery/locallib.php');
require_once($CFG->dirroot . '/mod/lightboxgallery/imageclass.php');

/**
 * Tests for how a page of the gallery is chosen and ordered, and for deleting a gallery.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(gallery_page::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_delete_instance')]
final class gallery_page_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /**
     * Reset the thumbnail budget for each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        lightboxgallery_image::set_thumbnail_budget(lightboxgallery_image::SYNC_THUMBNAIL_LIMIT);
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Create a gallery holding the given images, with optional captions.
     *
     * @param array $settings Gallery settings.
     * @param string[] $filenames
     * @param string[] $captions Keyed by filename.
     * @return \stdClass The gallery record, with its cmid.
     */
    private function create_gallery(array $settings, array $filenames, array $captions = []): \stdClass {
        global $DB;
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_lightboxgallery');
        $instance = $generator->create_instance(['course' => $this->course->id] + $settings);
        foreach ($filenames as $filename) {
            $generator->create_image($instance, $filename);
        }
        foreach ($captions as $filename => $caption) {
            $DB->insert_record('lightboxgallery_image_meta',
                ['gallery' => $instance->id, 'image' => $filename, 'metatype' => 'caption', 'description' => $caption]);
        }
        $gallery = $DB->get_record('lightboxgallery', ['id' => $instance->id], '*', MUST_EXIST);
        $gallery->cmid = $instance->cmid;
        return $gallery;
    }

    /**
     * The filenames shown on a page of the gallery, in order.
     *
     * @param \stdClass $gallery
     * @param int $page
     * @return array [string[] $filenames, int $imagecount]
     */
    private function show_page(\stdClass $gallery, int $page = 0): array {
        $cm = get_fast_modinfo($this->course)->get_cm($gallery->cmid);
        $gallerypage = new gallery_page($cm, $gallery, false, $page);
        preg_match_all('#/gallery_images/0/([^?"]+)\?#', $gallerypage->display_images(), $matches);
        return [array_map('rawurldecode', $matches[1]), $gallerypage->image_count()];
    }

    /**
     * Data provider for test_sorting.
     *
     * @return array
     */
    public static function sorting_provider(): array {
        $files = ['img10.png', 'img2.png', 'b.png', 'a.png'];
        // Captions sort the images; one without a caption sorts by its filename.
        $captions = ['img10.png' => 'Zebra', 'img2.png' => 'Apple', 'b.png' => 'Mango'];
        return [
            'by filename' => [gallery_page::SORTBY_FILENAME, $files, $captions,
                ['a.png', 'b.png', 'img10.png', 'img2.png']],
            'by filename, naturally' => [gallery_page::SORTBY_FILENAME_NATURAL, $files, $captions,
                ['a.png', 'b.png', 'img2.png', 'img10.png']],
            'by caption' => [gallery_page::SORTBY_CAPTION, $files, $captions,
                ['img2.png', 'b.png', 'img10.png', 'a.png']],
        ];
    }

    /**
     * Each sort setting orders the images as expected.
     *
     * @param int $sortby
     * @param string[] $files
     * @param string[] $captions
     * @param string[] $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sorting_provider')]
    public function test_sorting(int $sortby, array $files, array $captions, array $expected): void {
        $gallery = $this->create_gallery(['sortby' => $sortby], $files, $captions);

        [$shown] = $this->show_page($gallery);

        $this->assertSame($expected, $shown);
    }

    /**
     * Pages show their share of the images, and the count covers the whole gallery.
     */
    public function test_pagination(): void {
        $gallery = $this->create_gallery(['perpage' => 2], ['a.png', 'b.png', 'c.png', 'd.png', 'e.png']);

        $this->assertSame([['a.png', 'b.png'], 5], $this->show_page($gallery, 0));
        $this->assertSame([['c.png', 'd.png'], 5], $this->show_page($gallery, 1));
        $this->assertSame([['e.png'], 5], $this->show_page($gallery, 2));
        $this->assertSame([[], 5], $this->show_page($gallery, 3));
    }

    /**
     * Only the current page's captions and tags are loaded, with one query whatever the gallery's size.
     */
    public function test_metadata_loaded_for_page_only(): void {
        global $DB;
        $files = ['a.png', 'b.png', 'c.png', 'd.png', 'e.png'];
        $captions = array_combine($files, ['Ant', 'Bee', 'Cat', 'Dog', 'Eel']);
        $gallery = $this->create_gallery(['perpage' => 2], $files, $captions);
        $cm = get_fast_modinfo($this->course)->get_cm($gallery->cmid);
        $metadata = new \ReflectionProperty(gallery_page::class, 'metadata');

        $reads = $DB->perf_get_reads();
        $page = new gallery_page($cm, $gallery, false, 1);
        // The image and thumbnail lists, then this page's captions and tags.
        $this->assertSame(3, $DB->perf_get_reads() - $reads);
        $this->assertSame(['c.png', 'd.png'], array_keys($metadata->getValue($page)));
        $this->assertSame('Cat', $metadata->getValue($page)['c.png'][0]->description);

        // Showing every image loads the gallery's metadata by gallery, not by listing each image.
        $DB->set_field('lightboxgallery', 'perpage', 0, ['id' => $gallery->id]);
        $gallery->perpage = 0;
        $page = new gallery_page($cm, $gallery, false, 0);
        $this->assertSame($files, array_keys($metadata->getValue($page)));
    }

    /**
     * Files that aren't images aren't shown or counted.
     */
    public function test_non_images_are_left_out(): void {
        $gallery = $this->create_gallery([], ['a.png']);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($gallery->cmid)->id,
            'component' => 'mod_lightboxgallery',
            'filearea' => 'gallery_images',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'notes.txt',
        ], 'Not an image');

        $this->assertSame([['a.png'], 1], $this->show_page($gallery));
    }

    /**
     * Deleting a gallery removes its files, comments, captions and tags.
     */
    public function test_delete_instance(): void {
        global $DB;
        $gallery = $this->create_gallery([], ['a.png'], ['a.png' => 'A caption']);
        $this->show_page($gallery);
        $DB->insert_record('lightboxgallery_comments',
            ['gallery' => $gallery->id, 'userid' => 2, 'commenttext' => 'Nice', 'timemodified' => time()]);
        $contextid = \context_module::instance($gallery->cmid)->id;

        $this->assertTrue(lightboxgallery_delete_instance($gallery->id));

        $this->assertFalse($DB->record_exists('lightboxgallery', ['id' => $gallery->id]));
        $this->assertFalse($DB->record_exists('lightboxgallery_comments', ['gallery' => $gallery->id]));
        $this->assertFalse($DB->record_exists('lightboxgallery_image_meta', ['gallery' => $gallery->id]));
        $this->assertFalse($DB->record_exists('files', ['contextid' => $contextid, 'component' => 'mod_lightboxgallery']));
    }
}
