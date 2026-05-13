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
 * Activity page late-penalty notice injector.
 *
 * Receives the notice string from the hook listener (embedded via js_call_amd)
 * and appends it inside the standard activity-information block, alongside any
 * existing date or completion info rendered by the theme.
 *
 * @module     local_latepenalty/activityinfo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTOR_ACTINFO = '[data-region="activity-information"]';
const SELECTOR_HEADER = '[data-for="page-activity-header"]';

/**
 * Inject a penalty notice into the activity header.
 *
 * @param {string} notice The formatted penalty notice string.
 */
export const init = (notice) => {
    const div = document.createElement('div');
    div.className = 'local-latepenalty-notice';
    div.textContent = notice;

    const infoRegion = document.querySelector(SELECTOR_ACTINFO);
    if (infoRegion) {
        infoRegion.appendChild(div);
        return;
    }

    const header = document.querySelector(SELECTOR_HEADER);
    if (header) {
        header.appendChild(div);
    }
};
