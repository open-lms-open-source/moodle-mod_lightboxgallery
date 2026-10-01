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
 * Internal library of functions for module lightboxgallery
 *
 * All the newmodule specific functions, needed to implement the module
 * logic, should go here. Never include this file from your lib.php!
 *
 * @package   mod_lightboxgallery
 * @copyright 2011 NetSpot Pty Ltd
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(dirname(__FILE__) . '/lib.php');
require_once("$CFG->libdir/filelib.php");

// How many characters of a comment to show in recent activity.
define('LIGHTBOXGALLERY_COMMENT_PREVIEW_LENGTH', 20);
// How many comments to show on each page of a gallery.
define('LIGHTBOXGALLERY_COMMENTS_PERPAGE', 25);

// Values of the gallery's autoresize setting: resize images to fit the screen, when they're uploaded, or both.
define('LIGHTBOXGALLERY_AUTO_RESIZE_SCREEN', 1);
define('LIGHTBOXGALLERY_AUTO_RESIZE_UPLOAD', 2);
define('LIGHTBOXGALLERY_AUTO_RESIZE_BOTH', 3);

/**
 * Add a set of uploaded files to the gallery.
 *
 * @param array $files A list of stored_file objects.
 * @param context $context
 * @param cm_info $cm
 * @param stdClass $gallery
 * @param int $resize
 * @return void
 */
function lightboxgallery_add_images($files, $context, $cm, $gallery, $resize = 0) {
    require_once(dirname(__FILE__) . '/imageclass.php');

    $fs = get_file_storage();

    $images = [];
    $fs->delete_area_files($context->id, 'mod_lightboxgallery', 'unpacktemp', 0);
    foreach ($files as $storedfile) {
        if ($storedfile->get_mimetype() == 'application/zip') {
            // Unpack each zip into its own folder, alongside any other uploaded files.
            $packer = get_file_packer('application/zip');
            $folder = '/' . $storedfile->get_id() . '/';
            $storedfile->extract_to_storage($packer, $context->id, 'mod_lightboxgallery', 'unpacktemp', 0, $folder);
            $images = array_merge($images, array_values($fs->get_directory_files($context->id, 'mod_lightboxgallery',
                'unpacktemp', 0, $folder, true, false)));
            $storedfile->delete();
        } else {
            $images[] = $storedfile;
        }
    }

    foreach ($images as $storedfile) {
        if ($storedfile->is_valid_image()) {
            $filename = $storedfile->get_filename();
            $fileinfo = [
                'contextid'     => $context->id,
                'component'     => 'mod_lightboxgallery',
                'filearea'      => 'gallery_images',
                'itemid'        => 0,
                'filepath'      => '/',
                'filename'      => $filename,
            ];
            if (!$fs->get_file($context->id, 'mod_lightboxgallery', 'gallery_images', 0, '/', $filename)) {
                $storedfile = $fs->create_file_from_storedfile($fileinfo, $storedfile);
                $image = new lightboxgallery_image($storedfile, $gallery, $cm);

                if ($resize > 0) {
                    $resizeoptions = lightboxgallery_resize_options();
                    [$width, $height] = explode('x', $resizeoptions[$resize]);
                    // Uploads are only ever shrunk to fit; small images keep their size.
                    $image->resize_image($width, $height, false);
                }

                $image->set_caption($filename);
            }
        }
    }
    $fs->delete_area_files($context->id, 'mod_lightboxgallery', 'unpacktemp', 0);
}

/**
 * Get the list of editing plugins.
 *
 * @param bool|null $showall
 * @param stdClass|null $image
 * @return array
 * @throws coding_exception
 * @throws dml_exception
 */
function lightboxgallery_edit_types($showall = false, $image = null) {
    $result = [];

    $disabledplugins = explode(',', get_config('lightboxgallery', 'disabledplugins'));

    $edittypes = get_list_of_plugins('mod/lightboxgallery/edit');
    if ($image !== null && !$showall) {
        $edittypes = array_intersect($image->get_editing_options(), $edittypes);
    }

    foreach ($edittypes as $edittype) {
        if ($showall || !in_array($edittype, $disabledplugins)) {
            $result[$edittype] = get_string('edit_' . $edittype, 'lightboxgallery');
        }
    }

    return $result;
}

/**
 * Print the tags for a gallery.
 *
 * @param string $heading
 * @param array $tags
 * @param int $courseid
 * @param int $galleryid
 * @return void
 * @throws \core\exception\moodle_exception
 * @throws coding_exception
 */
