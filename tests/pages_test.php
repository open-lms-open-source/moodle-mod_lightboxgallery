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
 * Tests for errors, page URLs and icons on the plugin's pages.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_print_recent_mod_activity')]
final class pages_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /** @var \stdClass The gallery, with its cmid. */
    private $gallery;

    /**
     * Create a gallery, and log in as its teacher.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        lightboxgallery_image::set_thumbnail_budget(lightboxgallery_image::SYNC_THUMBNAIL_LIMIT);

        $this->course = $this->getDataGenerator()->create_course();
        $this->gallery = $this->getDataGenerator()->create_module(
            'lightboxgallery',
            ['course' => $this->course->id, 'comments' => 0]
        );
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher'));
    }

    /**
     * Load one of the plugin's pages with the given request parameters.
     *
     * @param string $script
     * @param array $params
     * @return string The page output.
     */
    private function load_page(string $script, array $params): string {
        global $CFG, $DB, $OUTPUT, $PAGE, $USER;
        $_GET = $params;
        ob_start();
        try {
            include($CFG->dirroot . '/mod/lightboxgallery/' . $script);
        } finally {
            $html = ob_get_clean();
            $_GET = [];
        }
        return $html;
    }

    /**
     * Adding a comment to a gallery without comments explains why, on the comment page.
     */
    public function test_comment_page_when_comments_are_off(): void {
        global $PAGE;

        try {
            $this->load_page('comment.php', ['id' => $this->gallery->id]);
            $this->fail('Expected an exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorcommentsdisabled', $e->errorcode);
        }
        $this->assertStringEndsWith('/mod/lightboxgallery/comment.php', $PAGE->url->get_path(false));
    }

    /**
     * Deleting a comment that doesn't exist reports a missing record.
     */
    public function test_comment_page_with_unknown_comment(): void {
        $this->expectException(\dml_missing_record_exception::class);
        $this->load_page('comment.php', ['id' => $this->gallery->id, 'delete' => 999999]);
    }

    /**
     * The add images page uses its own URL.
     */
    public function test_imageadd_page_url(): void {
        global $PAGE;

        $html = $this->load_page('imageadd.php', ['id' => $this->gallery->cmid]);

        $this->assertStringEndsWith('/mod/lightboxgallery/imageadd.php', $PAGE->url->get_path(false));
        $this->assertMatchesRegularExpression('/name="id" type="hidden" value="' . $this->gallery->cmid . '"|' .
            'type="hidden" name="id" value="' . $this->gallery->cmid . '"/', $html);
    }

    /**
     * Editing an image that doesn't exist names the missing file.
     */
    public function test_imageedit_missing_image(): void {
        try {
            $this->load_page('imageedit.php', ['id' => $this->gallery->cmid, 'image' => 'missing.png']);
            $this->fail('Expected an exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errornofile', $e->errorcode);
            $this->assertStringContainsString('missing.png', $e->getMessage());
        }
    }

    /**
     * The search form's controls are labelled, offer every gallery, and keep what was searched for.
     */
    public function test_search_form(): void {
        global $DB;
        $second = $this->getDataGenerator()->create_module('lightboxgallery', ['course' => $this->course->id, 'name' => 'Zoo']);
        // A match, so the page runs to its end rather than stopping at "no results".
        $DB->insert_record(
            'lightboxgallery_image_meta',
            ['gallery' => $second->id, 'image' => 'a.png', 'metatype' => 'caption', 'description' => 'The bus & coach']
        );

        $html = $this->load_page('search.php', ['id' => $this->course->id, 'gallery' => $second->id, 'search' => 'bus & co']);

        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($doc);
        $form = $xpath->query('//form[contains(@class, "mod-lightboxgallery-search-form")]')->item(0);
        $this->assertNotNull($form);
        foreach ($xpath->query('.//select | .//input[@type="search"]', $form) as $control) {
            $this->assertSame(1, $xpath->query('//label[@for="' . $control->getAttribute('id') . '"]')->length);
        }
        $this->assertSame('bus & co', $xpath->query('.//input[@name="search"]', $form)->item(0)->getAttribute('value'));
        $options = $xpath->query('.//select[@name="gallery"]/option', $form);
        $this->assertSame('0', $options->item(0)->getAttribute('value'));
        $this->assertSame(get_string('allgalleries', 'lightboxgallery'), $options->item(0)->textContent);
        $selected = $xpath->query('.//select[@name="gallery"]/option[@selected]', $form);
        $this->assertSame(1, $selected->length);
        $this->assertSame((string) $second->id, $selected->item(0)->getAttribute('value'));
    }

    /**
     * The recent activity report shows the plugin's icon when asked for details.
     */
    public function test_recent_activity_icon(): void {
        $activity = (object) [
            'type' => 'lightboxgallery',
            'cmid' => $this->gallery->cmid,
            'name' => 'Field trip',
            'timestamp' => time(),
            'user' => $this->getDataGenerator()->create_user(),
            'content' => (object) ['id' => 1, 'comment' => 'Nice', 'url' => new \moodle_url('/mod/lightboxgallery/view.php')],
        ];

        ob_start();
        lightboxgallery_print_recent_mod_activity($activity, $this->course->id, true, [], true);
        $html = ob_get_clean();

        $this->assertStringContainsString('monologo', $html);
        $this->assertStringContainsString('alt="Field trip"', $html);
    }
}
