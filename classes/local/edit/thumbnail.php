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
 * The thumbnail plugin class.
 *
 * @package   mod_lightboxgallery
 * @copyright 2010 John Kelsh
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class thumbnail extends base {
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
     * Output the forms.
     *
     * @return string
     */
    public function output() {
        global $OUTPUT;

        // Tall images can move up or down, others left or right.
        if ($this->lbgimage->width < $this->lbgimage->height) {
            $directions = [['value' => 1, 'label' => get_string('dirup', 'lightboxgallery')],
                ['value' => 2, 'label' => get_string('dirdown', 'lightboxgallery')]];
        } else {
            $directions = [['value' => 3, 'label' => get_string('dirleft', 'lightboxgallery')],
                ['value' => 4, 'label' => get_string('dirright', 'lightboxgallery')]];
        }

        $indexurl = new \moodle_url('/mod/lightboxgallery/index.php', ['id' => $this->gallery->course]);
        return $this->render_form('thumbnail', [
            'helpicon' => $OUTPUT->help_icon('setasindex', 'lightboxgallery', true, $indexurl),
            'directions' => $directions,
        ]);
    }

    /**
     * Process the form submission.
     *
     * @return string|void
     * @throws \coding_exception
     * @throws \file_exception
     * @throws \stored_file_creation_exception
     */
    public function process_form() {
        $domove = true;

        if (optional_param('index', '', PARAM_TEXT)) {
            return lightboxgallery_index_thumbnail($this->gallery->course, $this->gallery, $this->lbgimage);
        } else if (optional_param('reset', '', PARAM_TEXT)) {
            $offsetx = 0;
            $offsety = 0;
        } else {
            $move = optional_param('move', -1, PARAM_INT);
            $offset = optional_param('offset', 20, PARAM_INT);
            switch ($move) {
                case 1:
                    $offsetx = 0;
                    $offsety = -$offset;
                    break;
                case 2:
                    $offsetx = 0;
                    $offsety = $offset;
                    break;
                case 3:
                    $offsetx = -$offset;
                    $offsety = 0;
                    break;
                case 4:
                    $offsetx = $offset;
                    $offsety = 0;
                    break;
                default:
                    $domove = false;
            }
        }

        if ($domove) {
            $this->lbgimage->create_thumbnail($offsetx, $offsety);
        }
    }
}
