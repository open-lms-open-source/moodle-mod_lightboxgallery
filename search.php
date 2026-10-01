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

require_once(dirname(__DIR__, 2) . '/config.php');
require_once(__DIR__ . '/locallib.php');
require_once(__DIR__ . '/imageclass.php');

require_once($CFG->libdir . '/filelib.php');

// How many matching images to show on each page of results.
define('LIGHTBOXGALLERY_SEARCH_PERPAGE', 50);

$cid = required_param('id', PARAM_INT);
$g = optional_param('gallery', 0, PARAM_INT);
$search = trim(optional_param('search', '', PARAM_TEXT));
$page = optional_param('page', 0, PARAM_INT);

if ($g) {
    $gallery = $DB->get_record('lightboxgallery', ['id' => $g], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $gallery->course], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('lightboxgallery', $gallery->id, $course->id, false, MUST_EXIST);
    require_login($course, true, $cm);
    $context = context_module::instance($cm->id);
    $title = $gallery->name;
} else {
    $course = $DB->get_record('course', ['id' => $cid], '*', MUST_EXIST);
    require_login($course, true);
    $context = context_course::instance($course->id);
    $title = get_string('modulenameplural', 'lightboxgallery');
}

// The galleries in this course that the user can see, keyed by instance id.
$cms = [];
foreach (get_fast_modinfo($course)->get_instances_of('lightboxgallery') as $instanceid => $instancecm) {
    if ($instancecm->uservisible) {
        $cms[$instanceid] = $instancecm;
    }
}

$event = \mod_lightboxgallery\event\gallery_searched::create([
    'context' => $context,
    'other' => [
        'searchterm' => $search,
        'lightboxgalleryid' => $g,
    ],
]);
$event->trigger();

$pageurl = new moodle_url('/mod/lightboxgallery/search.php', ['id' => $course->id, 'gallery' => $g, 'search' => $search]);
$PAGE->set_url($pageurl, ['page' => $page]);
$PAGE->set_title($title);
$PAGE->set_heading($course->shortname);
$PAGE->requires->js_call_amd('mod_lightboxgallery/lightbox', 'init');

echo $OUTPUT->header();

if ($cms) {
    $galleries = [];
    foreach ($cms as $instanceid => $instancecm) {
        $galleries[] = ['id' => $instanceid, 'name' => $instancecm->get_formatted_name(), 'selected' => $instanceid == $g];
    }
    echo $OUTPUT->render_from_template('mod_lightboxgallery/search_form', [
        'action' => (new moodle_url('/mod/lightboxgallery/search.php'))->out(false),
        'courseid' => $course->id,
        'search' => $search,
        'galleries' => $galleries,
    ]);
}

$galleryids = $g ? array_intersect([$g], array_keys($cms)) : array_keys($cms);
if ($search === '' || !$galleryids) {
    echo $OUTPUT->footer();
    die();
}

[$total, $pageresults] = lightboxgallery_search_images(
    $galleryids,
    $search,
    $page * LIGHTBOXGALLERY_SEARCH_PERPAGE,
    LIGHTBOXGALLERY_SEARCH_PERPAGE
);

if (!$pageresults) {
    echo $OUTPUT->box(get_string('errornosearchresults', 'lightboxgallery'));
    echo $OUTPUT->footer();
    die();
}

// Load the galleries and the captions and tags for this page's images in one go.
$pagegalleryids = array_unique(array_column($pageresults, 'gallery'));
$galleryrecords = $DB->get_records_list('lightboxgallery', 'id', $pagegalleryids);
[$gallerysql, $galleryparams] = $DB->get_in_or_equal($pagegalleryids, SQL_PARAMS_NAMED, 'g');
[$imagesql, $imageparams] = $DB->get_in_or_equal(array_unique(array_column($pageresults, 'image')), SQL_PARAMS_NAMED, 'i');
$metadata = [];
$metarecords = $DB->get_records_select(
    'lightboxgallery_image_meta',
    "gallery $gallerysql AND image $imagesql",
    $galleryparams + $imageparams
);
foreach ($metarecords as $metarecord) {
    $metadata[$metarecord->gallery][$metarecord->image][] = $metarecord;
}

$fs = get_file_storage();
echo $OUTPUT->box_start('generalbox lightbox-gallery clearfix autoresize');
foreach ($pageresults as $result) {
    $imgcm = $cms[$result->gallery];
    $storedfile = $fs->get_file($imgcm->context->id, 'mod_lightboxgallery', 'gallery_images', 0, '/', $result->image);
    if ($storedfile) {
        $image = new lightboxgallery_image(
            $storedfile,
            $galleryrecords[$result->gallery],
            $imgcm,
            $metadata[$result->gallery][$result->image] ?? []
        );
        echo $image->get_image_display_html();
    }
}
echo $OUTPUT->box_end();

echo $OUTPUT->paging_bar($total, $page, LIGHTBOXGALLERY_SEARCH_PERPAGE, $pageurl);

echo $OUTPUT->footer();
