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
 * The tag plugin class.
 *
 * @package   mod_lightboxgallery
 * @copyright 2010 John Kelsh
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class edit_tag extends edit_base {
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
     * @return string
     * @throws \core\exception\moodle_exception
     * @throws coding_exception
     * @throws dml_exception
     */
    public function output() {
        global $OUTPUT;

        $stradd = get_string('add');

        $manualform = '<div class="input-group mb-3"><input type="text" class="form-control" name="tag" />' .
                      '<input type="submit" class="btn btn-secondary" value="' . $stradd . '" /></div>';
        $manualform = $this->enclose_in_form($manualform);

        $iptcform = '';
        $deleteform = '';

        $path = $this->lbgimage->copy_content_to_temp();
        $tags = $this->lbgimage->get_tags();

        if (isset($info['APP13'])) {
            $iptc = iptcparse($info['APP13']);
            if (isset($iptc['2#025'])) {
                $iptcform = '<input type="hidden" name="iptc" value="1" />';
                sort($iptc['2#025']);
                foreach ($iptc['2#025'] as $tag) {
                    $tag = core_text::strtolower($tag);
                    $exists = ($tags && in_array($tag, array_values($tags)));
                    $tag = htmlentities($tag);
                    $iptcform .= '<label ' . ($exists ? 'class="tag-exists"' : '') .
                        '><input type="checkbox" name="iptctags[]" value="' . $tag . '" />' . $tag . '</label><br />';
                }

                $iptcform .= '<input type="submit" class="btn btn-secondary" value="' . $stradd . '" />';
                $iptcform = '<span class="tag-head"> ' . get_string('tagsiptc', 'lightboxgallery') .
                    '</span>' . $this->enclose_in_form($iptcform);
            }
        }

        $iptcaddurl = new moodle_url('/mod/lightboxgallery/edit/tag/import.php', ['id' => $this->gallery->id]);
        $iptcform .= $OUTPUT->single_button($iptcaddurl, get_string('tagsimport', 'lightboxgallery'));

        if ($tags = $this->lbgimage->get_tags()) {
            $deleteform = '<input type="hidden" name="delete" value="1" />';
            foreach ($tags as $tag) {
                $deleteform .= '<label><input type="checkbox" name="deletetags[]" value="' . $tag->id . '" /> ' .
                               htmlentities($tag->description) . '</label><br />';
            }

            $deleteform .= '<input type="submit" class="btn btn-secondary" value="' . get_string('remove') . '" />';
            $deleteform = '<span class="tag-head"> ' . get_string('tagscurrent', 'lightboxgallery') . '</span>'
                          . $this->enclose_in_form($deleteform);
        }

        return $manualform . $iptcform . $deleteform;
    }

    /**
     * Process the form submission.
     *
     * @return void
     * @throws coding_exception
     * @throws dml_exception
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
