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
 * The tag import script.
 *
 * @package   mod_lightboxgallery
 * @copyright 2010 John Kelsh
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../../../config.php');
require_once(dirname(__FILE__) . '/../../lib.php');
require_once(dirname(__FILE__) . '/../../locallib.php');

$id = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

if (!$gallery = $DB->get_record('lightboxgallery', ['id' => $id])) {
    throw new \moodle_exception('invalidlightboxgalleryid', 'lightboxgallery');
}
if (!$course = $DB->get_record('course', ['id' => $gallery->course])) {
    throw new \moodle_exception('invalidcourseid');
}
if (!$cm = get_coursemodule_from_instance('lightboxgallery', $gallery->id, $course->id)) {
    throw new \moodle_exception('invalidcoursemodule');
}

require_login($course->id);

$context = context_module::instance($cm->id);
$galleryurl = $CFG->wwwroot . '/mod/lightboxgallery/view.php?id=' . $cm->id;

require_capability('mod/lightboxgallery:edit', $context);

$PAGE->set_cm($cm);
$PAGE->set_url('/mod/lightboxgallery/edit/tag/import.php', ['id' => $id]);
$PAGE->set_title($gallery->name);
$PAGE->set_heading($course->shortname);
echo $OUTPUT->header();

$disabledplugins = explode(',', get_config('lightboxgallery', 'disabledplugins'));
if (in_array('tag', $disabledplugins)) {
    throw new \moodle_exception(get_string('tagsdisabled', 'lightboxgallery'));
}

if ($confirm && confirm_sesskey()) {
    $a = lightboxgallery_import_iptc_tags($gallery, $context);

    foreach (array_keys((array)$a) as $b) {
        $a->{$b} = number_format($a->{$b});
    }

    notice(get_string('tagsimportfinish', 'lightboxgallery', $a), $galleryurl);
} else {
    $confirmurl = new moodle_url(
        '/mod/lightboxgallery/edit/tag/import.php',
        ['id' => $gallery->id, 'confirm' => 1, 'sesskey' => sesskey()]
    );
    $cancelurl = new moodle_url(
        '/mod/lightboxgallery/view.php',
        ['id' => $cm->id, 'editing' => 1]
    );
    echo $OUTPUT->confirm(get_string('tagsimportconfirm', 'lightboxgallery'), $confirmurl, $cancelurl);
}

echo $OUTPUT->footer();
