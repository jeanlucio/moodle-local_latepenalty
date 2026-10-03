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
 * Event observer for applying late penalties to grades.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty;

use local_latepenalty\local\penalty_writer;

/**
 * Observer class for handling grade events.
 */
class observer {
    /** @var int|null Course whose reset is running: its group changes recalculate nothing. */
    private static ?int $resetcourseid = null;

    /**
     * Apply the late penalty when a module grades a student.
     *
     * The event carries the graded item, which matters because an activity may
     * own several items. Events fired by the plugin's own writes are recognised
     * and skipped.
     *
     * @param \core\event\user_graded $event The grade event.
     * @return void
     */
    public static function user_graded(\core\event\user_graded $event): void {
        global $DB;

        $userid = (int) $event->relateduserid;
        $itemid = (int) ($event->other['itemid'] ?? 0);
        if (empty($userid) || empty($itemid)) {
            return;
        }

        $finalgrade = $event->other['finalgrade'] ?? null;
        if (penalty_writer::is_own_echo($userid, $itemid, $finalgrade === null ? null : (float) $finalgrade)) {
            return;
        }

        $gradeitem = \grade_item::fetch(['id' => $itemid]);
        if (!$gradeitem || !penalty_helper::is_penalisable_item($gradeitem)) {
            return;
        }

        $cm = get_coursemodule_from_instance(
            $gradeitem->itemmodule,
            $gradeitem->iteminstance,
            $gradeitem->courseid,
            false,
            IGNORE_MISSING
        );
        if (!$cm) {
            return;
        }

        $rule = $DB->get_record('local_latepenalty_rules', ['cmid' => $cm->id]);
        if (!$rule || !$rule->enabled) {
            return;
        }

        recalculator::run($cm, $gradeitem, [$userid], (float) $rule->daily_penalty, (float) $rule->max_penalty, null);
    }

