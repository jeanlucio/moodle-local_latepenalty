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
 * Two-phase strategy:
 *
 * Phase 1 — before reactive adoption:
 *   Sets `data-lp-penalty` on the `li` so the CSS ::after pseudo-element
 *   renders the badge immediately. No DOM injection inside the reactive
 *   subtree at this stage (would be wiped during adoption).
 *
 * Phase 2 — after reactive adoption (data-indexed set by courseformat):
 *   Injects a visible <span> badge inside .activityname. The span is both
 *   the visual badge and the Bootstrap tooltip anchor (it has a hover area,
 *   unlike a pseudo-element). Sets `data-lp-injected` on the `li` to
 *   suppress the CSS ::after fallback so the badge appears only once.
 *
 * For non-reactive formats (e.g. Tiles popup — no data-for="cmitem"),
 * phase 2 runs immediately.
 *
 * @module     local_latepenalty/courseinfo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Tooltip from 'theme_boost/bootstrap/tooltip';

/**
 * Mark an activity list item and inject the badge+tooltip span when safe.
 *
 * @param {HTMLElement} el     The `li#module-{cmid}` element.
 * @param {string}      notice Full penalty details for the tooltip.
 */
const markItem = (el, notice) => {
    // Always mark for the CSS ::after fallback badge.
    if (!el.hasAttribute('data-lp-penalty')) {
        el.setAttribute('data-lp-penalty', '1');
    }

    // Inject the real badge span with tooltip.
    // Guard is on the span inside .activityname (not on the li) so re-injection
    // happens whenever the courseformat reactive renderer wipes .activityname.
    const actname = el.querySelector('.activityname');
    if (!actname || actname.querySelector('.local-latepenalty-badge')) {
        return;
    }

    const badge = document.createElement('span');
    badge.className = 'local-latepenalty-badge';
    badge.textContent = 'Penalidade por atraso';
    badge.setAttribute('data-bs-toggle', 'tooltip');
    badge.setAttribute('data-bs-placement', 'bottom');
    badge.setAttribute('data-bs-title', notice);
    badge.setAttribute('title', notice);

    actname.appendChild(badge);
    Tooltip.getOrCreateInstance(badge);

    // Suppress CSS ::after once the real span is in place.
    el.setAttribute('data-lp-injected', '1');
};

/**
 * Inject penalty badges into the course activity list.
 *
 * @param {Array<{cmid: number, notice: string}>} notices One entry per activity with an enabled rule and a deadline.
 */
export const init = (notices) => {
    /** @type {Map<number, string>} */
    const noticeMap = new Map(notices.map(({cmid, notice}) => [cmid, notice]));

    const tryMarkAll = () => {
        noticeMap.forEach((notice, cmid) => {
            const el = document.getElementById(`module-${cmid}`);
            if (el) {
                markItem(el, notice);
            }
        });
    };

    tryMarkAll();

    // Watch for dynamically rendered content (Tiles popup, reactive courseformat).
    const observer = new MutationObserver(tryMarkAll);
    observer.observe(document.body, {childList: true, subtree: true});
};
