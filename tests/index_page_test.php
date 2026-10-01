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
 * Tests for the course's list of galleries (index.php).
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class index_page_test extends \advanced_testcase {
    /**
     * Render index.php for a course, as the current user.
     *
     * @param int $courseid
     * @return string
     */
    private function render_index(int $courseid): string {
        global $CFG, $DB, $OUTPUT, $PAGE, $USER;
        $_GET['id'] = $courseid;
        ob_start();
        try {
            include($CFG->dirroot . '/mod/lightboxgallery/index.php');
        } finally {
            $html = ob_get_clean();
            unset($_GET['id']);
        }
        return $html;
    }

    /**
     * The list shows each gallery with its counts, description and RSS link.
     */
    public function test_lists_galleries(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        lightboxgallery_image::set_thumbnail_budget(lightboxgallery_image::SYNC_THUMBNAIL_LIMIT);
        $CFG->enablerssfeeds = 1;
        set_config('enablerssfeeds', 1, 'lightboxgallery');

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $withrss = $this->getDataGenerator()->create_module('lightboxgallery', [
            'course' => $course->id, 'name' => 'Field trip', 'rss' => 1, 'comments' => 1,
            'intro' => '<p>Photos from the <strong>field trip</strong></p>', 'introformat' => FORMAT_HTML,
        ]);
        $this->getDataGenerator()->create_module(
            'lightboxgallery',
            ['course' => $course->id, 'name' => 'Science fair', 'rss' => 0, 'comments' => 0]
        );

        // One image and one non-image file in the first gallery, and a comment on each gallery.
        $context = \context_module::instance($withrss->cmid);
        $this->getDataGenerator()->get_plugin_generator('mod_lightboxgallery')->create_image($withrss, 'bus.png', 8, 8);
        get_file_storage()->create_file_from_string(['contextid' => $context->id, 'component' => 'mod_lightboxgallery',
            'filearea' => 'gallery_images', 'itemid' => 0, 'filepath' => '/', 'filename' => 'notes.txt'], 'Not an image');
        foreach ($DB->get_fieldset_select('lightboxgallery', 'id', 'course = ?', [$course->id]) as $galleryid) {
            $DB->insert_record(
                'lightboxgallery_comments',
                ['gallery' => $galleryid, 'userid' => $student->id, 'commenttext' => 'Nice', 'timemodified' => time()]
            );
        }

        $this->setUser($student);
        $html = $this->render_index($course->id);

        $this->assertStringContainsString('Field trip', $html);
        $this->assertStringContainsString('Science fair', $html);
        $this->assertStringContainsString('<strong>field trip</strong>', $html);
        $this->assertStringContainsString('1 images', $html);
        $this->assertStringNotContainsString('2 images', $html);

        // Comments are counted only where they're turned on.
        $this->assertSame(1, substr_count($html, '1 comments'));

        // One RSS link, built for the gallery's context; the other gallery says there's no feed.
        $this->assertSame(1, substr_count($html, '/rss/file.php/' . $context->id . '/'));
        $this->assertStringContainsString(get_string('norssfeedavailable', 'lightboxgallery'), $html);
    }
}
