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

namespace mod_lightboxgallery;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/lightboxgallery/lib.php');

/**
 * Tests for listing gallery comments a page at a time.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_get_comments')]
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_comment_url')]
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_print_comment')]
final class comments_test extends \advanced_testcase {
    /** @var \stdClass The gallery, with its cmid. */
    private $gallery;

    /** @var \stdClass[] The comments, oldest first. */
    private $comments = [];

    /**
     * Create a gallery with a comment from each of several users.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->gallery = $this->getDataGenerator()->create_module('lightboxgallery',
            ['course' => $course->id, 'comments' => 1]);

        // Two comments share a time, so the order also depends on their ids.
        $times = [100, 200, 200, 300, 400];
        foreach ($times as $i => $time) {
            $user = $this->getDataGenerator()->create_user(['firstname' => 'Commenter', 'lastname' => (string) $i]);
            $comment = (object) ['gallery' => $this->gallery->id, 'userid' => $user->id,
                'commenttext' => "Comment $i", 'timemodified' => $time];
            $comment->id = $DB->insert_record('lightboxgallery_comments', $comment);
            $this->comments[] = $comment;
        }
    }

    /**
     * Comments come a page at a time, oldest first, with their authors.
     */
    public function test_get_comments_pages(): void {
        [$total, $first] = lightboxgallery_get_comments($this->gallery->id, 0, 2);
        [, $last] = lightboxgallery_get_comments($this->gallery->id, 2, 2);

        $this->assertSame(5, $total);
        $this->assertSame(['Comment 0', 'Comment 1'], array_column($first, 'commenttext'));
        $this->assertSame(['Comment 4'], array_column($last, 'commenttext'));
        $this->assertEquals($this->comments[0]->userid, $first[0]->user->id);
        $this->assertSame('Commenter 0', fullname($first[0]->user));
    }

    /**
     * A comment's link points at the page of comments it's on.
     */
    public function test_comment_url(): void {
        $perpage = LIGHTBOXGALLERY_COMMENTS_PERPAGE;
        $url = lightboxgallery_comment_url($this->comments[2], $this->gallery->cmid);
        $this->assertStringEndsWith('#c' . $this->comments[2]->id, $url->out(false));
        $this->assertNull($url->get_param('cpage'));

        // Push the last comment onto the second page.
        global $DB;
        for ($i = 0; $i < $perpage; $i++) {
            $DB->insert_record('lightboxgallery_comments',
                ['gallery' => $this->gallery->id, 'userid' => 2, 'commenttext' => 'Early', 'timemodified' => 50]);
        }
        $url = lightboxgallery_comment_url($this->comments[4], $this->gallery->cmid);
        $this->assertEquals(1, $url->get_param('cpage'));
    }

    /**
     * Printing a page of comments with their authors doesn't look each author up again.
     */
    public function test_print_comments_without_user_queries(): void {
        global $DB;
        $context = \context_module::instance($this->gallery->cmid);
        [, $comments] = lightboxgallery_get_comments($this->gallery->id, 0, 5);

        // Warm up the per-request caches that don't depend on the number of comments.
        ob_start();
        lightboxgallery_print_comment($comments[0], $context, $comments[0]->user);
        $reads = $DB->perf_get_reads();
        foreach ($comments as $comment) {
            lightboxgallery_print_comment($comment, $context, $comment->user);
        }
        $html = ob_get_clean();

        $this->assertSame($reads, $DB->perf_get_reads());
        $this->assertStringContainsString('Commenter 4', $html);
    }
}
