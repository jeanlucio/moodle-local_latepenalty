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

/**
 * Observer class for handling grade events.
 */
class observer {
    /**
     * Map of "userid_cmid" => expected penalised grade (float).
     *
     * Moodle's event system buffers events fired during an observer and
     * dispatches them only after the current observer returns.  A simple
     * "processing" flag would already be unset by then, so every event
     * would re-trigger the penalty loop.  Instead we record the exact
     * bounded grade we just wrote; when the next user_graded arrives with
     * that same value we know it is our own event and skip it.
     *
     * @var array<string, float>
     */
    private static array $pendingpenalty = [];

    /**
     * Handle the user_graded event and apply late penalty if applicable.
     *
     * @param \core\event\user_graded $event The grade event.
     * @return void
     */
    public static function user_graded(\core\event\user_graded $event): void {
        global $DB;

        // Extract event data.
        $eventdata = $event->get_data();
        $userid = $event->relateduserid;

        if (empty($userid)) {
            debugging('local_latepenalty: Invalid event data (missing relateduserid)', DEBUG_DEVELOPER);
            return;
        }

        // The user_graded event always uses context_course, not context_module.
        // The grade item ID is stored in $event->other['itemid'] — use it to find the cmid.
        $itemid = $eventdata['other']['itemid'] ?? null;

        if (empty($itemid)) {
            return;
        }

        $gradeitem = $DB->get_record('grade_items', ['id' => $itemid], 'itemtype,itemmodule,iteminstance,courseid');

        if (!$gradeitem || $gradeitem->itemtype !== 'mod') {
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

        $cmid = $cm->id;
        $key  = $userid . '_' . $cmid;

        // Skip events that were fired by our own penalty application.
        // Moodle's event manager buffers new events while dispatching
        // (self::$dispatching = true) and processes them after the current
        // observer returns — meaning a simple "processing" lock would be
        // unset before the buffered event fires.  Comparing the incoming
        // other['finalgrade'] against the value we just stored is the only
        // reliable guard against the re-entrant loop.
        if (isset(self::$pendingpenalty[$key])) {
            $eventfinalgrade = isset($eventdata['other']['finalgrade'])
                ? (float) $eventdata['other']['finalgrade']
                : null;
            if (
                $eventfinalgrade !== null
                && abs($eventfinalgrade - self::$pendingpenalty[$key]) < 0.001
            ) {
                unset(self::$pendingpenalty[$key]);
                return;
            }
            // A different grade arrived (e.g. teacher re-graded); clear and proceed.
            unset(self::$pendingpenalty[$key]);
        }

        self::process_penalty($userid, $cmid, $eventdata);
    }

    /**
     * Process the late penalty calculation and application.
     *
     * @param int $userid User ID.
     * @param int $cmid Course module ID.
     * @param array $eventdata Event data array.
     * @return void
     */
    private static function process_penalty(int $userid, int $cmid, array $eventdata): void {
        global $DB;

        // Get penalty rule for this course module.
        $rule = $DB->get_record('local_latepenalty_rules', ['cmid' => $cmid]);

        if (!$rule || !$rule->enabled) {
            return;
        }

        // Get course module details.
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);

        // Resolve effective deadline and rates: user override > group override > rule default.
        $override = penalty_helper::get_override($cmid, $userid);
        $groupoverride = penalty_helper::get_group_override($cmid, $userid);

        if ($override && $override->deadline !== null) {
            $deadline = (int) $override->deadline;
        } else if ($groupoverride && $groupoverride->deadline !== null) {
            $deadline = (int) $groupoverride->deadline;
        } else {
            $deadline = penalty_helper::get_module_user_deadline($cm->modname, $cm->instance, $userid)
                ?? penalty_helper::get_deadline($cm);
        }

        if (!$deadline) {
            return;
        }

        $daily = ($override && $override->daily_penalty !== null)
            ? (float) $override->daily_penalty
            : (($groupoverride && $groupoverride->daily_penalty !== null)
                ? (float) $groupoverride->daily_penalty
                : (float) $rule->daily_penalty);

        $max = ($override && $override->max_penalty !== null)
            ? (float) $override->max_penalty
            : (($groupoverride && $groupoverride->max_penalty !== null)
                ? (float) $groupoverride->max_penalty
                : (float) $rule->max_penalty);

        // Get submission timestamp (when the student submitted, not when graded).
        // For auto-graded modules the grade event itself is the student action, so
        // use the event timestamp when no explicit submission record exists.
        // For manually-graded modules (e.g. forum), if no student action is found
        // there is nothing to measure lateness against — skip the penalty.
        $submissiontime = penalty_helper::get_submission_time($userid, $cm);

        if ($submissiontime === null) {
            if (!in_array($cm->modname, penalty_helper::$submissionmodules)) {
                // No submission table for this module type; the grade event fires when
                // the student completes the activity, so use its timestamp as a proxy.
                $submissiontime = (int) ($eventdata['timecreated'] ?? time());
            } else {
                return;
            }
        }

        // Calculate days late.
        $dayslate = penalty_helper::calculate_days_late($submissiontime, $deadline);

        if ($dayslate <= 0) {
            return;
        }

        // Get the grade item.
        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
            'courseid' => $cm->course,
        ]);

        if (!$gradeitem) {
            debugging('local_latepenalty: Grade item not found for cmid ' . $cmid, DEBUG_DEVELOPER);
            return;
        }

        // Get the user's current grade.
        $grade = new \grade_grade(['itemid' => $gradeitem->id, 'userid' => $userid]);
        $grade->load_optional_fields();

        if (!empty($grade->overridden)) {
            return;
        }

        if (!empty($grade->locked) || !empty($gradeitem->locked)) {
            return;
        }

        $rawgrade = $grade->rawgrade ?? $grade->finalgrade;
        if (empty($rawgrade)) {
            return;
        }

        if ((float) $rawgrade <= (float) $gradeitem->grademin) {
            return;
        }

        // Calculate penalty.
        $finalgrade = penalty_helper::apply_penalty($rawgrade, $dayslate, $daily, $max, (float) $gradeitem->grademin);

        // Apply only when the change is meaningful (avoids spurious DB writes).
        if (abs($finalgrade - $rawgrade) > 0.01) {
            // Store the bounded value that will appear in other['finalgrade'] of the
            // user_graded event fired by update_final_grade, so we can skip it.
            $key = $userid . '_' . $cmid;
            self::$pendingpenalty[$key] = (float) $gradeitem->bounded_grade($finalgrade);
            $gradeitem->update_final_grade(
                $userid,
                $finalgrade,
                'local_latepenalty',
                false,
                FORMAT_MOODLE,
                null,
                null,
                true
            );
        }
    }

    /**
     * Register a grade value that this plugin is about to write, so the next
     * user_graded event with that value is recognised as our own and skipped.
     *
     * Called by recalculator before each update_final_grade() call.
     *
     * @param string $key   "{userid}_{cmid}" composite key.
     * @param float  $value Bounded grade value that will appear in the event.
     * @return void
     */
    public static function register_pending_grade(string $key, float $value): void {
        self::$pendingpenalty[$key] = $value;
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
