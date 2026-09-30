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
 * Tests that the image editing and tag import pages respect the gallery's visibility.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class edit_access_test extends \advanced_testcase {
    /** @var \stdClass The gallery, with its cmid. */
    private $gallery;

    /**
     * Create a gallery with one image, and a student allowed to edit it.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        lightboxgallery_image::set_thumbnail_budget(lightboxgallery_image::SYNC_THUMBNAIL_LIMIT);

        $course = $this->getDataGenerator()->create_course();
        $this->gallery = $this->getDataGenerator()->create_module('lightboxgallery', ['course' => $course->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_lightboxgallery')->create_image($this->gallery, 'photo.png');

        // A student given the edit permission, but not the right to see hidden activities.
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability('mod/lightboxgallery:edit', CAP_ALLOW, $roleid, \context_module::instance($this->gallery->cmid));
        $this->setUser($student);
    }

    /**
     * Load one of the plugin's pages with the given request parameters.
     *
     * @param string $script Path below the plugin directory.
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
     * Data provider for the page tests.
     *
     * @return array
     */
    public static function page_provider(): array {
        // Each page with the kind of id it takes, and something only that page shows.
        return [
            'image editing' => ['imageedit.php', 'cmid', 'name="caption"'],
            'tag import' => ['edit/tag/import.php', 'galleryid', 'Are you sure you want to import tags'],
        ];
    }

    /**
     * The request parameters for a page.
     *
     * @param string $idtype Whether the page takes the course module id or the gallery id.
     * @return array
     */
    private function params(string $idtype): array {
        $id = $idtype == 'cmid' ? $this->gallery->cmid : $this->gallery->id;
        return ['id' => $id, 'image' => 'photo.png', 'tab' => 'caption'];
    }

    /**
     * The page opens while the gallery is visible.
     *
     * @param string $script
     * @param string $idtype
     * @param string $pagetext
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('page_provider')]
    public function test_page_opens_for_visible_gallery(string $script, string $idtype, string $pagetext): void {
        $html = $this->load_page($script, $this->params($idtype));

        $this->assertStringContainsString($pagetext, $html);
    }

    /**
     * The page sends away a user who can't see the hidden gallery, even with the edit permission.
     *
     * require_login() redirects them, which PHPUnit reports as an exception.
     *
     * @param string $script
     * @param string $idtype
     * @param string $pagetext
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('page_provider')]
    public function test_page_refuses_hidden_gallery(string $script, string $idtype, string $pagetext): void {
        set_coursemodule_visible($this->gallery->cmid, 0);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('Unsupported redirect detected');
        $this->load_page($script, $this->params($idtype));
    }
}
