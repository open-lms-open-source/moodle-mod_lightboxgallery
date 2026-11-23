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
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Tests for course reset of gallery user data.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_reset_userdata')]
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_reset_course_form_definition')]
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_reset_course_form_defaults')]
final class reset_test extends \advanced_testcase {
    /** @var \stdClass The course being reset. */
    private $course;

    /** @var \stdClass[] Its two galleries. */
    private $galleries = [];

    /** @var \stdClass A gallery in another course, which a reset must not touch. */
    private $othergallery;

    /**
     * Create two galleries in the course and one elsewhere, each with a student comment, a caption and an image.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $othercourse = $generator->create_course();
        $student = $generator->create_and_enrol($this->course, 'student');
        $generator->enrol_user($student->id, $othercourse->id, 'student');

        $this->galleries[] = $generator->create_module('lightboxgallery', ['course' => $this->course->id, 'comments' => 1]);
        $this->galleries[] = $generator->create_module('lightboxgallery', ['course' => $this->course->id, 'comments' => 1]);
        $this->othergallery = $generator->create_module('lightboxgallery', ['course' => $othercourse->id, 'comments' => 1]);

        foreach (array_merge($this->galleries, [$this->othergallery]) as $gallery) {
            $this->add_content($gallery, $student->id);
        }
    }

    /**
     * Give a gallery an image, a caption and a comment.
     *
     * @param \stdClass $gallery
     * @param int $userid The commenter.
     * @return void
     */
    private function add_content(\stdClass $gallery, int $userid): void {
        global $DB;
        $this->getDataGenerator()->get_plugin_generator('mod_lightboxgallery')->create_image($gallery, 'photo.png');
        $DB->insert_record('lightboxgallery_image_meta', [
            'gallery' => $gallery->id,
            'image' => 'photo.png',
            'metatype' => 'caption',
            'description' => 'A photo',
        ]);
        $DB->insert_record('lightboxgallery_comments', [
            'gallery' => $gallery->id,
            'userid' => $userid,
            'commenttext' => 'Nice photo',
            'timemodified' => time(),
        ]);
    }

    /**
     * Count the comments on a gallery.
     *
     * @param \stdClass $gallery
     * @return int
     */
    private function comment_count(\stdClass $gallery): int {
        global $DB;
        return $DB->count_records('lightboxgallery_comments', ['gallery' => $gallery->id]);
    }

    /**
     * A course reset deletes the course's gallery comments, and keeps its images and captions.
     *
     * Before this, galleries had no reset support, so comments survived "Delete all user data".
     */
    public function test_reset_deletes_comments(): void {
        global $DB;

        $status = reset_course_userdata((object) [
            'id' => $this->course->id,
            'courseid' => $this->course->id,
            'reset_lightboxgallery_comments' => 1,
        ]);

        foreach ($this->galleries as $gallery) {
            $this->assertEquals(0, $this->comment_count($gallery));
            $this->assertTrue($DB->record_exists('lightboxgallery_image_meta', ['gallery' => $gallery->id]));
            $context = \context_module::instance($gallery->cmid);
            $this->assertNotFalse(
                get_file_storage()->get_file($context->id, 'mod_lightboxgallery', 'gallery_images', 0, '/', 'photo.png')
            );
        }
        // Another course's gallery is untouched.
        $this->assertEquals(1, $this->comment_count($this->othergallery));

        $items = array_filter($status, fn($s) => $s['component'] === get_string('modulenameplural', 'lightboxgallery'));
        $this->assertCount(1, $items);
        $item = reset($items);
        $this->assertEquals(get_string('resetcomments', 'lightboxgallery'), $item['item']);
        $this->assertFalse($item['error']);
    }

    /**
     * Comments are kept when the option is left unticked.
     */
    public function test_reset_without_option_keeps_comments(): void {
        $status = lightboxgallery_reset_userdata((object) [
            'courseid' => $this->course->id,
            'reset_lightboxgallery_comments' => 0,
        ]);

        $this->assertSame([], $status);
        foreach ($this->galleries as $gallery) {
            $this->assertEquals(1, $this->comment_count($gallery));
        }
    }

    /**
     * The reset form offers the option, ticked by default.
     */
    public function test_reset_form(): void {
        $this->assertSame(
            ['reset_lightboxgallery_comments' => 1],
            lightboxgallery_reset_course_form_defaults($this->course)
        );

        $mform = new \MoodleQuickForm('reset', 'post', '');
        lightboxgallery_reset_course_form_definition($mform);
        $this->assertTrue($mform->elementExists('reset_lightboxgallery_comments'));
    }
}
