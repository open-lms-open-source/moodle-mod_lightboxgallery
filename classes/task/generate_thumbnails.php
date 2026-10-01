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

namespace mod_lightboxgallery\task;

/**
 * Generates a gallery's missing thumbnails and index image.
 *
 * Pages only generate a few thumbnails themselves (see lightboxgallery_image::SYNC_THUMBNAIL_LIMIT)
 * and queue this task for the rest.
 *
 * Custom data: cmid, the gallery's course module id.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_thumbnails extends \core\task\adhoc_task {
    /**
     * Get the task's name, as shown to admins.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskgeneratethumbnails', 'mod_lightboxgallery');
    }

    /**
     * Generate every missing thumbnail in the gallery, then its index image.
     *
     * An image that can't be processed is reported and skipped, so one bad file doesn't
     * stop the others or make the task retry forever.
     *
     * @return void
     */
    public function execute() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/lightboxgallery/locallib.php');
        require_once($CFG->dirroot . '/mod/lightboxgallery/imageclass.php');

        $cmid = $this->get_custom_data()->cmid;
        $cm = get_coursemodule_from_id('lightboxgallery', $cmid);
        if (!$cm || !$gallery = $DB->get_record('lightboxgallery', ['id' => $cm->instance])) {
            mtrace("Gallery with course module id {$cmid} no longer exists; nothing to do.");
            return;
        }

        \lightboxgallery_image::set_thumbnail_budget(null);
        try {
            $this->generate($gallery, $cm);
        } finally {
            \lightboxgallery_image::set_thumbnail_budget(\lightboxgallery_image::SYNC_THUMBNAIL_LIMIT);
        }
    }

    /**
     * Generate the gallery's missing thumbnails and index image.
     *
     * @param \stdClass $gallery
     * @param \stdClass $cm
     * @return void
     */
    private function generate(\stdClass $gallery, \stdClass $cm): void {
        $context = \context_module::instance($cm->id);
        $files = get_file_storage()->get_area_files(
            $context->id,
            'mod_lightboxgallery',
            'gallery_images',
            0,
            'filename',
            false
        );
        foreach ($files as $file) {
            if (!file_mimetype_in_typegroup($file->get_mimetype(), 'web_image')) {
                continue;
            }
            try {
                // Constructing the image generates its thumbnail if it's missing.
                new \lightboxgallery_image($file, $gallery, $cm, null, false, false);
            } catch (\Throwable $e) {
                mtrace("Couldn't make a thumbnail for {$file->get_filename()} in gallery {$gallery->id}: " .
                    $e->getMessage());
            }
        }

        try {
            lightboxgallery_index_thumbnail($cm->course, $gallery);
        } catch (\Throwable $e) {
            mtrace("Couldn't make the index image for gallery {$gallery->id}: " . $e->getMessage());
        }
    }
}
