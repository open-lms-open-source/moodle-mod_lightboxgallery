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

use lightboxgallery_image;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/rsslib.php');
require_once($CFG->dirroot . '/mod/lightboxgallery/rsslib.php');

/**
 * Tests for the gallery RSS feed.
 *
 * @package    mod_lightboxgallery
 * @author     Adam Olley <adam.olley@openlms.net>
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_rss_get_feed')]
#[\PHPUnit\Framework\Attributes\CoversFunction('lightboxgallery_rss_header')]
final class rss_test extends \advanced_testcase {
    /** @var \stdClass The gallery record. */
    private $gallery;

    /** @var \stdClass */
    private $cm;

    /** @var \context_module */
    private $context;

    /**
     * Create a gallery with RSS turned on.
     */
    protected function setUp(): void {
        global $CFG, $DB;
        parent::setUp();
        $this->resetAfterTest();
        lightboxgallery_image::set_thumbnail_budget(lightboxgallery_image::SYNC_THUMBNAIL_LIMIT);

        $CFG->enablerssfeeds = 1;
        set_config('enablerssfeeds', 1, 'lightboxgallery');

        $course = $this->getDataGenerator()->create_course();
        $gallery = $this->getDataGenerator()->create_module('lightboxgallery', ['course' => $course->id, 'rss' => 1]);
        $this->gallery = $DB->get_record('lightboxgallery', ['id' => $gallery->id], '*', MUST_EXIST);
        $this->cm = get_coursemodule_from_instance('lightboxgallery', $gallery->id, $course->id, false, MUST_EXIST);
        $this->context = \context_module::instance($this->cm->id);
    }

    /**
     * Add a small PNG to the gallery.
     *
     * @param string $filename
     * @return lightboxgallery_image
     */
    private function add_image(string $filename): lightboxgallery_image {
        $file = $this->getDataGenerator()->get_plugin_generator('mod_lightboxgallery')->create_image($this->gallery, $filename);
        return new lightboxgallery_image($file, $this->gallery, $this->cm);
    }

    /**
     * Ask for the feed as rss/file.php would.
     *
     * @param \context|null $context Defaults to the gallery's context.
     * @return string|null
     */
    private function get_feed(?\context $context = null): ?string {
        $args = [$this->context->id, 'token', 'mod_lightboxgallery', $this->gallery->id, 'rss.xml'];
        return lightboxgallery_rss_get_feed($context ?? $this->context, $args);
    }

    /**
     * The feed is valid XML with one uniquely identified item per image.
     */
    public function test_feed_is_valid(): void {
        $this->add_image('one.png')->set_caption('The first one');
        $this->add_image('two.png');

        $path = $this->get_feed();

        $this->assertNotNull($path);
        $xml = simplexml_load_file($path);
        $this->assertNotFalse($xml, 'The feed should be well-formed XML.');
        $this->assertSame('http://search.yahoo.com/mrss/', $xml->getDocNamespaces()['media']);

        $items = $xml->channel->item;
        $this->assertCount(2, $items);
        $this->assertNotEquals((string) $items[0]->guid, (string) $items[1]->guid);

        $media = $items[0]->children('http://search.yahoo.com/mrss/');
        $this->assertSame('one.png', (string) $items[0]->title);
        $this->assertSame('The first one', (string) $media->description);
        $this->assertStringContainsString('/gallery_thumbs/0/one.png.png', (string) $media->thumbnail->attributes()->url);
        $this->assertStringContainsString("\u{00A9}", (string) $xml->channel->copyright);
    }

    /**
     * An empty gallery still gets a valid feed.
     */
    public function test_empty_gallery_feed(): void {
        $path = $this->get_feed();

        $this->assertNotNull($path);
        $xml = simplexml_load_file($path);
        $this->assertNotFalse($xml);
        $this->assertCount(0, $xml->channel->item);
    }

    /**
     * The feed is served from its cache until something it shows changes.
     */
    public function test_feed_is_cached_until_changed(): void {
        $image = $this->add_image('one.png');
        $path = $this->get_feed();
        file_put_contents($path, 'cached copy');

        // Nothing changed, so the cached copy is served.
        $this->assertSame($path, $this->get_feed());
        $this->assertSame('cached copy', file_get_contents($path));

        // A new caption means a new feed, and the outdated copy is removed.
        $image->set_caption('New caption');
        $newpath = $this->get_feed();
        $this->assertNotSame($path, $newpath);
        $this->assertStringContainsString('New caption', file_get_contents($newpath));
        $this->assertFileDoesNotExist($path);

        // So does a new image.
        $this->add_image('two.png');
        $this->assertNotSame($newpath, $this->get_feed());
    }

    /**
     * No feed when RSS is off for the gallery or the plugin, or the context doesn't match.
     */
    public function test_no_feed_when_not_allowed(): void {
        global $DB;
        $this->assertNull($this->get_feed(\context_course::instance($this->cm->course)));

        $DB->set_field('lightboxgallery', 'rss', 0, ['id' => $this->gallery->id]);
        $this->assertNull($this->get_feed());

        $DB->set_field('lightboxgallery', 'rss', 1, ['id' => $this->gallery->id]);
        set_config('enablerssfeeds', 0, 'lightboxgallery');
        $this->assertNull($this->get_feed());
    }
}
