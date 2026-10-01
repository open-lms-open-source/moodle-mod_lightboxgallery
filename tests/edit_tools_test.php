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
 * Tests that the image editing tools remember which page of the gallery the user came from.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_lightboxgallery\local\edit\base::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_lightboxgallery\local\edit\delete::class)]
final class edit_tools_test extends \advanced_testcase {
    /** @var \stdClass The gallery record. */
    private $gallery;

    /** @var \stdClass */
    private $cm;

    /**
     * Create a gallery with one image.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        lightboxgallery_image::set_thumbnail_budget(lightboxgallery_image::SYNC_THUMBNAIL_LIMIT);

        $course = $this->getDataGenerator()->create_course();
        $gallery = $this->getDataGenerator()->create_module('lightboxgallery', ['course' => $course->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_lightboxgallery')->create_image($gallery, 'photo.png');
        $this->gallery = $DB->get_record('lightboxgallery', ['id' => $gallery->id], '*', MUST_EXIST);
        $this->cm = get_coursemodule_from_instance('lightboxgallery', $gallery->id, $course->id, false, MUST_EXIST);
    }

    /**
     * Create one of the editing tools.
     *
     * @param string $tool
     * @param int $page
     * @return \mod_lightboxgallery\local\edit\base
     */
    private function make_tool(string $tool, int $page): \mod_lightboxgallery\local\edit\base {
        $class = "\\mod_lightboxgallery\\local\\edit\\$tool";
        return new $class($this->gallery, $this->cm, 'photo.png', $tool, $page);
    }

    /**
     * Data provider for test_form_keeps_page.
     *
     * @return array
     */
    public static function tool_provider(): array {
        return array_map(fn($tool) => [$tool], array_combine(
            ['caption', 'delete', 'flip', 'resize', 'rotate', 'tag', 'thumbnail'],
            ['caption', 'delete', 'flip', 'resize', 'rotate', 'tag', 'thumbnail']
        ));
    }

    /**
     * Each tool's forms carry the gallery page, once each, so the user goes back to it afterwards.
     *
     * @param string $tool
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tool_provider')]
    public function test_form_keeps_page(string $tool): void {
        $html = $this->make_tool($tool, 3)->output('');

        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($doc);
        // Every form that posts back to the editing page carries it; the tag tool's import button goes elsewhere.
        $forms = $xpath->query('//form[contains(@action, "/mod/lightboxgallery/imageedit.php")]');
        $this->assertGreaterThan(0, $forms->length);
        foreach ($forms as $form) {
            $page = $xpath->query('.//input[@type="hidden"][@name="page"]', $form);
            $this->assertSame(1, $page->length);
            $this->assertSame('3', $page->item(0)->getAttribute('value'));
            $this->assertSame(1, $xpath->query('.//input[@name="sesskey"]', $form)->length);
        }
    }

    /**
     * Every control in each tool's forms has a label that points at it.
     *
     * @param string $tool
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tool_provider')]
    public function test_controls_are_labelled(string $tool): void {
        // The tag tool only lists tags to remove when the image has some.
        $this->make_tool('tag', 0)->lbgimage->add_tag('beach');
        $html = $this->make_tool($tool, 0)->output('A caption');

        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($doc);
        $controls = $xpath->query('//textarea | //select | //input[not(@type="hidden") and not(@type="submit")]');
        foreach ($controls as $control) {
            $id = $control->getAttribute('id');
            $this->assertNotSame('', $id, "A {$control->nodeName} named {$control->getAttribute('name')} has no id.");
            $this->assertSame(1, $xpath->query('//label[@for="' . $id . '"]')->length, "Nothing labels #$id.");
        }
    }

    /**
     * Without a page, the tools return to the first page.
     */
    public function test_page_defaults_to_first(): void {
        $this->assertSame(0, $this->make_tool('rotate', 0)->page);
        $this->assertStringContainsString('name="page" value="0"', $this->make_tool('rotate', 0)->output());
    }

    /**
     * The delete tool deletes the image and then sends the user back to the gallery.
     */
    public function test_delete_redirects_after_deleting(): void {
        $tool = $this->make_tool('delete', 2);

        try {
            $tool->process_form();
            $this->fail('Expected a redirect.');
        } catch (\moodle_exception $e) {
            $this->assertSame('redirecterrordetected', $e->errorcode);
        }

        $context = \context_module::instance($this->cm->id);
        $this->assertFalse(get_file_storage()->get_file(
            $context->id,
            'mod_lightboxgallery',
            'gallery_images',
            0,
            '/',
            'photo.png'
        ));
    }
}
