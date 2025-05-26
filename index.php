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
 * Shows a list of available galleries
 *
 * @package   mod_lightboxgallery
 * @copyright 2011 John Kelsh
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once(dirname(__FILE__).'/locallib.php');
require_once($CFG->libdir.'/rsslib.php');

$id = required_param('id', PARAM_INT);

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
$context = context_course::instance($course->id);
require_course_login($course);

$event = \mod_lightboxgallery\event\course_module_instance_list_viewed::create([
    'context' => $context,
]);
$event->add_record_snapshot('course', $course);
$event->trigger();

$PAGE->set_url('/mod/lightboxgallery/view.php', ['id' => $id]);
$PAGE->set_title(get_string('modulenameplural', 'lightboxgallery'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();

if (! $galleries = get_all_instances_in_course('lightboxgallery', $course)) {
    echo $OUTPUT->heading(get_string('thereareno', 'moodle', $strgalleries), 2);
    echo $OUTPUT->continue_button('view.php?id='.$course->id);
    echo $OUTPUT->footer();
    die();
}

$table = new html_table();
$table->head = [get_string($course->format == 'weeks' ? 'week' : 'topic'),
                        '&nbsp;',
                        get_string('modulenameshort', 'lightboxgallery'),
                        get_string('description'),
                        'RSS', ];
$table->align = ['center', 'center', 'left', 'left', 'center'];
$table->width = '*';

$fobj = new stdClass;
$fobj->para = false;

$prevsection = '';

// phpcs:disable moodle.Commenting.TodoComment
// TODO: Put this in a renderer.
foreach ($galleries as $gallery) {
    $cm = context_module::instance($gallery->coursemodule);

    $printsection = ($gallery->section !== $prevsection ? true : false);
    if ($printsection) {
        $table->data[] = 'hr';
    }

    if (lightboxgallery_rss_enabled() && $gallery->rss) {
        $rss = rss_get_link($course->id, $USER->id, 'lightboxgallery', $gallery->id, get_string('rsssubscribe', 'lightboxgallery'));
    }

    $fs = get_file_storage();
    $files = $fs->get_area_files($cm->id, 'mod_lightboxgallery', 'gallery_images');
    $imagecount = 0;
    foreach ($files as $file) {
        if ($file->get_filename() != '.') {
            $imagecount++;
        }
    }
    $commentcount = $DB->count_records('lightboxgallery_comments', ['gallery' => $gallery->id]);

    $viewurl = new moodle_url('/mod/lightboxgallery/view.php', ['id' => $gallery->coursemodule]);
    $table->data[] = [($printsection ? $gallery->section : ''),
                           lightboxgallery_index_thumbnail($course->id, $gallery),
                           html_writer::link($viewurl, $gallery->name).
                           '<br />'.get_string('imagecounta', 'lightboxgallery', $imagecount).' '.
                           get_string('commentcount', 'lightboxgallery', $commentcount),
                           format_text($gallery->intro, FORMAT_MOODLE, $fobj),
                           (isset($rss) ? $rss : get_string('norssfeedavailable', 'lightboxgallery')), ];

    $prevsection = $gallery->section;
}

echo $OUTPUT->heading(get_string('modulenameplural', 'lightboxgallery'), 2);
echo html_writer::table($table);
echo $OUTPUT->footer();

