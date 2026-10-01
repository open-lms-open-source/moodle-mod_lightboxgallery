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

defined('MOODLE_INTERNAL') || die();

// The tools use the plugin's image class and library functions.
global $CFG;
require_once($CFG->dirroot . '/mod/lightboxgallery/locallib.php');
require_once($CFG->dirroot . '/mod/lightboxgallery/imageclass.php');

/**
 * Base class to be extended for edit plugins
 *
 * @package   mod_lightboxgallery
 * @copyright 2010 John Kelsh
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class base {
    /**
     * @var \lightboxgallery_image $imageobj The image object
     */
    public $imageobj;
    /**
     * @var \context_module $cm The context module
     */
    public $cm;
    /**
     * @var \stdClass
     */
    public $gallery;
    /**
     * @var \stdClass
     */
    public $image;
    /**
     * @var \lightboxgallery_image
     */
    public $lbgimage;
    /**
     * @var \stdClass
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
     * @var int The page of the gallery the user came from, so they can return to it.
     */
    public $page;

    /**
     * Constructor.
     *
     * @param \stdClass $gallery
     * @param \context_module $cm
     * @param \stdClass $image
     * @param \stdClass $tab
     * @param bool|null $showthumb
     * @param int $page The page of the gallery the user came from.
     */
    public function __construct($gallery, $cm, $image, $tab, $showthumb = true, $page = 0) {
        $this->gallery = $gallery;
        $this->page = (int) $page;
        $this->cm = $cm;
        $this->image = $image;
        $this->tab = $tab;
        $this->showthumb = $showthumb;
        $this->context = \context_module::instance($this->cm->id);

        $fs = get_file_storage();
        $storedfile = $fs->get_file($this->context->id, 'mod_lightboxgallery', 'gallery_images', '0', '/', $this->image);
        $this->lbgimage = new \lightboxgallery_image($storedfile, $this->gallery, $this->cm);
    }

    /**
     * Check if the form is being processed.
     *
     * @return mixed
     * @throws \coding_exception
     */
    public function processing() {
        return optional_param('process', false, PARAM_BOOL);
    }

    /**
     * The values every tool's form needs to post back to the image editing page.
     *
     * @return array
     */
    protected function get_form_context(): array {
        return [
            'action' => (new \moodle_url('/mod/lightboxgallery/imageedit.php'))->out(false),
            'sesskey' => sesskey(),
            'cmid' => $this->cm->id,
            'image' => $this->image,
            'tab' => $this->tab,
            'page' => $this->page,
        ];
    }

    /**
     * Render one of the tools' templates, which post back to the image editing page.
     *
     * @param string $template The template's name within mod_lightboxgallery/edit.
     * @param array $context The tool's own values for the template.
     * @return string
     */
    protected function render_form(string $template, array $context = []): string {
        global $OUTPUT;

        return $OUTPUT->render_from_template('mod_lightboxgallery/edit/' . $template, $context + $this->get_form_context());
    }

    /**
     * Output the tool's form.
     *
     * @return string
     */
    public function output() {
        return '';
    }

    /**
     * Process the form submission.
     *
     * @return void
     */
    public function process_form() {
    }
}
