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
 * Handles all the RSS related tasks for the module
 *
 * @package   mod_lightboxgallery
 * @copyright Copyright (c) 2021 Open LMS (https://www.openlms.net)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/imageclass.php');

/**
 * Returns the path to the cached rss feed contents. Creates/updates the cache if necessary.
 *
 * Access to the course and activity has already been checked by rss/file.php.
 *
 * The feed is cached under a fingerprint of everything it shows, so it is only rebuilt when
 * the gallery, its images, thumbnails or captions change, or for a new language.
 *
 * @param context $context the context
 * @param array $args the arguments received in the url
 * @return string|null the full path to the cached RSS feed file. Null if there is a problem.
 */
function lightboxgallery_rss_get_feed($context, $args) {
    global $DB;

    if (empty(get_config('lightboxgallery', 'enablerssfeeds'))) {
        return null;
    }

    $galleryid = clean_param($args[3], PARAM_INT);
    $cm = get_coursemodule_from_instance('lightboxgallery', $galleryid, 0, false, IGNORE_MISSING);
    if (!$cm || $context->id != context_module::instance($cm->id)->id) {
        return null;
    }

    $gallery = $DB->get_record('lightboxgallery', ['id' => $galleryid], '*', MUST_EXIST);
    if (empty($gallery->rss)) {
        return null;
    }

    $fs = get_file_storage();
    $images = [];
    foreach ($fs->get_area_files($context->id, 'mod_lightboxgallery', 'gallery_images', 0, 'filename', false) as $file) {
        if (file_mimetype_in_typegroup($file->get_mimetype(), 'web_image')) {
            $images[$file->get_filename()] = $file;
        }
    }
    $thumbnails = [];
    foreach ($fs->get_area_files($context->id, 'mod_lightboxgallery', 'gallery_thumbs', 0, 'filename', false) as $file) {
        // Thumbnails have ".png" suffixed in the filepool.
        $thumbnails[substr($file->get_filename(), 0, -4)] = $file;
    }
    $captions = $DB->get_records_menu(
        'lightboxgallery_image_meta',
        ['metatype' => 'caption', 'gallery' => $gallery->id],
        '',
        'image, description'
    );

    $filename = rss_get_file_name($gallery, lightboxgallery_rss_fingerprint($gallery, $images, $thumbnails, $captions));
    $cachedfilepath = rss_get_file_full_name('mod_lightboxgallery', $filename);
    if (file_exists($cachedfilepath)) {
        return $cachedfilepath;
    }

    $articles = '';
    foreach ($images as $imagename => $file) {
        $description = $captions[$imagename] ?? $imagename;
        $image = new lightboxgallery_image($file, $gallery, $cm, null, $thumbnails[$imagename] ?? false, false);

        $articles .= rss_start_tag('item', 2, true);
        $articles .= rss_full_tag('title', 3, false, $imagename);
        $articles .= rss_full_tag('link', 3, false, $image->get_image_url()->out(false));
        $articles .= rss_full_tag('guid', 3, false, $file->get_pathnamehash(), ['isPermaLink' => 'false']);
        $articles .= rss_full_tag('media:description', 3, false, $description);
        if ($thumbnailurl = $image->get_thumbnail_url()) {
            $articles .= rss_full_tag('media:thumbnail', 3, false, '', ['url' => $thumbnailurl]);
        }
        $articles .= rss_full_tag(
            'media:content',
            3,
            false,
            '',
            ['url' => $image->get_image_url(), 'type' => $file->get_mimetype()]
        );
        $articles .= rss_end_tag('item', 2, true);
    }

    $header = lightboxgallery_rss_header(
        format_string($gallery->name, true),
        (new moodle_url('/mod/lightboxgallery/view.php', ['id' => $cm->id]))->out(false),
        format_string($gallery->intro, true)
    );
    if (!rss_save_file('mod_lightboxgallery', $filename, $header . $articles . rss_standard_footer())) {
        return null;
    }

    // Remove this gallery's outdated copies.
    foreach (glob(dirname($cachedfilepath) . '/' . $gallery->id . '_*.xml') ?: [] as $oldfile) {
        if ($oldfile !== $cachedfilepath) {
            @unlink($oldfile);
        }
    }

    return $cachedfilepath;
}

/**
 * A fingerprint of everything a gallery's feed shows, used to name its cached copy.
 *
 * @param stdClass $gallery
 * @param stored_file[] $images The gallery's images, keyed by filename.
 * @param stored_file[] $thumbnails The images' thumbnails, keyed by image filename.
 * @param string[] $captions The images' captions, keyed by image filename.
 * @return string
 */
function lightboxgallery_rss_fingerprint($gallery, array $images, array $thumbnails, array $captions) {
    $parts = [$gallery->timemodified, current_language(), date('Y')];
    foreach ($images as $imagename => $file) {
        $parts[] = $imagename . ':' . $file->get_contenthash() . ':' . $file->get_timemodified();
        if (isset($thumbnails[$imagename])) {
            $parts[] = 'thumb:' . $thumbnails[$imagename]->get_timemodified();
        }
    }
    ksort($captions);
    $parts[] = json_encode($captions);

    return implode('|', $parts);
}

/**
 * This function returns the header for the RSS feed.
 *
 * @param string|null $title
 * @param string|null $link
 * @param string|null $description
 * @return string
 * @throws moodle_exception
 */
function lightboxgallery_rss_header($title = null, $link = null, $description = null) {
    global $CFG, $OUTPUT;

    $site = get_site();

    // Calculate title, link and description.
    if (empty($title)) {
        $title = format_string($site->fullname);
    }
    if (empty($link)) {
        $link = $CFG->wwwroot;
    }
    if (empty($description)) {
        $description = $site->summary;
    }

    // XML headers.
    $result = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $result .= "<rss version=\"2.0\" xmlns:media=\"http://search.yahoo.com/mrss/\" " .
                "xmlns:atom=\"http://www.w3.org/2005/Atom\">\n";

    // Open the channel.
    $result .= rss_start_tag('channel', 1, true);

    // Write channel info.
    $result .= rss_full_tag('title', 2, false, strip_tags($title));
    $result .= rss_full_tag('link', 2, false, $link);
    $result .= rss_full_tag('description', 2, false, $description);
    $result .= rss_full_tag('generator', 2, false, 'Moodle');
    $result .= rss_full_tag('language', 2, false, substr(current_language(), 0, 2));
    $today = getdate();
    $result .= rss_full_tag('copyright', 2, false, "\u{00A9} " . $today['year'] . ' ' . format_string($site->fullname));

    // Write image info.
    $rsspix = $OUTPUT->image_url('i/rsssitelogo');

    // Write the info.
    $result .= rss_start_tag('image', 2, true);
    $result .= rss_full_tag('url', 3, false, $rsspix);
    $result .= rss_full_tag('title', 3, false, 'moodle');
    $result .= rss_full_tag('link', 3, false, $CFG->wwwroot);
    $result .= rss_full_tag('width', 3, false, '140');
    $result .= rss_full_tag('height', 3, false, '35');
    $result .= rss_end_tag('image', 2, true);

    return $result;
}
