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
require_once($CFG->dirroot . '/mod/lightboxgallery/edit/tag/tag.class.php');

/**
 * Tests for importing IPTC keywords as tags, and for the tag tool.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_import_iptc_tags')]
#[\PHPUnit\Framework\Attributes\CoversClass(\edit_tag::class)]
final class tag_import_test extends \advanced_testcase {
    /** @var \stdClass The gallery record. */
    private $gallery;

    /** @var \stdClass */
    private $cm;

    /** @var \context_module */
    private $context;

    /**
     * Create an empty gallery for each test.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        lightboxgallery_image::set_thumbnail_budget(lightboxgallery_image::SYNC_THUMBNAIL_LIMIT);

        $course = $this->getDataGenerator()->create_course();
        $gallery = $this->getDataGenerator()->create_module('lightboxgallery', ['course' => $course->id]);
        $this->gallery = $DB->get_record('lightboxgallery', ['id' => $gallery->id], '*', MUST_EXIST);
        $this->cm = get_coursemodule_from_instance('lightboxgallery', $gallery->id, $course->id, false, MUST_EXIST);
        $this->context = \context_module::instance($this->cm->id);
    }

    /**
     * Store an image in the gallery.
     *
     * @param string $filename
     * @param string $content
     * @return void
     */
    private function add_file(string $filename, string $content): void {
        get_file_storage()->create_file_from_string([
            'contextid' => $this->context->id,
            'component' => 'mod_lightboxgallery',
            'filearea' => 'gallery_images',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * Build a JPEG carrying the given IPTC keywords.
     *
     * @param string[] $keywords Raw keyword bytes.
     * @return string
     */
    private function make_jpeg(array $keywords): string {
        $path = make_request_directory() . '/photo.jpg';
        imagejpeg(imagecreatetruecolor(8, 8), $path);

        $iptc = '';
        foreach ($keywords as $keyword) {
            // Record 2, dataset 25 (keywords): a tag marker, the record and dataset numbers, then the length.
            $iptc .= chr(0x1C) . chr(2) . chr(25) . pack('n', strlen($keyword)) . $keyword;
        }
        return iptcembed($iptc, $path);
    }

    /**
     * Get the gallery's tags, sorted.
     *
     * @return string[]
     */
    private function get_tags(): array {
        global $DB;
        $tags = $DB->get_fieldset_select('lightboxgallery_image_meta', 'description', 'gallery = ? AND metatype = ?',
            [$this->gallery->id, 'tag']);
        sort($tags);
        return $tags;
    }

    /**
     * How many temporary file copies exist.
     *
     * @return int
     */
    private function count_temp_files(): int {
        return count(glob(make_temp_directory('files') . '/tempup_*') ?: []);
    }

    /**
     * Keywords are imported once, whether they're stored as UTF-8 or Latin-1.
     */
    public function test_import_keywords(): void {
        $this->add_file('trip.jpg', $this->make_jpeg(['beach', 'über', "caf\xE9"]));

        $result = lightboxgallery_import_iptc_tags($this->gallery, $this->context);

        $this->assertSame(3, $result->tags);
        $this->assertSame(1, $result->images);
        $this->assertSame(['beach', 'café', 'über'], $this->get_tags());

        // Importing again doesn't add them twice.
        $this->assertSame(0, lightboxgallery_import_iptc_tags($this->gallery, $this->context)->tags);
        $this->assertCount(3, $this->get_tags());
    }

    /**
     * Only JPEGs are read, and no temporary copies are left behind.
     */
    public function test_import_leaves_no_temp_files(): void {
        $this->add_file('one.jpg', $this->make_jpeg(['one']));
        $this->add_file('two.jpg', $this->make_jpeg([]));
        $gd = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($gd);
        $this->add_file('three.png', ob_get_clean());

        $before = $this->count_temp_files();
        $result = lightboxgallery_import_iptc_tags($this->gallery, $this->context);

        $this->assertSame(1, $result->tags);
        $this->assertSame($before, $this->count_temp_files());
    }

    /**
     * Showing the tag tool doesn't copy the image.
     */
    public function test_tag_tool_leaves_no_temp_files(): void {
        $this->add_file('trip.jpg', $this->make_jpeg(['beach']));
        $tool = new \edit_tag($this->gallery, $this->cm, 'trip.jpg', 'tag');

        $before = $this->count_temp_files();
        $tool->output();

        $this->assertSame($before, $this->count_temp_files());
    }
}