    /**
     * Recalculate when an activity override or extension changes a deadline (F7).
     *
     * Handles the student and group override events of assignments, quizzes and
     * lessons, and assignment extensions. A student event recalculates that
     * student; a group event recalculates the group's members.
     *
     * @param \core\event\base $event Override or extension event, in the activity context.
     * @return void
     */
    public static function activity_deadline_changed(\core\event\base $event): void {
        global $DB;

        if ((int) $event->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        $cmid = (int) $event->contextinstanceid;
        $rule = $DB->get_record('local_latepenalty_rules', ['cmid' => $cmid, 'enabled' => 1]);
        if (!$rule) {
            return;
        }

        if (!empty($event->relateduserid)) {
            recalculator::recalculate_for_student(
                $cmid,
                (int) $event->relateduserid,
                (float) $rule->daily_penalty,
                (float) $rule->max_penalty
            );
        } else if (!empty($event->other['groupid'])) {
            recalculator::recalculate_for_group(
                $cmid,
                (int) $event->other['groupid'],
                (float) $rule->daily_penalty,
                (float) $rule->max_penalty
            );
        }
    }

    /**
     * Delete the plugin's own rows for a single course module that is being removed.
     *
     * Fires only for the "delete this activity" path (course_delete_module()).
     * Whole-course deletion does not go through that function — see
     * course_deleted() below — so this alone does not cover course removal.
     * Without this cleanup, rules/overrides/group overrides keyed by the dead
     * cmid would remain orphaned forever, including per-student data the Privacy
     * API can no longer reach once the module's context_module row is gone.
     *
     * @param \core\event\course_module_deleted $event The event.
     * @return void
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        global $DB;

        $cmid = (int) $event->objectid;

        $DB->delete_records('local_latepenalty_overrides', ['cmid' => $cmid]);
        $DB->delete_records('local_latepenalty_group_overrides', ['cmid' => $cmid]);
        $DB->delete_records('local_latepenalty_rules', ['cmid' => $cmid]);
    }

    /**
     * Delete the group overrides of a group that was removed.
     *
     * Its members are gone by the time the event fires, so the override applies to
     * nobody; left behind, it would show as an unknown group in the override list.
     * Course resets and "Delete all groups" also fire this event once per group.
     *
     * @param \core\event\group_deleted $event The event.
     * @return void
     */
    public static function group_deleted(\core\event\group_deleted $event): void {
        global $DB;

        $DB->delete_records('local_latepenalty_group_overrides', ['groupid' => (int) $event->objectid]);

        // The former members lose the group's deadlines, but they are already gone from
        // groups_members: recalculate the whole course in the background (once per course).
        $courseid = (int) $event->courseid;
        if (self::$resetcourseid === $courseid || !self::course_has_rules($courseid)) {
            return;
        }
        $task = new task\recalculate_course();
        $task->set_custom_data(['courseid' => $courseid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Recalculate a student who joined or left a group that changes deadlines.
     *
     * Joining or leaving the group gives or takes away its group overrides, from
     * Late Penalty or from the activity itself, as editing the override would.
     * Nothing happens during a course reset, nor for a user no longer enrolled:
     * unenrolling removes the groups right before core deletes the grade, which
     * "Recover grades" would bring back from the history as it was last written.
     *
     * @param \core\event\base $event group_member_added or group_member_removed.
     * @return void
     */
    public static function group_member_changed(\core\event\base $event): void {
        $courseid = (int) $event->courseid;
        $userid = (int) $event->relateduserid;
        if (self::$resetcourseid === $courseid || !is_enrolled(\context_course::instance($courseid), $userid)) {
            return;
        }

        foreach (recalculator::rules_with_group_deadline($courseid, (int) $event->objectid) as $rule) {
            recalculator::recalculate_for_student(
                (int) $rule->cmid,
                $userid,
                (float) $rule->daily_penalty,
                (float) $rule->max_penalty
            );
        }
    }

    /**
     * Note that a course reset started, so its group changes recalculate nothing.
     *
     * A reset closes a term: recalculating then would punish the students who had
     * a group extension in it, which is not what removing the groups means.
     *
     * @param \core\event\course_reset_started $event The event.
     * @return void
     */
    public static function course_reset_started(\core\event\course_reset_started $event): void {
        self::$resetcourseid = (int) $event->courseid;
    }

    /**
     * Note that the course reset is over.
     *
     * @param \core\event\course_reset_ended $event The event.
     * @return void
     */
    public static function course_reset_ended(\core\event\course_reset_ended $event): void {
        self::$resetcourseid = null;
    }

    /**
     * Whether a course has an activity with an enabled rule.
     *
     * @param int $courseid Course ID.
     * @return bool
     */
    private static function course_has_rules(int $courseid): bool {
        global $DB;

        return $DB->record_exists_sql(
            "SELECT 1
               FROM {local_latepenalty_rules} r
               JOIN {course_modules} cm ON cm.id = r.cmid
              WHERE cm.course = :courseid AND r.enabled = 1",
            ['courseid' => $courseid]
        );
    }

    /**
     * Delete the plugin's own rows for every course module in a course that is
     * about to be removed.
     *
     * remove_course_contents() (called by delete_course()) deletes course_modules
     * rows through its own duplicated code path — its source literally flags
     * "very similar code in course_delete_module" — and never fires
     * course_module_deleted per module. So course_module_deleted() above never
     * runs during a whole-course deletion, confirmed by exercising the real
     * delete_course() and finding orphaned rows survive it. This hook fires
     * before remove_course_contents() runs, while course_modules rows for the
     * course still exist, so the affected cmids can still be resolved.
     *
     * @param \core_course\hook\before_course_deleted $hook The hook instance.
     * @return void
     */
    public static function course_deleted(\core_course\hook\before_course_deleted $hook): void {
        global $DB;

        $cmids = array_keys($DB->get_records(
            'course_modules',
            ['course' => (int) $hook->course->id],
            '',
            'id'
        ));

        if (empty($cmids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);

        $DB->delete_records_select('local_latepenalty_overrides', "cmid $insql", $inparams);
        $DB->delete_records_select('local_latepenalty_group_overrides', "cmid $insql", $inparams);
        $DB->delete_records_select('local_latepenalty_rules', "cmid $insql", $inparams);
    }
}
