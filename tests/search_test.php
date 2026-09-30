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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/lightboxgallery/locallib.php');

/**
 * Tests for searching gallery captions and tags.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_search_images')]
#[\PHPUnit\Framework\Attributes\CoversClass(event\gallery_searched::class)]
final class search_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /**
     * Create a course for each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Create a gallery.
     *
     * @return int The gallery id.
     */
    private function create_gallery(): int {
        return $this->getDataGenerator()->create_module('lightboxgallery', ['course' => $this->course->id])->id;
    }

    /**
     * Add a caption or tag to an image.
     *
     * @param int $galleryid
     * @param string $image
     * @param string $description
     * @param string $metatype caption or tag.
     * @return void
     */
    private function add_meta(int $galleryid, string $image, string $description, string $metatype = 'caption'): void {
        global $DB;
        $DB->insert_record('lightboxgallery_image_meta',
            ['gallery' => $galleryid, 'image' => $image, 'metatype' => $metatype, 'description' => $description]);
    }

    /**
     * Search and return just the matches, as "gallery/image" strings.
     *
     * @param int[] $galleryids
     * @param string $search
     * @param int $offset
     * @param int $limit
     * @return string[]
     */
    private function search(array $galleryids, string $search, int $offset = 0, int $limit = 50): array {
        [, $matches] = lightboxgallery_search_images($galleryids, $search, $offset, $limit);
        return array_map(fn($match) => $match->gallery . '/' . $match->image, $matches);
    }

    /**
     * The same filename in two galleries is two results; several matches on one image is one.
     */
    public function test_results_are_distinct_per_gallery_image(): void {
        $first = $this->create_gallery();
        $second = $this->create_gallery();
        $this->add_meta($first, 'IMG_0001.png', 'Beach day');
        $this->add_meta($first, 'IMG_0001.png', 'beach', 'tag');
        $this->add_meta($second, 'IMG_0001.png', 'At the beach');
        $this->add_meta($second, 'IMG_0002.png', 'Mountains');

        $this->assertSame(["$first/IMG_0001.png", "$second/IMG_0001.png"], $this->search([$first, $second], 'BEACH'));
        $this->assertSame(["$second/IMG_0001.png"], $this->search([$second], 'beach'));
    }

    /**
     * Wildcard characters in the search term are matched literally.
     */
    public function test_search_term_is_literal(): void {
        $gallery = $this->create_gallery();
        $this->add_meta($gallery, 'a.png', '50% off');
        $this->add_meta($gallery, 'b.png', '500 off');
        $this->add_meta($gallery, 'c.png', 'file_name');
        $this->add_meta($gallery, 'd.png', 'filexname');

        $this->assertSame(["$gallery/a.png"], $this->search([$gallery], '50%'));
        $this->assertSame(["$gallery/c.png"], $this->search([$gallery], 'e_n'));
    }

    /**
     * Results page in order, with the total counting each image once.
     */
    public function test_paging(): void {
        $gallery = $this->create_gallery();
        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $this->add_meta($gallery, "$name.png", 'Holiday');
            $this->add_meta($gallery, "$name.png", 'holiday', 'tag');
        }

        [$total, ] = lightboxgallery_search_images([$gallery], 'holiday', 0, 2);
        $this->assertSame(5, $total);
        $this->assertSame(["$gallery/a.png", "$gallery/b.png"], $this->search([$gallery], 'holiday', 0, 2));
        $this->assertSame(["$gallery/e.png"], $this->search([$gallery], 'holiday', 4, 2));
    }

    /**
     * Nothing is returned for a blank search or no galleries.
     */
    public function test_nothing_to_search(): void {
        $gallery = $this->create_gallery();
        $this->add_meta($gallery, 'a.png', 'Holiday');

        $this->assertSame([0, []], lightboxgallery_search_images([$gallery], '', 0, 50));
        $this->assertSame([0, []], lightboxgallery_search_images([], 'holiday', 0, 50));
    }

    /**
     * A search of every gallery in a course can be logged.
     */
    public function test_course_wide_search_event(): void {
        $event = event\gallery_searched::create([
            'context' => \context_course::instance($this->course->id),
            'other' => ['searchterm' => 'beach', 'lightboxgalleryid' => 0],
        ]);

        $this->assertStringContainsString("course with id '{$this->course->id}'", $event->get_description());
        $this->assertSame(\context_course::instance($this->course->id)->id, $event->contextid);
    }
}
