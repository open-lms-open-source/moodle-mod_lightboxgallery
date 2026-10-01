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
 * Image editing page
 *
 * @package   mod_lightboxgallery
 * @copyright 2011 NetSpot Pty Ltd
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\tabobject;
use core\output\tabtree;

require_once(dirname(__DIR__, 2) . '/config.php');
require_once(__DIR__ . '/locallib.php');
require_once(__DIR__ . '/imageclass.php');

global $DB;

$id = required_param('id', PARAM_INT);
$image = required_param('image', PARAM_PATH);
$tab = optional_param('tab', '', PARAM_TEXT);
$page = optional_param('page', 0, PARAM_INT);

$cm      = get_coursemodule_from_id('lightboxgallery', $id, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$gallery = $DB->get_record('lightboxgallery', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/lightboxgallery:edit', $context);

$PAGE->set_cm($cm);
$PAGE->set_pagelayout('incourse');
$PAGE->set_url('/mod/lightboxgallery/imageedit.php', ['id' => $cm->id, 'image' => $image, 'tab' => $tab, 'page' => $page]);
$PAGE->set_title($gallery->name);
$PAGE->set_heading($course->shortname);
$buttonurl = new moodle_url('/mod/lightboxgallery/view.php', ['id' => $id, 'editing' => 1, 'page' => $page]);
$PAGE->set_button($OUTPUT->single_button($buttonurl, get_string('backtogallery', 'lightboxgallery')));

$fs = get_file_storage();
if (!$storedfile = $fs->get_file($context->id, 'mod_lightboxgallery', 'gallery_images', '0', '/', $image)) {
    throw new \moodle_exception('errornofile', 'lightboxgallery', '', $image);
}
$imageclass = new lightboxgallery_image($storedfile, $gallery, $cm);
$imageclass->ensure_thumbnail();

$edittypes = lightboxgallery_edit_types(false, $imageclass);

$tabs = [];
foreach ($edittypes as $type => $name) {
    $editurl = new moodle_url(
        '/mod/lightboxgallery/imageedit.php',
        ['id' => $cm->id, 'image' => $image, 'page' => $page, 'tab' => $type]
    );
    $tabs[] = new tabobject($type, $editurl, $name);
}

if (!in_array($tab, array_keys($edittypes))) {
    $types = array_keys($edittypes);
    if (isset($types[0])) {
        $tab = $types[0];
    } else {
        notice(get_string('allpluginsdisabled', 'lightboxgallery'), "view.php?id=$id&page=$page");
    }
}

// The tab is one of the tool names listed above, so this names one of the tool classes.
$editclass = '\\mod_lightboxgallery\\local\\edit\\' . $tab;
$editinstance = new $editclass($gallery, $cm, $image, $tab, $page);

if ($editinstance->processing() && confirm_sesskey()) {
    $params = [
        'context' => $context,
        'other' => [
            'imagename' => $image,
            'tab' => $tab,
        ],
    ];
    $event = \mod_lightboxgallery\event\image_updated::create($params);
    $event->add_record_snapshot('course_modules', $cm);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('lightboxgallery', $gallery);
    $event->trigger();

    $editinstance->process_form();
    redirect(new moodle_url(
        '/mod/lightboxgallery/imageedit.php',
        ['id' => $cm->id, 'image' => $editinstance->image, 'tab' => $tab, 'page' => $page]
    ));
}

$thumbnailurl = $imageclass->get_thumbnail_url();
$content = $OUTPUT->render_from_template('mod_lightboxgallery/edit/page', [
    'showthumb' => $editinstance->showthumb,
    'thumbnailurl' => $thumbnailurl ? $thumbnailurl->out(false) : null,
    'caption' => $imageclass->get_image_caption(),
    'tool' => $editinstance->output($imageclass->get_image_caption()),
]);

echo $OUTPUT->header();
echo $OUTPUT->render(new tabtree($tabs, $tab));
echo $content;
echo $OUTPUT->footer();
