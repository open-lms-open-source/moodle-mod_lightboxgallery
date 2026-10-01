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
use mod_lightboxgallery\output\gallery_comment;
use mod_lightboxgallery\output\image_tile;
use mod_lightboxgallery\output\popular_tags;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/lightboxgallery/locallib.php');
require_once($CFG->dirroot . '/mod/lightboxgallery/imageclass.php');

/**
 * Tests for the gallery page's output: image tiles, comments and tags.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(image_tile::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(gallery_comment::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(popular_tags::class)]
final class output_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /** @var \stdClass The gallery record. */
    private $gallery;

    /** @var \stdClass */
    private $cm;

    /**
     * Create a gallery with one image.
     */
    protected function setUp(): void {
        global $DB, $PAGE;
        parent::setUp();
        $this->resetAfterTest();
        lightboxgallery_image::set_thumbnail_budget(lightboxgallery_image::SYNC_THUMBNAIL_LIMIT);
        $PAGE->set_url('/mod/lightboxgallery/view.php');

        $this->course = $this->getDataGenerator()->create_course();
        $gallery = $this->getDataGenerator()->create_module('lightboxgallery', ['course' => $this->course->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_lightboxgallery')->create_image($gallery, 'bus.png');
        $this->gallery = $DB->get_record('lightboxgallery', ['id' => $gallery->id], '*', MUST_EXIST);
        $this->cm = get_coursemodule_from_instance('lightboxgallery', $gallery->id, $this->course->id, false, MUST_EXIST);
    }

    /**
     * Load the gallery's image.
     *
     * @return lightboxgallery_image
     */
    private function get_image(): lightboxgallery_image {
        $file = get_file_storage()->get_file(
            \context_module::instance($this->cm->id)->id,
            'mod_lightboxgallery',
            'gallery_images',
            0,
            '/',
            'bus.png'
        );
        return new lightboxgallery_image($file, $this->gallery, $this->cm);
    }

    /**
     * Parse rendered HTML for querying.
     *
     * @param string $html
     * @return \DOMXPath
     */
    private function parse(string $html): \DOMXPath {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        return new \DOMXPath($doc);
    }

    /**
     * A tile links to the image, and is named by its caption or, without one, its filename.
     */
    public function test_tile_link(): void {
        $image = $this->get_image();
        $xpath = $this->parse($image->get_image_display_html());
        $link = $xpath->query('//a[contains(@class, "lightbox-gallery-image-thumbnail")]')->item(0);
        $this->assertSame('lightbox_gallery', $link->getAttribute('rel'));
        $this->assertStringContainsString('/gallery_images/0/bus.png', $link->getAttribute('href'));
        $this->assertSame('bus.png', trim($link->textContent));

        // The tile shortens a long caption, but the viewer and screen readers get all of it.
        $image->set_caption('The <school> bus to the beach');
        $xpath = $this->parse($this->get_image()->get_image_display_html());
        $link = $xpath->query('//a[contains(@class, "lightbox-gallery-image-thumbnail")]')->item(0);
        $this->assertSame('The <school> bus to the beach', $link->getAttribute('title'));
        $this->assertSame('The <school> bus to the beach', trim($link->textContent));
        $this->assertSame(
            'The <school> ...',
            trim($xpath->query('//div[contains(@class, "lightbox-gallery-image-caption")]')->item(0)->textContent)
        );
        $this->assertSame(0, $xpath->query('//school')->length);
    }

    /**
     * A hidden caption isn't shown or used as the link's title.
     */
    public function test_tile_hidden_caption(): void {
        $this->get_image()->set_caption('Secret caption');
        $this->gallery->captionpos = LIGHTBOXGALLERY_POS_HID;

        $html = $this->get_image()->get_image_display_html();

        $this->assertStringNotContainsString('Secret caption', $html);
    }

    /**
     * The edit menu offers only the enabled tools, and returns to the page being shown.
     */
    public function test_tile_edit_menu(): void {
        set_config('disabledplugins', 'flip,resize', 'lightboxgallery');

        $xpath = $this->parse($this->get_image()->get_image_display_html(true, 3));

        $options = [];
        foreach ($xpath->query('//select[@name="tab"]/option[@value!=""]') as $option) {
            $options[] = $option->getAttribute('value');
        }
        $this->assertSame(['caption', 'delete', 'rotate', 'tag', 'thumbnail'], $options);
        $this->assertSame('3', $xpath->query('//input[@name="page"]')->item(0)->getAttribute('value'));
        $this->assertSame((string) $this->cm->id, $xpath->query('//input[@name="id"]')->item(0)->getAttribute('value'));
        $select = $xpath->query('//select[@name="tab"]')->item(0);
        $this->assertSame(1, $xpath->query('//label[@for="' . $select->getAttribute('id') . '"]')->length);
    }

    /**
     * Without editing, there's no edit menu.
     */
    public function test_tile_without_editing(): void {
        $xpath = $this->parse($this->get_image()->get_image_display_html(false));
        $this->assertSame(0, $xpath->query('//form')->length);
    }

    /**
     * A comment shows its author, escaped, and only editors get a delete link.
     */
    public function test_comment(): void {
        global $DB;
        $commenter = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        // Names are cleaned when saved, so give the displayed author markup directly.
        $commenter->firstname = 'Sam <b>';
        $comment = (object) ['gallery' => $this->gallery->id, 'userid' => $commenter->id,
            'commenttext' => '<p>Great photos</p>', 'timemodified' => time()];
        $comment->id = $DB->insert_record('lightboxgallery_comments', $comment);
        $context = \context_module::instance($this->cm->id);

        $this->setUser($commenter);
        ob_start();
        lightboxgallery_print_comment($comment, $context, $commenter);
        $xpath = $this->parse(ob_get_clean());
        $this->assertSame(1, $xpath->query('//*[@id="c' . $comment->id . '"]')->length);
        $author = $xpath->query('//div[contains(@class, "lightboxgallery-comment-author")]/a')->item(0);
        $this->assertStringContainsString('Sam <b>', $author->textContent);
        $this->assertSame(0, $xpath->query('//b')->length);
        $this->assertStringContainsString('Great photos', $xpath->query('//p')->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//a[contains(@href, "delete=")]')->length);

        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher'));
        ob_start();
        lightboxgallery_print_comment($comment, $context, $commenter);
        $xpath = $this->parse(ob_get_clean());
        $this->assertSame(1, $xpath->query('//a[contains(@href, "delete=' . $comment->id . '")]')->length);
    }

    /**
     * Each tag links to a search for it, beside a labelled search box.
     */
    public function test_popular_tags(): void {
        $tags = [(object) ['description' => 'beach'], (object) ['description' => 'bus & coach']];

        ob_start();
        lightboxgallery_print_tags('Popular tags', $tags, $this->course->id, $this->gallery->id);
        $xpath = $this->parse(ob_get_clean());

        $links = $xpath->query('//a[@class="taglink"]');
        $this->assertSame(2, $links->length);
        $this->assertSame('bus & coach', $links->item(1)->textContent);
        $this->assertStringContainsString('search=bus%20%26%20coach', $links->item(1)->getAttribute('href'));
        $input = $xpath->query('//input[@name="search"]')->item(0);
        $this->assertSame(1, $xpath->query('//label[@for="' . $input->getAttribute('id') . '"]')->length);
        $this->assertSame((string) $this->gallery->id, $xpath->query('//input[@name="gallery"]')->item(0)->getAttribute('value'));
    }
}
