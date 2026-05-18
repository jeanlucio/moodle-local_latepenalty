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
 * Recalculates previously applied late penalties after a rule change.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty;

/**
 * Re-applies late penalties for all already-penalised students when the
 * deadline or rate of a rule changes.
 *
 * Only students who previously received a penalty (recorded in
 * grade_grades_history with source = 'local_latepenalty') are affected.
 * Students who submitted within the original deadline are never touched.
 *
 * Auto-graded modules (lesson, playergroup, scorm) are skipped during
 * recalculation because their original submission timestamp is derived from
 * the live grade event and is no longer recoverable.
 */
class recalculator {
    /**
     * Recalculate penalties for all penalised students of a course module.
     *
     * @param int   $cmid        Course module ID.
     * @param int   $newdeadline New deadline timestamp.
     * @param float $daily       New daily penalty percentage.
     * @param float $max         New maximum penalty cap percentage.
     * @return void
     */
    public static function recalculate(int $cmid, int $newdeadline, float $daily, float $max): void {
        global $DB;

        if (!$newdeadline) {
            return;
        }

        // Fetch the most-recent penalty record per student for this cmid.
        $sql = "SELECT ggh.userid, ggh.rawgrade, ggh.itemid
                  FROM {grade_grades_history} ggh
                  JOIN {grade_items} gi ON gi.id = ggh.itemid
                                       AND gi.itemtype = 'mod'
                  JOIN {modules} m ON m.name = gi.itemmodule
                  JOIN {course_modules} cm ON cm.instance = gi.iteminstance
                                          AND cm.id = :cmid
                                          AND cm.module = m.id
                 WHERE ggh.source = 'local_latepenalty'
                 ORDER BY ggh.timemodified DESC";

        $rows = $DB->get_records_sql($sql, ['cmid' => $cmid]);

        // Deduplicate — keep only the most recent entry per user.
        $seen     = [];
        $students = [];
        foreach ($rows as $row) {
            $uid = (int) $row->userid;
            if (!isset($seen[$uid])) {
                $seen[$uid]  = true;
                $students[]  = $row;
            }
        }

        if (empty($students)) {
            return;
        }

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);

        $isautograded = in_array($cm->modname, penalty_helper::$autogradedmodules);

        // Load all per-user overrides for this cmid in a single query.
        $overridesbyuserid = $DB->get_records(
            'local_latepenalty_overrides',
            ['cmid' => $cmid],
            '',
            'userid, deadline, daily_penalty, max_penalty'
        );

        foreach ($students as $student) {
            $userid   = (int) $student->userid;
            $rawgrade = (float) $student->rawgrade;

            // Resolve effective deadline and rates for this student.
            $override = $overridesbyuserid[$userid] ?? null;
            $effectivedeadline = ($override && $override->deadline !== null)
                ? (int) $override->deadline
                : $newdeadline;
            $effectivedaily = ($override && $override->daily_penalty !== null)
                ? (float) $override->daily_penalty
                : $daily;
            $effectivemax = ($override && $override->max_penalty !== null)
                ? (float) $override->max_penalty
                : $max;

            if (!$effectivedeadline) {
                continue;
            }

            if ($isautograded) {
                // For auto-graded modules there is no submission record, but the
                // original grade history entry (written when the student completed
                // the activity) has a timemodified that matches the event timestamp
                // the observer used — making it a reliable proxy for submission time.
                $row = $DB->get_record_sql(
                    "SELECT timemodified
                       FROM {grade_grades_history}
                      WHERE itemid = :itemid
                        AND userid = :userid
                        AND source != 'local_latepenalty'
                   ORDER BY timemodified DESC",
                    ['itemid' => (int) $student->itemid, 'userid' => $userid],
                    IGNORE_MISSING
                );
                $submissiontime = $row ? (int) $row->timemodified : null;
            } else {
                $submissiontime = penalty_helper::get_submission_time($userid, $cm);
            }

            if ($submissiontime === null) {
                continue;
            }

            $dayslate = penalty_helper::calculate_days_late($submissiontime, $effectivedeadline);

            $newfinalgrade = ($dayslate <= 0)
                ? $rawgrade
                : penalty_helper::apply_penalty($rawgrade, $dayslate, $effectivedaily, $effectivemax);

            $gradeitem = \grade_item::fetch(['id' => (int) $student->itemid]);
            if (!$gradeitem) {
                continue;
            }

            $grade = new \grade_grade(['itemid' => $gradeitem->id, 'userid' => $userid]);
            $grade->load_optional_fields();

            $currentgrade = (float) ($grade->finalgrade ?? 0);
            if (abs($newfinalgrade - $currentgrade) < 0.01) {
                continue;
            }

            // Register the anti-recursion guard so the observer skips the
            // user_graded event that update_final_grade() will fire.
            $key = $userid . '_' . $cmid;
            observer::register_pending_grade($key, (float) $gradeitem->bounded_grade($newfinalgrade));

            $gradeitem->update_final_grade(
                $userid,
                $newfinalgrade,
                'local_latepenalty',
                false,
                FORMAT_MOODLE,
                null,
                null,
                true
            );
        }
    }
}
