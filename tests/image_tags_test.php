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
require_once($CFG->dirroot . '/mod/lightboxgallery/imageclass.php');

/**
 * Tests for tag handling on lightboxgallery_image.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(lightboxgallery_image::class)]
final class image_tags_test extends \advanced_testcase {
    /**
     * Create a gallery containing one image with the given filename.
     *
     * @param string $filename
     * @return lightboxgallery_image
     */
    private function create_gallery_image(string $filename): lightboxgallery_image {
        global $CFG, $DB;

        $course = $this->getDataGenerator()->create_course();
        $gallery = $this->getDataGenerator()->create_module('lightboxgallery', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('lightboxgallery', $gallery->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $fileinfo = [
            'contextid' => $context->id,
            'component' => 'mod_lightboxgallery',
            'filearea' => 'gallery_images',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ];
        $storedfile = get_file_storage()->create_file_from_pathname($fileinfo,
            $CFG->dirroot . '/mod/lightboxgallery/pix/index.png');

        $gallery = $DB->get_record('lightboxgallery', ['id' => $gallery->id], '*', MUST_EXIST);
        return new lightboxgallery_image($storedfile, $gallery, $cm);
    }

    /**
     * Tags on a same-named image in another gallery are neither listed nor deletable.
     */
    public function test_tags_are_scoped_to_gallery(): void {
        global $DB;
        $this->resetAfterTest();

        $mine = $this->create_gallery_image('IMG_0001.png');
        $theirs = $this->create_gallery_image('IMG_0001.png');

        $mytagid = $mine->add_tag('mine');
        $theirtagid = $theirs->add_tag('theirs');

        // Only this gallery's tag is listed.
        $this->assertEquals([$mytagid], array_keys($mine->get_tags()));

        // Deleting another gallery's tag id through this image does nothing.
        $mine->delete_tag($theirtagid);
        $this->assertTrue($DB->record_exists('lightboxgallery_image_meta', ['id' => $theirtagid]));

        // A caption on this image can't be deleted as though it were a tag.
        $mine->set_caption('A caption');
        $mygalleryid = $DB->get_field('lightboxgallery_image_meta', 'gallery', ['id' => $mytagid], MUST_EXIST);
        $captionid = $DB->get_field('lightboxgallery_image_meta', 'id',
            ['gallery' => $mygalleryid, 'metatype' => 'caption'], MUST_EXIST);
        $mine->delete_tag($captionid);
        $this->assertTrue($DB->record_exists('lightboxgallery_image_meta', ['id' => $captionid]));

        // This image's own tag can still be deleted.
        $mine->delete_tag($mytagid);
        $this->assertFalse($DB->record_exists('lightboxgallery_image_meta', ['id' => $mytagid]));
    }
}
