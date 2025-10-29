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
 * The resize plugin class.
 *
 * @package   mod_lightboxgallery
 * @copyright 2010 John Kelsh
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class edit_resize extends edit_base {
    /**
     * @var lang_string|string
     */
    private $strresize;
    /**
     * @var lang_string|string
     */
    private $strscale;
    /**
     * @var string[]
     */
    private $resizeoptions;

    /**
     * Constructor.
     *
     * @param stdClass $gallery
     * @param context_module $cm
     * @param stdClass $image
     * @param stdClass $tab
     * @throws coding_exception
     */
    public function __construct($gallery, $cm, $image, $tab) {
        parent::__construct($gallery, $cm, $image, $tab, true);
        $this->strresize = get_string('edit_resize', 'lightboxgallery');
        $this->strscale = get_string('edit_resizescale', 'lightboxgallery');
        $this->resizeoptions = lightboxgallery_resize_options();
    }

    /**
     * Output the form.
     *
     * @return string|void
     * @throws coding_exception
     */
    public function output() {
        $fs = get_file_storage();
        $storedfile = $fs->get_file($this->context->id, 'mod_lightboxgallery', 'gallery_images', '0', '/', $this->image);
        $image = new lightboxgallery_image($storedfile, $this->gallery, $this->cm);

        $currentsize = sprintf('%s: %dx%d', get_string('currentsize', 'lightboxgallery'), $image->width, $image->height) .
                       '<br /><br />';

        $sizeselect = '<div class="input-group"><select name="size" class="form-select">';
        foreach ($this->resizeoptions as $index => $option) {
            $sizeselect .= '<option value="' . $index . '">' . $option . '</option>';
        }

        $sizeselect .= '</select>&nbsp;<input type="submit" class="btn btn-secondary" name="button" value="' .
                       $this->strresize . '" /></div><br /><br />';

        $scaleselect = '<div class="input-group"><select name="scale" class="form-select">' .
                       '  <option value="200">200&#37;</option>' .
                       '  <option value="150">150&#37;</option>' .
                       '  <option value="125">125&#37;</option>' .
                       '  <option value="75">75&#37;</option>' .
                       '  <option value="50">50&#37;</option>' .
                       '  <option value="25">25&#37;</option>' .
                       '</select>&nbsp;<input type="submit" class="btn btn-secondary" name="button" value="' .
                       $this->strscale . '" /></div>';

        return $this->enclose_in_form($currentsize . $sizeselect . $scaleselect);
    }

    /**
     * Process the form submission.
     *
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     * @throws file_exception
     * @throws stored_file_creation_exception
     */
    public function process_form() {
        $button = required_param('button', PARAM_TEXT);

        switch ($button) {
            case $this->strresize:
                $size = required_param('size', PARAM_INT);
                [$width, $height] = explode('x', $this->resizeoptions[$size]);
                break;
            case $this->strscale:
                $scale = required_param('scale', PARAM_INT);
                $width = $this->lbgimage->width * ($scale / 100);
                $height = $this->lbgimage->height * ($scale / 100);
                break;
        }

        $this->image = $this->lbgimage->resize_image($width, $height);
    }
}
