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
 * A page for uploading new images
 *
 * @package   mod_lightworkgallery
 * @copyright 2011 John Kelsh <john.kelsh@netspot.com.au>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once(dirname(__FILE__).'/imageadd_form.php');
require_once(dirname(__FILE__).'/imageclass.php');

$id = required_param('id', PARAM_INT);

$cm         = get_coursemodule_from_id('lightboxgallery', $id, 0, false, MUST_EXIST);
$course     = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$gallery    = $DB->get_record('lightboxgallery', array('id' => $cm->instance), '*', MUST_EXIST);

require_login($course->id);

$context = get_context_instance(CONTEXT_MODULE, $cm->id);
require_capability('mod/lightboxgallery:addimage', $context);

$PAGE->set_url('/mod/lightboxgallery/view.php', array('id' => $cm->id));
$PAGE->set_title($gallery->name);
$PAGE->set_heading($course->shortname);
$PAGE->set_button(update_module_button($cm->id, $course->id, get_string('modulename', 'lightboxgallery')));

$mform = new mod_lightboxgallery_imageadd_form(null, array('id' => $cm->id));

if ($mform->is_cancelled()) {
    redirect($CFG->wwwroot.'/mod/lightboxgallery/view.php?id='.$cm->id);

} else if (($formdata = $mform->get_data()) && confirm_sesskey()) {
    require_once($CFG->dirroot . '/lib/uploadlib.php');

    $fs = get_file_storage();
    $draftid = file_get_submitted_draft_itemid('image');
    if (!$files = $fs->get_area_files(
        get_context_instance(CONTEXT_USER, $USER->id)->id, 'user', 'draft', $draftid, 'id DESC', false)) {
        redirect($PAGE->url);
    }
    $stored_file = reset($files);

    if ($gallery->autoresize == AUTO_RESIZE_UPLOAD || $gallery->autoresize == AUTO_RESIZE_BOTH) {
        $resize = $gallery->resize;
    } else if (isset($formdata->resize)) {
        $resize = $formdata->resize;
    } else {
        $resize = 0; // No resize.
    }

    lightboxgallery_add_images($stored_file, $context, $cm, $gallery, $resize);
    redirect($CFG->wwwroot.'/mod/lightboxgallery/view.php?id='.$cm->id);

}

echo $OUTPUT->header();
echo $OUTPUT->box($OUTPUT->notification(get_string('acceptablefiletypebriefing', 'mod_lightboxgallery')));

$mform->display();

echo $OUTPUT->footer();

