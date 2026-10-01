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
require_once($CFG->dirroot . '/mod/lightboxgallery/imageadd_form.php');

/**
 * Tests for checking uploads before they're added to a gallery.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_lightboxgallery_imageadd_form::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_check_zip')]
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_add_images')]
final class upload_validation_test extends \advanced_testcase {
    /** @var \stdClass The gallery record. */
    private $gallery;

    /** @var \stdClass */
    private $cm;

    /** @var int The draft area the test uploads into. */
    private $draftitemid;

    /**
     * Create a gallery, and log in as an admin with an empty draft area.
     */
    protected function setUp(): void {
        global $DB, $PAGE;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        lightboxgallery_image::set_thumbnail_budget(lightboxgallery_image::SYNC_THUMBNAIL_LIMIT);

        $course = $this->getDataGenerator()->create_course();
        $gallery = $this->getDataGenerator()->create_module('lightboxgallery', ['course' => $course->id]);
        $this->gallery = $DB->get_record('lightboxgallery', ['id' => $gallery->id], '*', MUST_EXIST);
        $this->cm = get_coursemodule_from_instance('lightboxgallery', $gallery->id, $course->id, false, MUST_EXIST);
        $this->draftitemid = file_get_unused_draft_itemid();
        $PAGE->set_url('/mod/lightboxgallery/imageadd.php', ['id' => $this->cm->id]);
    }

    /**
     * Put a file in the draft area, as an upload would.
     *
     * @param string $filename
     * @param string $content
     * @return \stored_file
     */
    private function upload(string $filename, string $content): \stored_file {
        global $USER;
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $this->draftitemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * Encode a blank PNG.
     *
     * @return string
     */
    private function png(): string {
        ob_start();
        imagepng(imagecreatetruecolor(8, 8));
        return ob_get_clean();
    }

    /**
     * Upload a zip holding the given number of small files.
     *
     * @param string $filename
     * @param int $count
     * @return \stored_file
     */
    private function upload_zip(string $filename, int $count): \stored_file {
        $path = make_request_directory() . '/' . $filename;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        for ($i = 1; $i <= $count; $i++) {
            $zip->addFromString("file$i.txt", str_repeat('x', 100));
        }
        $zip->close();
        return $this->upload($filename, file_get_contents($path));
    }

    /**
     * Run the upload form's validation on the draft area.
     *
     * @return array The errors.
     */
    private function validate(): array {
        $form = new \mod_lightboxgallery_imageadd_form(null, ['id' => $this->cm->id, 'gallery' => $this->gallery]);
        return $form->validation(['image' => $this->draftitemid, 'id' => $this->cm->id], []);
    }

    /**
     * The draft area's filenames, sorted.
     *
     * @return string[]
     */
    private function draft_filenames(): array {
        global $USER;
        $names = [];
        $files = get_file_storage()->get_area_files(
            \context_user::instance($USER->id)->id,
            'user',
            'draft',
            $this->draftitemid,
            'filename',
            false
        );
        foreach ($files as $file) {
            $names[] = $file->get_filename();
        }
        return $names;
    }

    /**
     * Images and a reasonable zip pass.
     */
    public function test_valid_upload(): void {
        $this->upload('one.png', $this->png());
        $this->upload('two.png', $this->png());
        $this->upload_zip('more.zip', 3);

        $this->assertSame([], $this->validate());
        $this->assertSame(['more.zip', 'one.png', 'two.png'], $this->draft_filenames());
    }

    /**
     * Every file is checked, not just the first; only the bad ones are removed.
     */
    public function test_each_file_is_checked(): void {
        $this->upload('a.png', $this->png());
        $this->upload('notes.txt', 'Not an image');
        $this->upload('b.png', $this->png());
        $this->upload('report.pdf', '%PDF-1.4');

        $errors = $this->validate();

        $this->assertStringContainsString('notes.txt', $errors['image']);
        $this->assertStringContainsString('report.pdf', $errors['image']);
        $this->assertSame(['a.png', 'b.png'], $this->draft_filenames());
    }

    /**
     * A zip holding too many files is refused before it's extracted.
     */
    public function test_zip_with_too_many_files(): void {
        $this->upload('one.png', $this->png());
        $this->upload_zip('huge.zip', LIGHTBOXGALLERY_ZIP_MAX_FILES + 1);

        $errors = $this->validate();

        $this->assertStringContainsString('huge.zip', $errors['image']);
        $this->assertStringContainsString((string) LIGHTBOXGALLERY_ZIP_MAX_FILES, $errors['image']);
        $this->assertSame(['one.png'], $this->draft_filenames());
    }

    /**
     * The zip check counts files and adds up their extracted size.
     */
    public function test_check_zip_limits(): void {
        $zip = $this->upload_zip('ten.zip', 10);
        $course = get_course($this->gallery->course);

        $this->assertNull(lightboxgallery_check_zip($zip, $course));
        $this->assertNull(lightboxgallery_check_zip($zip, $course, 10, 1000));
        $this->assertStringContainsString('more than 9 files', lightboxgallery_check_zip($zip, $course, 9, 1000));
        $this->assertStringContainsString(display_size(999), lightboxgallery_check_zip($zip, $course, 10, 999));
    }

    /**
     * By default a zip may expand to no more than the course's maximum upload size.
     */
    public function test_zip_size_follows_course_upload_limit(): void {
        global $CFG, $DB;
        // Ten files of 100 bytes each.
        $zip = $this->upload_zip('ten.zip', 10);
        $CFG->maxbytes = 0;

        $DB->set_field('course', 'maxbytes', 1000, ['id' => $this->gallery->course]);
        $this->assertNull(lightboxgallery_check_zip($zip, get_course($this->gallery->course)));

        $DB->set_field('course', 'maxbytes', 999, ['id' => $this->gallery->course]);
        $course = get_course($this->gallery->course);
        $this->assertStringContainsString(display_size(999), lightboxgallery_check_zip($zip, $course));
        $this->assertStringContainsString('ten.zip', $this->validate()['image']);

        // A lower site limit still applies.
        $DB->set_field('course', 'maxbytes', 0, ['id' => $this->gallery->course]);
        $CFG->maxbytes = 999;
        $this->assertStringContainsString(
            display_size(999),
            lightboxgallery_check_zip($zip, get_course($this->gallery->course))
        );
    }

    /**
     * Adding images refuses an oversized zip too, without extracting anything.
     */
    public function test_add_images_refuses_large_zip(): void {
        $zip = $this->upload_zip('huge.zip', LIGHTBOXGALLERY_ZIP_MAX_FILES + 1);
        $context = \context_module::instance($this->cm->id);

        try {
            lightboxgallery_add_images([$zip], $context, $this->cm, $this->gallery);
            $this->fail('Expected the zip to be refused.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('huge.zip', $e->getMessage());
        }
        $this->assertTrue(get_file_storage()->is_area_empty($context->id, 'mod_lightboxgallery', 'unpacktemp'));
        $this->assertTrue(get_file_storage()->is_area_empty($context->id, 'mod_lightboxgallery', 'gallery_images'));
    }
}
