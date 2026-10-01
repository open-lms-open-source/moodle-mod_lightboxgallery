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

namespace mod_lightboxgallery\output;

use core\output\named_templatable;
use core\output\renderable;
use core\output\renderer_base;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/lightboxgallery/locallib.php');
require_once($CFG->dirroot . '/mod/lightboxgallery/imageclass.php');

/**
 * One image's tile in a gallery: its thumbnail, caption, details and, when editing, its edit menu.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class image_tile implements named_templatable, renderable {
    /**
     * Constructor.
     *
     * @param \lightboxgallery_image $image
     * @param \stdClass $gallery The gallery's record.
     * @param bool $editing Whether to show the edit menu.
     * @param int $page The page of the gallery being shown, so editing returns to it.
     */
    public function __construct(
        /** @var \lightboxgallery_image The image. */
        protected \lightboxgallery_image $image,
        /** @var \stdClass The gallery's record. */
        protected \stdClass $gallery,
        /** @var bool Whether to show the edit menu. */
        protected bool $editing = false,
        /** @var int The page of the gallery being shown. */
        protected int $page = 0
    ) {
    }

    /**
     * Get the template that renders this.
     *
     * @param renderer_base $renderer
     * @return string
     */
    public function get_template_name(renderer_base $renderer): string {
        return 'mod_lightboxgallery/image_tile';
    }

    /**
     * Export the tile's data for its template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $file = $this->image->get_stored_file();

        // The tile may show a shortened caption; the viewer, and screen readers, get it in full.
        $fullcaption = $this->gallery->captionpos == LIGHTBOXGALLERY_POS_HID ? '' : $this->image->get_image_caption();
        $caption = $fullcaption;
        if (!$this->gallery->captionfull) {
            $caption = lightboxgallery_resize_text($caption, \lightboxgallery_image::CAPTION_PREVIEW_LENGTH);
        }

        $thumbnailurl = $this->image->get_thumbnail_url();
        $data = [
            'imageurl' => $this->image->get_image_url()->out(false),
            'thumbnailurl' => $thumbnailurl ? $thumbnailurl->out(false) : null,
            'pending' => $this->image->is_thumbnail_pending(),
            'caption' => $caption,
            'fullcaption' => $fullcaption,
            'label' => $fullcaption !== '' ? $fullcaption : $file->get_filename(),
            'captiontop' => $this->gallery->captionpos == LIGHTBOXGALLERY_POS_TOP,
            'width' => \lightboxgallery_image::THUMBNAIL_WIDTH,
            'height' => \lightboxgallery_image::THUMBNAIL_HEIGHT,
            'extinfo' => null,
            'editmenu' => null,
        ];

        if ($this->gallery->extinfo) {
            $data['extinfo'] = [
                'modified' => userdate($file->get_timemodified(), get_string('strftimedatetimeshort', 'langconfig')),
                'size' => display_size($file->get_filesize()),
                'dimensions' => $this->image->width . 'x' . $this->image->height . 'px',
            ];
        }

        if ($this->editing) {
            $options = [];
            foreach (lightboxgallery_edit_types(false, $this->image) as $value => $label) {
                $options[] = ['value' => $value, 'label' => $label];
            }
            $data['editmenu'] = [
                'action' => (new \moodle_url('/mod/lightboxgallery/imageedit.php'))->out(false),
                'menuid' => \html_writer::random_id('lightboxgallery-edit-'),
                'cmid' => $this->image->get_cmid(),
                'image' => $file->get_filename(),
                'page' => $this->page,
                'options' => $options,
            ];
        }

        return $data;
    }
}
