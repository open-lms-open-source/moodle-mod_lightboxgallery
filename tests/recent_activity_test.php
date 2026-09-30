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
 * Tests that recent activity only lists comments the user is allowed to see.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_print_recent_activity')]
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_get_recent_mod_activity')]
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_comment_preview')]
final class recent_activity_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /** @var \stdClass */
    private $student;

    /**
     * Create a course with a student.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Create a gallery with one comment.
     *
     * @param string $commenttext
     * @param array $options Extra gallery settings.
     * @return \stdClass The gallery, with its cmid.
     */
    private function create_gallery_with_comment(string $commenttext, array $options = []): \stdClass {
        global $DB;

        $gallery = $this->getDataGenerator()->create_module('lightboxgallery',
            array_merge(['course' => $this->course->id, 'comments' => 1], $options));
        $DB->insert_record('lightboxgallery_comments', [
            'gallery' => $gallery->id,
            'userid' => $this->student->id,
            'commenttext' => $commenttext,
            'timemodified' => time(),
        ]);
        return $gallery;
    }

    /**
     * Capture what print_recent_activity prints, and what it returns.
     *
     * @return array [bool $result, string $output]
     */
    private function print_recent(): array {
        ob_start();
        $result = lightboxgallery_print_recent_activity($this->course, true, time() - HOURSECS);
        return [$result, ob_get_clean()];
    }

    /**
     * Comments from hidden galleries, or galleries with comments turned off, aren't listed.
     */
    public function test_print_recent_activity_filters_galleries(): void {
        $this->create_gallery_with_comment('Visible comment');
        $hidden = $this->create_gallery_with_comment('Hidden comment');
        set_coursemodule_visible($hidden->cmid, 0);
        $this->create_gallery_with_comment('Disabled comment', ['comments' => 0]);

        $this->setUser($this->student);
        [$result, $output] = $this->print_recent();

        $this->assertTrue($result);
        $this->assertStringContainsString('Visible comment', $output);
        $this->assertStringNotContainsString('Hidden comment', $output);
        $this->assertStringNotContainsString('Disabled comment', $output);
    }

    /**
     * Nothing is listed, and false is returned, without the viewcomments capability.
     */
    public function test_print_recent_activity_requires_capability(): void {
        global $DB;
        $gallery = $this->create_gallery_with_comment('Secret comment');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability('mod/lightboxgallery:viewcomments', CAP_PROHIBIT, $roleid,
            \context_module::instance($gallery->cmid));

        $this->setUser($this->student);
        [$result, $output] = $this->print_recent();

        $this->assertFalse($result);
        $this->assertSame('', $output);
    }

    /**
     * Comment previews are shown as escaped plain text.
     */
    public function test_print_recent_activity_escapes_preview(): void {
        $this->create_gallery_with_comment('<b>Tom &amp; Jerry</b><img src=x onerror=alert(1)>');

        $this->setUser($this->student);
        [, $output] = $this->print_recent();

        $this->assertStringContainsString('Tom &amp; Jerry', $output);
        $this->assertStringNotContainsString('<b>', $output);
        $this->assertStringNotContainsString('<img', $output);
    }

    /**
     * The recent activity report gets no comments from a gallery the user can't see comments in.
     */
    public function test_get_recent_mod_activity(): void {
        global $DB;
        $visible = $this->create_gallery_with_comment('Visible comment');
        $hidden = $this->create_gallery_with_comment('Hidden comment');
        set_coursemodule_visible($hidden->cmid, 0);
        $disabled = $this->create_gallery_with_comment('Disabled comment', ['comments' => 0]);
        $prohibited = $this->create_gallery_with_comment('Secret comment');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability('mod/lightboxgallery:viewcomments', CAP_PROHIBIT, $roleid,
            \context_module::instance($prohibited->cmid));

        $this->setUser($this->student);
        $activities = [];
        $index = 0;
        foreach ([$visible, $hidden, $disabled, $prohibited] as $gallery) {
            lightboxgallery_get_recent_mod_activity($activities, $index, time() - HOURSECS, $this->course->id,
                $gallery->cmid);
        }

        $this->assertCount(1, $activities);
        $this->assertEquals($visible->cmid, $activities[0]->cmid);
        $this->assertSame('Visible comment', $activities[0]->content->comment);
    }
}
