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
 * The delete plugin class.
 *
 * @package   mod_lightboxgallery
 * @copyright 2010 John Kelsh
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class edit_delete extends edit_base {

    /**
     * Constructor.
     *
     * @param stdClass $gallery
     * @param context_module $cm
     * @param stdClass $image
     * @param stdClass $tab
     */
    public function __construct($gallery, $cm, $image, $tab) {
        parent::__construct($gallery, $cm, $image, $tab, true);
    }

    /**
     * Output the form.
     *
     * @return string|void
     * @throws coding_exception
     */
    public function output() {
        global $page;
        $result = get_string('deletecheck', '', $this->image).'<br /><br />';
        $result .= '<input type="hidden" name="page" value="'.$page.'" />';
        $result .= '<input type="submit" class="btn btn-secondary" value="'.get_string('yes').'" />';
        return $this->enclose_in_form($result);
    }

    /**
     * Process the form submission.
     *
     * @return void
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function process_form() {
        global $CFG, $page;
        $this->lbgimage->delete_file();
        redirect($CFG->wwwroot.'/mod/lightboxgallery/view.php?id='.$this->cm->id.'&page='.$page.'&editing=1');
    }

}
