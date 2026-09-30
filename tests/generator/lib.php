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
 * mod_lightboxgallery data generator.
 *
 * @package    mod_lightboxgallery
 * @category   test
 * @copyright  Copyright (c) 2021 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * mod_lightboxgallery data generator class.
 *
 * @package    mod_lightboxgallery
 * @category   test
 * @copyright  Copyright (c) 2021 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_lightboxgallery_generator extends testing_module_generator {
    /**
     * Create a new instance of the module.
     *
     * @param stdClass|null $record
     * @param array|null $options
     * @return stdClass
     * @throws coding_exception
     */
    public function create_instance($record = null, ?array $options = null) {
        return parent::create_instance($record, (array)$options);
    }

    /**
     * Add an image to a gallery's image area, without making its thumbnail.
     *
     * @param stdClass $gallery A gallery as returned by create_instance(), with its cmid.
     * @param string $filename
     * @param int $width
     * @param int $height
     * @return stored_file
     */
    public function create_image(stdClass $gallery, string $filename, int $width = 40, int $height = 20): stored_file {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        switch (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            case 'gif':
                imagegif($image);
                break;
            case 'jpg':
            case 'jpeg':
                imagejpeg($image);
                break;
            default:
                imagepng($image);
        }
        $content = ob_get_clean();

        return get_file_storage()->create_file_from_string([
            'contextid' => context_module::instance($gallery->cmid)->id,
            'component' => 'mod_lightboxgallery',
            'filearea' => 'gallery_images',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }
}
