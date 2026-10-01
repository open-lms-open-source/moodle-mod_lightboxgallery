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

/**
 * A gallery's tags, each linking to a search for it, with a box to search the gallery.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class popular_tags implements named_templatable, renderable {
    /**
     * Constructor.
     *
     * @param string $heading What to call the list of tags.
     * @param \stdClass[] $tags The tags, each with a description.
     * @param int $courseid
     * @param int $galleryid
     */
    public function __construct(
        /** @var string What to call the list of tags. */
        protected string $heading,
        /** @var \stdClass[] The tags. */
        protected array $tags,
        /** @var int The course id. */
        protected int $courseid,
        /** @var int The gallery id. */
        protected int $galleryid
    ) {
    }

    /**
     * Get the template that renders this.
     *
     * @param renderer_base $renderer
     * @return string
     */
    public function get_template_name(renderer_base $renderer): string {
        return 'mod_lightboxgallery/popular_tags';
    }

    /**
     * Export the tags' data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $searchurl = new \moodle_url('/mod/lightboxgallery/search.php');
        $tags = [];
        foreach (array_values($this->tags) as $index => $tag) {
            $url = new \moodle_url($searchurl, ['id' => $this->courseid, 'gallery' => $this->galleryid,
                'search' => $tag->description]);
            $tags[] = ['name' => $tag->description, 'url' => $url->out(false), 'first' => $index === 0];
        }

        return [
            'heading' => $this->heading,
            'tags' => $tags,
            'searchaction' => $searchurl->out(false),
            'searchid' => \html_writer::random_id('lightboxgallery-search-'),
            'courseid' => $this->courseid,
            'galleryid' => $this->galleryid,
        ];
    }
}
