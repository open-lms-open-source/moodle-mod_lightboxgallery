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
 * A comment on a gallery, with its author and, for editors, a delete link.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gallery_comment implements named_templatable, renderable {
    /**
     * Constructor.
     *
     * @param \stdClass $comment The lightboxgallery_comments record.
     * @param \stdClass $user The comment's author, with the user picture fields.
     * @param \context_module $context The gallery's context.
     */
    public function __construct(
        /** @var \stdClass The lightboxgallery_comments record. */
        protected \stdClass $comment,
        /** @var \stdClass The comment's author. */
        protected \stdClass $user,
        /** @var \context_module The gallery's context. */
        protected \context_module $context
    ) {
    }

    /**
     * Get the template that renders this.
     *
     * @param renderer_base $renderer
     * @return string
     */
    public function get_template_name(renderer_base $renderer): string {
        return 'mod_lightboxgallery/gallery_comment';
    }

    /**
     * Export the comment's data for its template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $courseid = $this->context->get_course_context()->instanceid;

        $deleteurl = null;
        if (has_capability('mod/lightboxgallery:edit', $this->context)) {
            $deleteurl = (new \moodle_url(
                '/mod/lightboxgallery/comment.php',
                ['id' => $this->comment->gallery, 'delete' => $this->comment->id]
            ))->out(false);
        }

        return [
            'id' => $this->comment->id,
            'userpicture' => $output->user_picture($this->user, ['courseid' => $courseid]),
            'fullname' => fullname($this->user, has_capability('moodle/site:viewfullnames', $this->context)),
            'profileurl' => (new \moodle_url('/user/view.php', ['id' => $this->user->id, 'course' => $courseid]))->out(false),
            'date' => userdate($this->comment->timemodified),
            'content' => format_text($this->comment->commenttext, FORMAT_MOODLE, ['context' => $this->context]),
            'deleteurl' => $deleteurl,
        ];
    }
}
