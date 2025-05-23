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
 * Base class to be extended for edit plugins
 *
 * @package   mod_lightboxgallery
 * @copyright 2010 John Kelsh
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class edit_base {

    /**
     * @var lightboxgallery_image $imageobj The image object
     */
    public $imageobj;
    /**
     * @var context_module $cm The context module
     */
    public $cm;
    /**
     * @var stdClass
     */
    public $gallery;
    /**
     * @var stdClass
     */
    public $image;
    /**
     * @var lightboxgallery_image
     */
    public $lbgimage;
    /**
     * @var stdClass
     */
    public $tab;
    /**
     * @var bool|null
     */
    public $showthumb;
    /**
     * @var \core\context\module|false
     */
    public $context;

    /**
     * Constructor.
     *
     * @param stdClass $gallery
     * @param context_module $cm
     * @param stdClass $image
     * @param stdClass $tab
     * @param bool|null $showthumb
     */
    public function __construct($gallery, $cm, $image, $tab, $showthumb = true) {
        $this->gallery = $gallery;
        $this->cm = $cm;
        $this->image = $image;
        $this->tab = $tab;
        $this->showthumb = $showthumb;
        $this->context = context_module::instance($this->cm->id);

        $fs = get_file_storage();
        $storedfile = $fs->get_file($this->context->id, 'mod_lightboxgallery', 'gallery_images', '0', '/', $this->image);
        $this->lbgimage = new lightboxgallery_image($storedfile, $this->gallery, $this->cm);
    }

    /**
     * Check if the form is being processed.
     *
     * @return mixed
     * @throws coding_exception
     */
    public function processing() {
        return optional_param('process', false, PARAM_BOOL);
    }

    /**
     * Enclose the form in a form tag.
     *
     * @param string $text The text to enclose in the form
     * @return string
     */
    public function enclose_in_form($text) {
        global $CFG, $USER;

        return '<form action="'.$CFG->wwwroot.'/mod/lightboxgallery/imageedit.php" method="post">'.
               '<fieldset class="invisiblefieldset">'.
               '<input type="hidden" name="sesskey" value="'.$USER->sesskey.'" />'.
               '<input type="hidden" name="id" value="'.$this->cm->id.'" />'.
               '<input type="hidden" name="image" value="'.$this->image.'" />'.
               '<input type="hidden" name="tab" value="'.$this->tab.'" />'.
               '<input type="hidden" name="process" value="1" />'.$text.'</fieldset></form>';
    }

    /**
     * Output the form.
     *
     * @return void
     */
    public function output() {

    }

    /**
     * Process the form submission.
     *
     * @return void
     */
    public function process_form() {

    }

}
