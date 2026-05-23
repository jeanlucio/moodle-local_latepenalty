// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Course page late-penalty badge injector.
 *
 * Injects a <span> badge inside .activityname for each activity that has an
 * active penalty rule. The badge also acts as the Bootstrap tooltip anchor.
 *
 * A MutationObserver keeps the badge alive when the courseformat reactive
 * component re-renders the li contents (Moodle 4.5). A final setTimeout
 * retry covers late adoptions that produce mutations not caught by the
 * observer in the same tick.
 *
 * @module     local_latepenalty/courseinfo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Tooltip from 'theme_boost/bootstrap/tooltip';

/**
 * Find the activity li element for a given cmid.
 *
 * Tries id="module-{cmid}" first, then falls back to the data-for/data-id
 * attributes set by the courseformat reactive component. The fallback covers
 * Moodle 4.5 where some reactive re-renders may temporarily drop the id.
 *
 * @param {number} cmid The course module ID.
 * @returns {HTMLElement|null}
 */
const findCmElement = (cmid) => document.getElementById(`module-${cmid}`)
    ?? document.querySelector(`[data-for="cmitem"][data-id="${cmid}"]`);

/**
 * Inject a penalty badge into an activity list item.
 *
 * Safe to call multiple times: returns immediately if the badge already exists.
 *
 * @param {HTMLElement}                                              el   The activity li element.
 * @param {{notice: string, badgelabel: string, badgestate: string}} item Penalty data for this activity.
 */
const markItem = (el, item) => {
    const actname = el.querySelector('.activityname');
    if (!actname) {
        return;
    }

    if (actname.querySelector('.local-latepenalty-badge')) {
        return;
    }

    const badge = document.createElement('span');
    badge.className = `local-latepenalty-badge local-latepenalty-badge--${item.badgestate}`;
    badge.textContent = item.badgelabel;
    badge.setAttribute('data-bs-toggle', 'tooltip');
    badge.setAttribute('data-bs-placement', 'bottom');
    badge.setAttribute('data-bs-title', item.notice);
    badge.setAttribute('title', item.notice);

    actname.appendChild(badge);
    if (typeof Tooltip.getOrCreateInstance === 'function') {
        Tooltip.getOrCreateInstance(badge);
    } else {
        new Tooltip(badge);
    }
};

/**
 * Inject penalty badges into the course activity list.
 *
 * @param {Array<{cmid: number, notice: string, badgelabel: string, badgestate: string}>} notices One entry per activity.
 */
export const init = (notices) => {
    /** @type {Map<number, {notice: string, badgelabel: string, badgestate: string}>} */
    const noticeMap = new Map(notices.map(item => [item.cmid, item]));

    const tryMarkAll = () => {
        noticeMap.forEach((item, cmid) => {
            const el = findCmElement(cmid);
            if (el) {
                markItem(el, item);
            }
        });
    };

    tryMarkAll();

    // Re-inject after the courseformat reactive re-renders activity li items.
    // Only fire tryMarkAll when a cmitem or activity-card node is added/removed —
    // not when we ourselves insert a badge span — to avoid ping-pong loops with
    // the Moodle 4.5 reactive renderer.
    const observer = new MutationObserver((mutations) => {
        const relevant = mutations.some(m => {
            const nodes = [...m.addedNodes, ...m.removedNodes];
            return nodes.some(n => {
                if (n.nodeType !== 1) {
                    return false;
                }
                return n.matches('[data-for="cmitem"]')
                    || n.matches('[data-region="activity-card"]')
                    || n.querySelector?.('[data-for="cmitem"]');
            });
        });
        if (relevant) {
            tryMarkAll();
        }
    });
    observer.observe(document.body, {childList: true, subtree: true});

    // Belt-and-suspenders retry for Moodle 4.5 late reactive adoption.
    setTimeout(tryMarkAll, 500);
};
