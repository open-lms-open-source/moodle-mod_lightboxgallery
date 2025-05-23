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
 * The rotate plugin class.
 *
 * @package   mod_lightboxgallery
 * @copyright 2010 John Kelsh
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class edit_rotate extends edit_base {

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
        $result = get_string('selectrotation', 'lightboxgallery').'<br /><br />'.
                  '<label class="me-3"><input type="radio" class="form-check-input me-1" name="angle" value="-90" '.
                  '/>-90&#176;</label>'.
                  '<label class="me-3"><input type="radio" class="form-check-input me-1" name="angle" value="180" '.
                  '/>180&#176;</label>'.
                  '<label><input type="radio" class="form-check-input me-1" name="angle" value="90" />90&#176;</label>'.
                  '<br /><br /><input type="submit" class="btn btn-secondary" value="'.
                  get_string('edit_rotate', 'lightboxgallery').'" />';

        return $this->enclose_in_form($result);
    }

    /**
     * Process the form.
     *
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     * @throws file_exception
     * @throws stored_file_creation_exception
     */
    public function process_form() {
        $angle = required_param('angle', PARAM_INT);
        $this->image = $this->lbgimage->rotate_image($angle);
    }

}
