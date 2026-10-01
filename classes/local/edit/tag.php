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
 * The tag plugin class.
 *
 * @package   mod_lightboxgallery
 * @copyright 2010 John Kelsh
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tag extends base {
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

        $importurl = new \moodle_url('/mod/lightboxgallery/edit/tag/import.php', ['id' => $this->gallery->id]);
        $tags = [];
        foreach ($this->lbgimage->get_tags() as $tag) {
            $tags[] = ['id' => $tag->id, 'description' => $tag->description];
        }

        return $this->render_form('tag', [
            'importbutton' => $OUTPUT->single_button($importurl, get_string('tagsimport', 'lightboxgallery')),
            'hastags' => !empty($tags),
            'tags' => $tags,
        ]);
    }

    /**
     * Process the form submission.
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function process_form() {
        $tag = optional_param('tag', '', PARAM_TAG);

        if ($tag) {
            $this->lbgimage->add_tag($tag);
        } else if (optional_param('delete', 0, PARAM_INT)) {
            if ($deletes = optional_param_array('deletetags', [], PARAM_RAW)) {
                foreach ($deletes as $delete) {
                    $this->lbgimage->delete_tag(clean_param($delete, PARAM_INT));
                }
            }
        }
    }
}
