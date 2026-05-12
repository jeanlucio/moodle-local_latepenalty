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
 * Course page late-penalty notice injector.
 *
 * Receives notice data from the hook listener (embedded via js_call_amd,
 * no extra AJAX round-trip) and appends a short penalty reminder to each
 * matching activity item on the course page.
 *
 * @module     local_latepenalty/courseinfo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** CSS selector for course-module list items. */
const SELECTOR_CM = '[data-for="cmitem"]';

/**
 * Inject penalty notices into the course activity list.
 *
 * @param {Array<{cmid: number, notice: string}>} notices One entry per activity that has an enabled rule and a deadline.
 */
export const init = (notices) => {
    notices.forEach(({cmid, notice}) => {
        const el = document.querySelector(`${SELECTOR_CM}[data-id="${cmid}"]`);
        if (!el) {
            return;
        }

        const div = document.createElement('div');
        div.className = 'local-latepenalty-notice';
        div.textContent = notice;

        // Insert after the activity-grid block so the notice sits below the
        // icon/name row but before the description / afterlink area.
        const grid = el.querySelector('.activity-grid');
        if (grid) {
            grid.insertAdjacentElement('afterend', div);
        } else {
            el.appendChild(div);
        }
    });
};
