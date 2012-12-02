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
 * Search page for searching for images
 *
 * @package   mod_lightboxgallery
 * @copyright 2010 John Kelsh
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once(dirname(__FILE__).'/imageclass.php');

require_once($CFG->libdir.'/filelib.php');

$cid = required_param('id', PARAM_INT);
$g = optional_param('gallery', '0', PARAM_INT);
$search = optional_param('search', '', PARAM_CLEAN);

if (!$gallery = $DB->get_record('lightboxgallery', array('id' => $g))) {
    print_error('invalidlightboxgalleryid', 'lightboxgallery');
}
if (!$course = $DB->get_record('course', array('id' => $gallery->course))) {
    print_error('invalidcourseid');
}
if (!$cm = get_coursemodule_from_instance("lightboxgallery", $gallery->id, $course->id)) {
    print_error('invalidcoursemodule');
}

require_login($course, true, $cm);

if ($gallery->ispublic) {
    $userid = (isloggedin() ? $USER->id : 0);
} else {
    require_login($course, true, $cm);
    $userid = $USER->id;
}

$context = get_context_instance(CONTEXT_MODULE, $cm->id);

add_to_log($course->id, 'lightboxgallery', 'search', 'search.php?id='.$cid.'&gallery='.$g.'&search='.$search, $search, 0, $userid);

$PAGE->set_url('/mod/lightboxgallery/search.php', array('id' => $cm->id, 'search' => $search));
$PAGE->set_title($gallery->name);
$PAGE->set_heading($course->shortname);
$PAGE->requires->css('/mod/lightboxgallery/assets/skins/sam/gallery-lightbox-skin.css');
$PAGE->requires->js('/mod/lightboxgallery/gallery-lightbox-min.js');
$PAGE->requires->js('/mod/lightboxgallery/module.js');

echo $OUTPUT->header();

if ($instances = get_all_instances_in_course('lightboxgallery', $course)) {
    $options = array(0 => get_string('all'));
    foreach ($instances as $instance) {
        $options[$instance->id] = $instance->name;
    }

    echo('<form action="search.php">');

    $table = new html_table;
    $table->width = '*';
    $table->align = array('left', 'left', 'left', 'left');
    $table->data[] = array(get_string('modulenameshort', 'lightboxgallery'), html_writer::select($options, 'gallery', $g),
                           '<input type="text" name="search" size="10" value="'.s($search, true).'" />' .
                           '<input type="hidden" name="id" value="'.$cm->id.'" />',
                           '<input type="submit" value="'.get_string('search').'" />');
    echo html_writer::table($table);

    echo('</form>');
}

$fs = get_file_storage();

if ($results = $DB->get_records_select('lightboxgallery_image_meta',
        $DB->sql_like('description', '?', false).($g > 0 ? 'AND gallery = ?' : ''), array('%'.$search.'%', ($g > 0 ? $g : null)))) {
    echo $OUTPUT->box_start('generalbox lightbox-gallery clearfix');

    $hashes = array();

    foreach ($results as $result) {
        if (!isset($hashes[$result->image])) {
            if ($stored_file = $fs->get_file($context->id, 'mod_lightboxgallery', 'gallery_images', 0, '/', $result->image)) {
                $image = new lightboxgallery_image($stored_file, $gallery, $cm);
                echo $image->get_image_display_html();
                $hashes[$result->image] = 1;
            }
        }
    }

    echo $OUTPUT->box_end();

} else {

    echo $OUTPUT->box(get_string('errornosearchresults', 'lightboxgallery'));

}

echo $OUTPUT->footer();
