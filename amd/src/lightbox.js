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
 * Shows a gallery's images full size, one at a time, when a thumbnail is clicked.
 *
 * Thumbnails are links to the full image inside a .lightbox-gallery container, with
 * rel="lightbox_gallery" and the caption in their title. All such links on the page form
 * one set to step through. When the container also has the autoresize class, images are
 * scaled down to fit the screen.
 *
 * @module     mod_lightboxgallery/lightbox
 * @copyright  Copyright (c) 2026 Open LMS (https://www.openlms.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import Pending from 'core/pending';
import Templates from 'core/templates';
import {getString} from 'core/str';

const SELECTORS = {
    LINK: '.lightbox-gallery a[rel^="lightbox"]',
    DIALOG: '[data-region="mod_lightboxgallery-lightbox"]',
    IMAGE: '[data-region="image"]',
    LOADING: '[data-region="loading"]',
    CAPTION: '[data-region="caption"]',
    COUNTER: '[data-region="counter"]',
    DOWNLOAD: '[data-region="download"]',
    PREVIOUS: '[data-action="previous"]',
    NEXT: '[data-action="next"]',
    CLOSE: '[data-action="close"]',
};

/** @type {HTMLDialogElement|null} The viewer, once it has been rendered. */
let dialog = null;

/** @type {Array<{url: string, caption: string}>} The images being stepped through. */
let images = [];

/** @type {number} Which of the images is showing. */
let current = 0;

/** @type {number} Increased on every change of image, so a slow load can't replace a newer one. */
let showing = 0;

/** @type {boolean} Whether the click handler is already in place. */
let initialised = false;

/**
 * Get the images a thumbnail belongs with: every gallery link sharing its rel, or just itself.
 *
 * @param {HTMLAnchorElement} link The thumbnail that was clicked.
 * @returns {Array<{url: string, caption: string}>}
 */
const getImageSet = (link) => {
    const links = link.rel === 'lightbox'
        ? [link]
        : [...document.querySelectorAll(SELECTORS.LINK)].filter((other) => other.rel === link.rel);
    return links.map((other) => ({url: other.href, caption: other.title}));
};

/**
 * Load an image, resolving once the browser has it.
 *
 * @param {string} url
 * @returns {Promise<void>}
 */
const loadImage = (url) => new Promise((resolve, reject) => {
    const image = new Image();
    image.onload = () => resolve();
    image.onerror = () => reject(new Error(`Unable to load ${url}`));
    image.src = url;
});

/**
 * Show one of the images.
 *
 * @param {number} index
 * @returns {Promise<void>}
 */
const showImage = async(index) => {
    const pending = new Pending('mod_lightboxgallery/lightbox:showImage');
    const thisshowing = ++showing;
    current = index;
    const {url, caption} = images[index];

    const image = dialog.querySelector(SELECTORS.IMAGE);
    image.hidden = true;
    dialog.querySelector(SELECTORS.LOADING).hidden = false;
    dialog.querySelector(SELECTORS.PREVIOUS).hidden = index === 0;
    dialog.querySelector(SELECTORS.NEXT).hidden = index === images.length - 1;

    const captionnode = dialog.querySelector(SELECTORS.CAPTION);
    captionnode.textContent = caption;
    captionnode.hidden = caption === '';

    const downloadurl = new URL(url);
    downloadurl.searchParams.set('forcedownload', 1);
    dialog.querySelector(SELECTORS.DOWNLOAD).href = downloadurl.toString();

    try {
        const [counter] = await Promise.all([
            getString('imagecountof', 'mod_lightboxgallery', {index: index + 1, total: images.length}),
            loadImage(url),
        ]);
        if (thisshowing !== showing) {
            // Another image was chosen while this one loaded.
            return;
        }
        dialog.querySelector(SELECTORS.COUNTER).textContent = counter;
        image.src = url;
        image.alt = caption;
        image.hidden = false;
        dialog.querySelector(SELECTORS.LOADING).hidden = true;
        preloadNeighbours(index);
    } catch (error) {
        Notification.exception(error);
    } finally {
        pending.resolve();
    }
};

/**
 * Start loading the images either side of one, so stepping to them is quick.
 *
 * @param {number} index
 */
const preloadNeighbours = (index) => {
    [index - 1, index + 1]
        .filter((neighbour) => neighbour >= 0 && neighbour < images.length)
        .forEach((neighbour) => {
            loadImage(images[neighbour].url).catch(() => {
                // It'll be tried again, with an error shown, if it's chosen.
            });
        });
};

/**
 * Step to the previous or next image, if there is one.
 *
 * @param {number} step -1 for the previous image, 1 for the next.
 */
const stepImage = (step) => {
    const index = current + step;
    if (index >= 0 && index < images.length) {
        showImage(index);
    }
};

/**
 * Handle keys pressed while the viewer is open. Escape is handled by the dialog itself.
 *
 * @param {KeyboardEvent} event
 */
const handleKey = (event) => {
    if (event.ctrlKey || event.metaKey || event.altKey) {
        return;
    }
    const key = event.key.toLowerCase();
    if (key === 'arrowleft' || key === 'p') {
        event.preventDefault();
        stepImage(-1);
    } else if (key === 'arrowright' || key === 'n') {
        event.preventDefault();
        stepImage(1);
    } else if (key === 'x' || key === 'o' || key === 'c') {
        event.preventDefault();
        dialog.close();
    }
};

/**
 * Render the viewer and wire up its controls, the first time it's needed.
 *
 * @returns {Promise<HTMLDialogElement>}
 */
const getDialog = async() => {
    if (dialog) {
        return dialog;
    }

    const {html, js} = await Templates.renderForPromise('mod_lightboxgallery/lightbox', {});
    Templates.appendNodeContents(document.body, html, js);
    dialog = document.body.querySelector(SELECTORS.DIALOG);

    dialog.querySelector(SELECTORS.PREVIOUS).addEventListener('click', () => stepImage(-1));
    dialog.querySelector(SELECTORS.NEXT).addEventListener('click', () => stepImage(1));
    dialog.querySelector(SELECTORS.CLOSE).addEventListener('click', () => dialog.close());
    dialog.addEventListener('keydown', handleKey);
    dialog.addEventListener('click', (event) => {
        // A click on the dialog itself, rather than anything in it, is a click on the backdrop.
        if (event.target === dialog) {
            dialog.close();
        }
    });

    return dialog;
};

/**
 * Open the viewer at the thumbnail that was clicked.
 *
 * @param {HTMLAnchorElement} link
 * @returns {Promise<void>}
 */
const open = async(link) => {
    const pending = new Pending('mod_lightboxgallery/lightbox:open');
    try {
        await getDialog();
        images = getImageSet(link);
        dialog.classList.toggle('autoresize', link.closest('.autoresize') !== null);
        if (!dialog.open) {
            dialog.showModal();
        }
        await showImage(Math.max(0, images.findIndex((image) => image.url === link.href)));
    } catch (error) {
        Notification.exception(error);
    } finally {
        pending.resolve();
    }
};

/**
 * Open the viewer when a gallery thumbnail is clicked.
 */
export const init = () => {
    if (initialised) {
        return;
    }
    initialised = true;

    document.addEventListener('click', (event) => {
        const link = event.target.closest(SELECTORS.LINK);
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            // Let modified clicks open the image in a new tab or window as usual.
            return;
        }
        event.preventDefault();
        open(link);
    });
};