function lightboxgallery_print_tags($heading, $tags, $courseid, $galleryid) {
    global $OUTPUT;

    echo $OUTPUT->box_start();

    echo '<form action="search.php" style="float: right; margin-left: 4px;">' .
         ' <fieldset class="invisiblefieldset">' .
         '  <input type="hidden" name="id" value="' . $courseid . '" />' .
         '  <input type="hidden" name="gallery" value="' . $galleryid . '" />' .
         '  <input type="text" name="search" size="8" />' .
         '  <input type="submit" class="btn btn-secondary" value="' . get_string('search') . '" />' .
         ' </fieldset>' .
         '</form>' .
         $heading . ': ';

    $tagarray = [];
    foreach ($tags as $tag) {
        $tagparams = ['id' => $courseid, 'gallery' => $galleryid, 'search' => stripslashes($tag->description)];
        $tagurl = new moodle_url('/mod/lightboxgallery/search.php', $tagparams);
        $tagarray[] = html_writer::link($tagurl, s($tag->description), ['class' => 'taglink']);
    }

    echo implode(', ', $tagarray);

    echo $OUTPUT->box_end();
}

/**
 * Get the list of resize options.
 *
 * @return string[]
 */
function lightboxgallery_resize_options() {
    return [1 => '1280x1024', 2 => '1024x768', 3 => '800x600', 4 => '640x480'];
}

/**
 * Get the list of thumbnail sizes.
 *
 * @param int $courseid
 * @param stdClass $gallery
 * @param stdClass|null $newimage
 * @return string
 * @throws coding_exception
 * @throws file_exception
 * @throws stored_file_creation_exception
 */
function lightboxgallery_index_thumbnail($courseid, $gallery, $newimage = null) {
    global $CFG, $OUTPUT;

    require_once(dirname(__FILE__) . '/imageclass.php');
    $cm = get_coursemodule_from_instance("lightboxgallery", $gallery->id, $courseid);
    $context = context_module::instance($cm->id);

    $imageid = 'Gallery Index Image';

    $fs = get_file_storage();
    $storedfile = $fs->get_file($context->id, 'mod_lightboxgallery', 'gallery_index', '0', '/', 'index.png');

    if (!is_null($newimage)) {
        // Replace the index with the chosen image.
        if (is_object($storedfile)) {
            $storedfile->delete();
        }
        $index = $newimage->create_index();
    } else if (is_object($storedfile)) {
        $index = $storedfile;
    } else if ($file = lightboxgallery_first_indexable_image($context)) {
        if (lightboxgallery_image::claim_thumbnail_budget()) {
            $image = new lightboxgallery_image($file, $gallery, $cm);
            $index = $image->create_index();
        } else {
            // Too many images to process in this request; show the default picture until the task has run.
            lightboxgallery_image::queue_thumbnail_generation($cm->id);
            return '<img src="' . $OUTPUT->image_url('index', 'mod_lightboxgallery') . '" alt="" id="' . $imageid . '" />';
        }
    } else {
        $fileinfo = [
            'contextid' => $context->id,
            'component' => 'mod_lightboxgallery',
            'filearea'  => 'gallery_index',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'index.png',
        ];
        $index = $fs->create_file_from_pathname($fileinfo, $CFG->dirroot . '/mod/lightboxgallery/pix/index.png');
    }

    $path = moodle_url::make_pluginfile_url(
        $context->id,
        'mod_lightboxgallery',
        'gallery_index',
        $index->get_itemid(),
        $index->get_filepath(),
        $index->get_filename(),
    );
    $path->param('mtime', $index->get_timemodified());

    return '<img src="' . $path . '" alt="" ' . (! empty($imageid) ? 'id="' . $imageid . '"' : '' )  . ' />';
}


/**
 * Add each image's IPTC keywords to it as tags.
 *
 * Only JPEGs carry IPTC keywords. Each one is copied to a temporary file to read them,
 * and the copy is always removed. Keywords already on the image aren't added again.
 *
 * @param stdClass $gallery
 * @param context_module $context The gallery's context.
 * @return stdClass With tags, the number of tags added, and images, the number of files looked at.
 */
