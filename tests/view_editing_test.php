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
 * Tests for who can edit a gallery from its page.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class view_editing_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /** @var \stdClass The gallery, with its cmid. */
    private $gallery;

    /**
     * Create a gallery with one image.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        lightboxgallery_image::set_thumbnail_budget(lightboxgallery_image::SYNC_THUMBNAIL_LIMIT);

        $this->course = $this->getDataGenerator()->create_course();
        $this->gallery = $this->getDataGenerator()->create_module('lightboxgallery', ['course' => $this->course->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_lightboxgallery')->create_image($this->gallery, 'bus.png');
    }

    /**
     * Load the gallery's page as the current user, with edit mode on or off.
     *
     * @param bool $editmode
     * @return string The page output.
     */
    private function load_gallery(bool $editmode): string {
        global $CFG, $DB, $OUTPUT, $PAGE, $USER;
        $USER->editing = $editmode;
        $_GET = ['id' => $this->gallery->cmid];
        ob_start();
        try {
            include($CFG->dirroot . '/mod/lightboxgallery/view.php');
        } finally {
            $html = ob_get_clean();
            $_GET = [];
        }
        return $html;
    }

    /**
     * Set a capability for a role in the gallery.
     *
     * @param string $capability
     * @param int $permission
     * @param string $role The role's shortname.
     * @return void
     */
    private function set_permission(string $capability, int $permission, string $role): void {
        global $DB;
        $roleid = $DB->get_field('role', 'id', ['shortname' => $role], MUST_EXIST);
        assign_capability($capability, $permission, $roleid, \context_module::instance($this->gallery->cmid));
    }

    /**
     * Whether the page showed the images' edit menus.
     *
     * @param string $html
     * @return bool
     */
    private function shows_edit_menu(string $html): bool {
        return str_contains($html, 'lightbox-gallery-image-editmenu');
    }

    /**
     * Someone who can edit the gallery, but not the course, can turn edit mode on and edit it.
     */
    public function test_gallery_editor_without_course_editing(): void {
        global $PAGE;
        $this->set_permission('mod/lightboxgallery:edit', CAP_ALLOW, 'student');
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'student'));

        $html = $this->load_gallery(true);

        $this->assertTrue($PAGE->user_allowed_editing());
        $this->assertTrue($this->shows_edit_menu($html));
    }

    /**
     * With edit mode off, the gallery shows as usual.
     */
    public function test_gallery_editor_with_edit_mode_off(): void {
        $this->set_permission('mod/lightboxgallery:edit', CAP_ALLOW, 'student');
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'student'));

        $this->assertFalse($this->shows_edit_menu($this->load_gallery(false)));
    }

    /**
     * A course editor who can't edit this gallery sees it as usual in edit mode, rather than an error.
     */
    public function test_course_editor_without_gallery_editing(): void {
        $this->set_permission('mod/lightboxgallery:edit', CAP_PROHIBIT, 'editingteacher');
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher'));

        $this->assertFalse($this->shows_edit_menu($this->load_gallery(true)));
    }

    /**
     * Someone who can't edit the gallery can't turn edit mode on for it.
     */
    public function test_student_cannot_edit(): void {
        global $PAGE;
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'student'));

        $html = $this->load_gallery(true);

        $this->assertFalse($PAGE->user_allowed_editing());
        $this->assertFalse($this->shows_edit_menu($html));
    }
}
