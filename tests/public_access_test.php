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
require_once($CFG->dirroot . '/mod/lightboxgallery/lib.php');

/**
 * Tests for what anonymous and non-enrolled users can reach in public galleries.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_public_gallery_visible')]
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_can_view_comments')]
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_pluginfile')]
final class public_access_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /** @var \stdClass The gallery record. */
    private $gallery;

    /** @var int */
    private $cmid;

    /**
     * Create a course with a public gallery that allows comments.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $gallery = $this->getDataGenerator()->create_module(
            'lightboxgallery',
            ['course' => $this->course->id, 'ispublic' => 1, 'comments' => 1]
        );
        $this->gallery = $DB->get_record('lightboxgallery', ['id' => $gallery->id], '*', MUST_EXIST);
        $this->cmid = $gallery->cmid;
    }

    /**
     * The course record and the gallery's course module as the current user sees them.
     *
     * @return array [stdClass $course, \cm_info $cm]
     */
    private function load(): array {
        global $DB;
        $course = $DB->get_record('course', ['id' => $this->course->id], '*', MUST_EXIST);
        return [$course, get_fast_modinfo($course)->get_cm($this->cmid)];
    }

    /**
     * A visible public gallery is shown to anonymous visitors.
     */
    public function test_visible_public_gallery(): void {
        $this->setUser(null);
        [$course, $cm] = $this->load();
        $this->assertTrue(lightboxgallery_public_gallery_visible($course, $cm));
    }

    /**
     * Hiding the activity hides it from anonymous visitors but not from teachers.
     */
    public function test_hidden_activity(): void {
        set_coursemodule_visible($this->cmid, 0);

        $this->setUser(null);
        [$course, $cm] = $this->load();
        $this->assertFalse(lightboxgallery_public_gallery_visible($course, $cm));

        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($teacher);
        [$course, $cm] = $this->load();
        $this->assertTrue(lightboxgallery_public_gallery_visible($course, $cm));
    }

    /**
     * Hiding the course hides the gallery from anonymous visitors.
     */
    public function test_hidden_course(): void {
        global $DB;
        $DB->set_field('course', 'visible', 0, ['id' => $this->course->id]);
        rebuild_course_cache($this->course->id, true);

        $this->setUser(null);
        [$course, $cm] = $this->load();
        $this->assertFalse(lightboxgallery_public_gallery_visible($course, $cm));
    }

    /**
     * An availability restriction applies to anonymous visitors too.
     */
    public function test_availability_restriction(): void {
        global $DB;
        set_config('enableavailability', 1);
        $availability = \core_availability\tree::get_root_json(
            [\availability_date\condition::get_json('>=', time() + DAYSECS)]
        );
        $DB->set_field('course_modules', 'availability', json_encode($availability), ['id' => $this->cmid]);
        rebuild_course_cache($this->course->id, true);

        $this->setUser(null);
        [$course, $cm] = $this->load();
        $this->assertFalse(lightboxgallery_public_gallery_visible($course, $cm));
    }

    /**
     * Comments on a public gallery are hidden from anyone who couldn't open the course.
     */
    public function test_comments_on_public_gallery(): void {
        $context = \context_module::instance($this->cmid);

        // Anonymous visitors.
        $this->setUser(null);
        $this->assertFalse(lightboxgallery_can_view_comments($this->gallery, $this->course, $context));

        // The guest user, when the course doesn't allow guest access.
        $this->setGuestUser();
        $this->assertFalse(lightboxgallery_can_view_comments($this->gallery, $this->course, $context));

        // A logged-in user who isn't enrolled.
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(lightboxgallery_can_view_comments($this->gallery, $this->course, $context));

        // An enrolled student.
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'student'));
        $this->assertTrue(lightboxgallery_can_view_comments($this->gallery, $this->course, $context));

        // Nobody sees comments once they're turned off.
        $this->gallery->comments = 0;
        $this->assertFalse(lightboxgallery_can_view_comments($this->gallery, $this->course, $context));
    }

    /**
     * The guest user sees comments on a public gallery when the course allows guest access.
     */
    public function test_comments_with_guest_access(): void {
        global $DB;
        $guestplugin = enrol_get_plugin('guest');
        $instance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'guest'], '*', MUST_EXIST);
        $guestplugin->update_status($instance, ENROL_INSTANCE_ENABLED);

        $this->setGuestUser();
        $context = \context_module::instance($this->cmid);
        $this->assertTrue(lightboxgallery_can_view_comments($this->gallery, $this->course, $context));
    }

    /**
     * Only the gallery's own image areas are served.
     */
    public function test_pluginfile_rejects_other_areas(): void {
        $this->setUser(null);
        $context = \context_module::instance($this->cmid);
        $cm = get_coursemodule_from_id('lightboxgallery', $this->cmid, 0, false, MUST_EXIST);

        foreach (['unpacktemp', 'edittemp', 'somethingelse'] as $filearea) {
            get_file_storage()->create_file_from_string([
                'contextid' => $context->id,
                'component' => 'mod_lightboxgallery',
                'filearea' => $filearea,
                'itemid' => 0,
                'filepath' => '/',
                'filename' => 'a.png',
            ], 'content');
            $this->assertFalse(lightboxgallery_pluginfile($this->course, $cm, $context, $filearea, ['0', 'a.png'], true));
        }
    }

    /**
     * Files in a hidden public gallery require the normal course login.
     */
    public function test_pluginfile_hidden_public_gallery(): void {
        set_coursemodule_visible($this->cmid, 0);
        $this->setUser(null);
        $context = \context_module::instance($this->cmid);
        $cm = get_coursemodule_from_id('lightboxgallery', $this->cmid, 0, false, MUST_EXIST);

        $this->expectException(\moodle_exception::class);
        lightboxgallery_pluginfile($this->course, $cm, $context, 'gallery_images', ['0', 'a.png'], true);
    }
}
