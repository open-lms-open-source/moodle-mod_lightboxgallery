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

/**
 * Unit tests for (some of) mod/lightboxgallery/lib.php.
 *
 * @package    mod_lightboxgallery
 * @category   phpunit
 * @author     Adam Olley <adam.olley@netspot.com.au>
 * @copyright  2012 NetSpot Pty Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License
 */


defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/lightboxgallery/lib.php');


/**
 * @copyright  2012 NetSpot Pty Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License
 */
class mod_lightboxgallery_lib_testcase extends basic_testcase {
    public function test_lightboxgallery_resize_text() {
        $this->assertEquals('test123', lightboxgallery_resize_text('test123', 10));
        $this->assertEquals('test123456...', lightboxgallery_resize_text('test1234567', 10));
    }
}
