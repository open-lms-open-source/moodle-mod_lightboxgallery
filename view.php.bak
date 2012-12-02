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
 * Prints a particular instance of lightboxgallery
 *
 * @package   mod_lightboxgallery
 * @copyright 2011 John Kelsh <john.kelsh@netspot.com.au>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once(dirname(__FILE__).'/locallib.php');

require_once($CFG->libdir.'/completionlib.php');
require_once($CFG->libdir.'/filelib.php');
require_once($CFG->libdir.'/rsslib.php');
require_once(dirname(__FILE__).'/imageclass.php');

global $DB;

$id = optional_param('id', 0, PARAM_INT); // Course module id.
$l = optional_param('l', 0, PARAM_INT); // instance id.
$page = optional_param('page', 0, PARAM_INT);
$search  = optional_param('search', '', PARAM_TEXT);
$editing = optional_param('editing', 0, PARAM_BOOL);

if ($id) {
    if (!$cm = get_coursemodule_from_id('lightboxgallery', $id)) {
        print_error('invalidcoursemodule');
    }
    if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
        print_error('coursemisconf');
    }
    if (!$gallery = $DB->get_record('lightboxgallery', array('id' => $cm->instance))) {
        print_error('invalidcoursemodule');
    }
} else {
    if (!$gallery = $DB->get_record('lightboxgallery', array('id' => $l))) {
        print_error('invalidlightboxgalleryid', 'lightboxgallery');
    }
    if (!$course = $DB->get_record('course', array('id' => $gallery->course))) {
        print_error('invalidcourseid');
    }
    if (!$cm = get_coursemodule_from_instance("lightboxgallery", $gallery->id, $course->id)) {
        print_error('invalidcoursemodule');
    }
}


require_login($course, true, $cm);

if ($gallery->ispublic) {
    $userid = (isloggedin() ? $USER->id : 0);
} else {
    require_login($course, true, $cm);
    $userid = $USER->id;
}

$context = get_context_instance(CONTEXT_MODULE, $cm->id);

if ($editing) {
    require_capability('mod/lightboxgallery:edit', $context);
}

if (empty($cm->visible) and !has_capability('moodle/course:viewhiddenactivities', $context)) {
    notice(get_string("activityiscurrentlyhidden"));
}

lightboxgallery_config_defaults();

add_to_log($course->id, 'lightboxgallery', 'view', 'view.php?id='.$cm->id.'&page='.$page, $gallery->id, $cm->id, $userid);

// Mark viewed
$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$PAGE->set_cm($cm);
$PAGE->set_url('/mod/lightboxgallery/view.php', array('id' => $cm->id));
$PAGE->set_title($gallery->name);
$PAGE->set_heading($course->shortname);
$button = '';
if (has_capability('mod/lightboxgallery:edit', $context)) {
    $url_params = array('id' => $id, 'page' => $page, 'editing' => $editing ? '0' : '1');
    $url = new moodle_url('/mod/lightboxgallery/view.php', $url_params);
    $strediting = get_string('turnediting'.($editing ? 'off' : 'on'));
    $button = $OUTPUT->single_button($url, $strediting, 'get').' ';
}
$button .= update_module_button($cm->id, $course->id, get_string('modulename', 'lightboxgallery'));
$PAGE->set_button($button);
$PAGE->requires->css('/mod/lightboxgallery/assets/skins/sam/gallery-lightbox-skin.css');
$PAGE->requires->js('/mod/lightboxgallery/gallery-lightbox-min.js');
$PAGE->requires->js('/mod/lightboxgallery/module.js');

$allowrssfeed = (lightboxgallery_rss_enabled() && $gallery->rss);
$heading = get_string('displayinggallery', 'lightboxgallery', $gallery->name);

if ($allowrssfeed) {
    rss_add_http_header($context, 'mod_lightboxgallery', $gallery->id, $gallery->name);
    $strrsssub = get_string('rsssubscribe', 'lightboxgallery');
    $heading .= ' '.rss_get_link($context->id, $userid, 'mod_lightboxgallery', $gallery->id, $strrsssub);
}

echo $OUTPUT->header();

echo $OUTPUT->heading($heading);

if ($gallery->intro && !$editing) {
    echo $OUTPUT->box(format_module_intro('lightboxgallery', $gallery, $cm->id), 'generalbox', 'intro');
}

echo $OUTPUT->box_start('generalbox lightbox-gallery clearfix');

$fs = get_file_storage();
$stored_files = $fs->get_area_files($context->id, 'mod_lightboxgallery', 'gallery_images');

$image_count = 1;

foreach ($stored_files as $stored_file) {
    if (!$stored_file->is_valid_image()) {
        continue;
    }

    if ($gallery->perpage > 0 &&
        (($image_count > (($gallery->perpage * $page) + $gallery->perpage) || ($image_count < ($gallery->perpage * $page) + 1)))) {
        $image_count++;
        continue;
    }

    $image = new lightboxgallery_image($stored_file, $gallery, $cm);

    echo $image->get_image_display_html($editing);

    if (!is_float($image_count / $gallery->perrow)) {
        echo $OUTPUT->box('', 'clearfix');
    }

    $image_count++;
}

echo ($image_count < 1 ? print_string('errornoimages', 'lightboxgallery') : '');
echo $OUTPUT->box_end();

if ($gallery->perpage) {
    $barurl = $CFG->wwwroot.'/mod/lightboxgallery/view.php?id='.$cm->id.'&amp;' . ($editing ? 'editing=1&amp;' : '');
    $pagingbar = new paging_bar($image_count, $page, $gallery->perpage, $barurl);
    echo $OUTPUT->render($pagingbar);
}

$showtags = !in_array('tag', explode(',', get_config('lightboxgallery', 'disabledplugins')));

if (!$editing && $showtags) {
    $desc_compare = $DB->sql_compare_text('description');
    $sql = "SELECT $desc_compare
              FROM {lightboxgallery_image_meta}
             WHERE gallery = {$gallery->id}
               AND metatype = 'tag'
          GROUP BY $desc_compare
          ORDER BY COUNT($desc_compare) DESC,
                   $desc_compare ASC";
    if ($tags = $DB->get_records_sql($sql, array(), 0, 10)) {
        lightboxgallery_print_tags(get_string('tagspopular', 'lightboxgallery'), $tags, $course->id, $gallery->id);
    }
}

$options = array();

if (has_capability('mod/lightboxgallery:addimage', $context)) {
    $opturl = new moodle_url('/mod/lightboxgallery/imageadd.php', array('id' => $cm->id));
    $options[] = html_writer::link($opturl, get_string('addimage', 'lightboxgallery'));
}

if ($gallery->comments && has_capability('mod/lightboxgallery:addcomment', $context)) {
    $opturl = new moodle_url('/mod/lightboxgallery/comment.php', array('id' => $gallery->id));
    $options[] = html_writer::link($opturl, get_string('addcomment', 'lightboxgallery'));
}

if (count($options) > 0) {
    echo $OUTPUT->box(implode(' | ', $options), 'center');
}

if (!$editing && $gallery->comments && has_capability('mod/lightboxgallery:viewcomments', $context)) {
    if ($comments = $DB->get_records('lightboxgallery_comments', array('gallery' => $gallery->id), 'timemodified ASC')) {
        foreach ($comments as $comment) {
            lightboxgallery_print_comment($comment, $context);
        }
    }
}

echo $OUTPUT->footer();