function lightboxgallery_import_iptc_tags($gallery, context_module $context) {
    global $DB;

    $storedfiles = get_file_storage()->get_area_files($context->id, 'mod_lightboxgallery', 'gallery_images', false,
        'itemid', false);

    $result = new stdClass();
    $result->tags = 0;
    $result->images = count($storedfiles);

    foreach ($storedfiles as $storedfile) {
        if ($storedfile->get_mimetype() != 'image/jpeg' || !$storedfile->is_valid_image()) {
            continue;
        }

        $path = $storedfile->copy_content_to_temp();
        try {
            $info = [];
            getimagesize($path, $info);
        } finally {
            @unlink($path);
        }
        if (!isset($info['APP13']) || !($iptc = iptcparse($info['APP13'])) || !isset($iptc['2#025'])) {
            continue;
        }

        $keywords = $iptc['2#025'];
        sort($keywords);
        foreach ($keywords as $tag) {
            // Keywords are UTF-8 or, in older files, Latin-1.
            if (!mb_check_encoding($tag, 'UTF-8')) {
                $tag = mb_convert_encoding($tag, 'UTF-8', 'ISO-8859-1');
            }
            $tag = trim(strip_tags(clean_param($tag, PARAM_TAG)));
            if ($tag === '') {
                continue;
            }

            $select = "gallery = :gallery AND image = :image
                       AND metatype = :metatype AND " . $DB->sql_compare_text('description', 100) . ' = :description';
            $record = [
                'gallery' => $gallery->id,
                'image' => $storedfile->get_filename(),
                'metatype' => 'tag',
                'description' => $tag,
            ];
            if (!$DB->record_exists_select('lightboxgallery_image_meta', $select, $record)) {
                $DB->insert_record('lightboxgallery_image_meta', $record);
                $result->tags++;
            }
        }
    }

    return $result;
}

/**
 * Find images whose captions or tags contain a search term.
 *
 * Each image is returned once, however many of its captions or tags match. Access to the
 * galleries must already have been checked.
 *
 * @param int[] $galleryids The galleries to search.
 * @param string $search The text to look for, matched literally and ignoring case.
 * @param int $offset How many matches to skip, for paging.
 * @param int $limit The most matches to return.
 * @return array [int $total, stdClass[] $matches], where each match has gallery and image properties,
 *     ordered by gallery then image.
 */
function lightboxgallery_search_images(array $galleryids, string $search, int $offset, int $limit): array {
    global $DB;

    if (!$galleryids || $search === '') {
        return [0, []];
    }

    [$insql, $inparams] = $DB->get_in_or_equal($galleryids, SQL_PARAMS_NAMED);
    $params = ['search' => '%' . $DB->sql_like_escape($search) . '%'] + $inparams;
    $matches = "SELECT DISTINCT gallery, image
                  FROM {lightboxgallery_image_meta}
                 WHERE " . $DB->sql_like('description', ':search', false) . " AND gallery $insql";

    $total = $DB->count_records_sql("SELECT COUNT(1) FROM ($matches) matches", $params);
    $results = [];
    $recordset = $DB->get_recordset_sql("$matches ORDER BY gallery, image", $params, $offset, $limit);
    foreach ($recordset as $record) {
        $results[] = $record;
    }
    $recordset->close();

    return [$total, $results];
}

/**
 * Find the first image in a gallery that an index picture can be made from.
 *
 * @param context_module $context The gallery's context.
 * @return stored_file|null
 */
function lightboxgallery_first_indexable_image(context_module $context) {
    $files = get_file_storage()->get_area_files($context->id, 'mod_lightboxgallery', 'gallery_images', 0,
        'itemid, filepath, filename', false);
    foreach ($files as $file) {
        $mimetype = $file->get_mimetype();
        if (file_mimetype_in_typegroup($mimetype, 'web_image') && $mimetype != 'image/svg+xml') {
            return $file;
        }
    }
    return null;
}

/**
 * File browsing support class
 */
class lightboxgallery_content_file_info extends file_info_stored {
    /**
     * Get the parent file.
     *
     * @return file_info|null
     */
    public function get_parent() {
        if ($this->lf->get_filepath() === '/' && $this->lf->get_filename() === '.') {
            return $this->browser->get_file_info($this->context);
        }
        return parent::get_parent();
    }

    /**
     * Get the name of the file.
     *
     * @return string
     */
    public function get_visible_name() {
        if ($this->lf->get_filepath() === '/' && $this->lf->get_filename() === '.') {
            return $this->topvisiblename;
        }
        return parent::get_visible_name();
    }
}
