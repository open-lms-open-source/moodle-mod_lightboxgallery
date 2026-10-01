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

namespace mod_lightboxgallery\local\edit;

/**
 * The rotate plugin class.
 *
 * @package   mod_lightboxgallery
 * @copyright 2010 John Kelsh
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rotate extends base {
    /**
     * Constructor.
     *
     * @param \stdClass $gallery
     * @param \context_module $cm
     * @param \stdClass $image
     * @param \stdClass $tab
     * @param int $page The page of the gallery the user came from.
     */
    public function __construct($gallery, $cm, $image, $tab, $page = 0) {
        parent::__construct($gallery, $cm, $image, $tab, true, $page);
    }

    /**
     * Output the form.
     *
     * @return string
     */
    public function output() {
        $angles = [];
        foreach ([-90, 180, 90] as $index => $angle) {
            $angles[] = ['value' => $angle, 'label' => $angle . "\u{00B0}", 'first' => $index === 0];
        }
        return $this->render_form('rotate', ['angles' => $angles]);
    }

    /**
     * Process the form.
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \file_exception
     * @throws \stored_file_creation_exception
     */
    public function process_form() {
        $angle = required_param('angle', PARAM_INT);
        $this->image = $this->lbgimage->rotate_image($angle);
    }
}
