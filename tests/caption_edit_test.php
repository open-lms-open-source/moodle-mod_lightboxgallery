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
require_once($CFG->dirroot . '/mod/lightboxgallery/edit/base.class.php');
require_once($CFG->dirroot . '/mod/lightboxgallery/edit/caption/caption.class.php');

/**
 * Tests for the caption editor.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\edit_caption::class)]
final class caption_edit_test extends \advanced_testcase {
    /**
     * Data provider for test_caption_round_trips.
     *
     * @return array
     */
    public static function caption_provider(): array {
        return [
            'numeric entities' => ['&#60;img src=x onerror=alert(1)&#62;'],
            'named entity' => ['Tom &amp; Jerry'],
            'ampersand' => ['Tom & Jerry'],
            'quotes' => ['"Quoted" and \'single\''],
            'closing textarea' => ['</textarea><b>bold</b>'],
        ];
    }

    /**
     * The caption editor shows the stored caption exactly, and nothing in it becomes markup.
     *
     * @param string $caption
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('caption_provider')]
    public function test_caption_round_trips(string $caption): void {
        global $DB;
        $this->resetAfterTest();
        lightboxgallery_image::set_thumbnail_budget(lightboxgallery_image::SYNC_THUMBNAIL_LIMIT);

        $course = $this->getDataGenerator()->create_course();
        $gallery = $this->getDataGenerator()->create_module('lightboxgallery', ['course' => $course->id]);
        $gallery = $DB->get_record('lightboxgallery', ['id' => $gallery->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('lightboxgallery', $gallery->id, $course->id, false, MUST_EXIST);
        $this->getDataGenerator()->get_plugin_generator('mod_lightboxgallery')->create_image($gallery, 'photo.png', 4, 4);

        $html = (new \edit_caption($gallery, $cm, 'photo.png', 'caption'))->output($caption);

        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        $textareas = $doc->getElementsByTagName('textarea');
        $this->assertSame(1, $textareas->length);
        $this->assertSame($caption, $textareas->item(0)->textContent);
        $this->assertSame(0, $doc->getElementsByTagName('img')->length);
        $this->assertSame(0, $doc->getElementsByTagName('b')->length);
    }
}
