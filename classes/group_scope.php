<?php
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
 * Activity-level group scope resolution, shared by the override management pages.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty;

use context_module;
use stdClass;

/**
 * Static helper resolving whether a caller must be confined to their own group(s).
 */
class group_scope {
    /**
     * Resolve which groups, if any, a caller must be restricted to on an activity's
     * override pages.
     *
     * Mirrors the standard Moodle separate-groups check, but scoped to the
     * activity's effective group mode (course.groupmode unless the activity's own
     * groupmode applies — see groups_get_activity_groupmode()) rather than the
     * course's group mode alone. Returns null when the caller should see every
     * group (activity not in separate groups, or the caller can access all groups).
     *
     * @param stdClass       $cm         Course module record (needs course, groupmode, groupingid, id).
     * @param context_module $modcontext The module context.
     * @return int[]|null Group IDs to restrict to, or null for no restriction.
     */
    public static function resolve_activity_restriction(stdClass $cm, context_module $modcontext): ?array {
        if ((int) groups_get_activity_groupmode($cm) !== SEPARATEGROUPS) {
            return null;
        }

        if (has_capability('moodle/site:accessallgroups', $modcontext)) {
            return null;
        }

        return array_keys(groups_get_activity_allowed_groups($cm));
    }
}
