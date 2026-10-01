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

require_once(dirname(__DIR__, 2) . '/config.php');
require_once(__DIR__ . '/locallib.php');
require_once($CFG->libdir . '/rsslib.php');
require_once($CFG->dirroot . '/course/lib.php');

$id = required_param('id', PARAM_INT);

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
$context = context_course::instance($course->id);
require_course_login($course);

$event = \mod_lightboxgallery\event\course_module_instance_list_viewed::create([
    'context' => $context,
]);
$event->add_record_snapshot('course', $course);
$event->trigger();

$PAGE->set_url('/mod/lightboxgallery/index.php', ['id' => $course->id]);
$PAGE->set_title(get_string('modulenameplural', 'lightboxgallery'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();

if (! $galleries = get_all_instances_in_course('lightboxgallery', $course)) {
    echo $OUTPUT->heading(get_string('thereareno', 'moodle', get_string('modulenameplural', 'lightboxgallery')), 2);
    echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $course->id]));
    echo $OUTPUT->footer();
    die();
}

// Count every gallery's comments in one query.
[$insql, $inparams] = $DB->get_in_or_equal(array_column($galleries, 'id'));
$commentcounts = $DB->get_records_sql_menu("SELECT gallery, COUNT(1)
                                              FROM {lightboxgallery_comments}
                                             WHERE gallery $insql
                                          GROUP BY gallery", $inparams);

$usesections = course_format_uses_sections($course->format);
$fs = get_file_storage();
$prevsection = null;
$rows = [];
foreach ($galleries as $gallery) {
    $gallerycontext = context_module::instance($gallery->coursemodule);

    $rsslink = null;
    if (lightboxgallery_rss_enabled() && $gallery->rss) {
        $rsslink = rss_get_link(
            $gallerycontext->id,
            $USER->id,
            'mod_lightboxgallery',
            $gallery->id,
            get_string('rsssubscribe', 'lightboxgallery')
        );
    }

    $imagecount = 0;
    foreach ($fs->get_area_files($gallerycontext->id, 'mod_lightboxgallery', 'gallery_images', 0, 'filename', false) as $file) {
        if (file_mimetype_in_typegroup($file->get_mimetype(), 'web_image')) {
            $imagecount++;
        }
    }
    $counts = get_string('imagecounta', 'lightboxgallery', $imagecount);
    if (lightboxgallery_can_view_comments($gallery, $course, $gallerycontext)) {
        $counts .= ' ' . get_string('commentcount', 'lightboxgallery', $commentcounts[$gallery->id] ?? 0);
    }

    $rows[] = [
        'newsection' => $gallery->section !== $prevsection,
        'sectionname' => $usesections ? get_section_name($course, $gallery->section) : '',
        'imageurl' => lightboxgallery_index_image_url($course->id, $gallery)->out(false),
        'viewurl' => (new moodle_url('/mod/lightboxgallery/view.php', ['id' => $gallery->coursemodule]))->out(false),
        'name' => format_string($gallery->name),
        'visible' => (bool) $gallery->visible,
        'counts' => $counts,
        'intro' => format_module_intro('lightboxgallery', $gallery, $gallery->coursemodule),
        'rsslink' => $rsslink,
    ];
    $prevsection = $gallery->section;
}

echo $OUTPUT->heading(get_string('modulenameplural', 'lightboxgallery'), 2);
echo $OUTPUT->render_from_template('mod_lightboxgallery/index', [
    'usesections' => $usesections,
    'sectionheading' => $usesections ? get_string('sectionname', 'format_' . $course->format) : '',
    'galleries' => $rows,
]);
echo $OUTPUT->footer();
