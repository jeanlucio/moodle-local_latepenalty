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

namespace local_latepenalty\task;

use local_latepenalty\recalculator;

/**
 * Applies the rules again to the activities where a deleted group changed the deadline.
 *
 * A deleted group takes its deadline away from its former members, but core
 * removes the members before it reports the deletion, so who they were is no
 * longer known: those activities are recalculated instead. Running later also
 * lets the activities' own group_deleted observers remove their group overrides
 * first. Recalculating an unchanged grade writes nothing, and grades that predate
 * the rule are left alone (F19).
 *
 * Custom data: courseid, cmids. Tasks queued by 1.3.0 carry no cmids and cover
 * the whole course.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recalculate_course extends \core\task\adhoc_task {
    #[\Override]
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        $courseid = (int) $data->courseid;
        if ($DB->record_exists('course', ['id' => $courseid])) {
            recalculator::recalculate_course($courseid, isset($data->cmids) ? (array) $data->cmids : null);
        }
    }
}
