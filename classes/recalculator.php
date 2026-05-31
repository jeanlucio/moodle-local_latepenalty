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
 * For modules without an explicit submission table (anything not in
 * penalty_helper::$submissionmodules), the grade_grades_history timestamp is
 * used as a proxy for the original submission time.
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
        global $CFG, $DB;

        require_once($CFG->libdir . '/gradelib.php');

        if (!$newdeadline) {
            return;
        }

        // Fetch the most-recent penalty record per student for this cmid.
        $sql = "SELECT ggh.id, ggh.userid, ggh.rawgrade, ggh.itemid
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
        $isautograded = !in_array($cm->modname, penalty_helper::$submissionmodules);

        // Collect all student user IDs. All records for the same CM share one grade item.
        $userids = array_map(fn($s) => (int) $s->userid, $students);
        $itemid  = (int) $students[0]->itemid;

        $gradeitem = \grade_item::fetch(['id' => $itemid]);
        if (!$gradeitem) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');

        // Pre-load grade_grades to check finalgrade and lock status.
        $graderows = $DB->get_records_sql(
            "SELECT userid, finalgrade, locked
               FROM {grade_grades}
              WHERE itemid = :itemid
                AND userid $insql",
            array_merge(['itemid' => $itemid], $inparams)
        );
        $gradebyuserid = [];
        foreach ($graderows as $gr) {
            $gradebyuserid[(int) $gr->userid] = $gr;
        }

        // Pre-load plugin overrides in a single query, keyed by userid.
        $overridesbyuserid = [];
        foreach ($DB->get_records('local_latepenalty_overrides', ['cmid' => $cmid]) as $override) {
            $overridesbyuserid[(int) $override->userid] = $override;
        }

        // Pre-load group overrides for all students (merged most-lenient per field).
        $groupoverridesbyuserid = penalty_helper::get_group_overrides_bulk($cmid, $userids);

        // Pre-load module-native deadline overrides for all students in one pass.
        $moduledeadlinesbyuserid = penalty_helper::get_module_user_deadlines_bulk(
            $cm->modname,
            $cm->instance,
            $userids
        );

        // Pre-load submission times — one bulk query instead of N individual queries.
        if ($isautograded) {
            // For auto-graded modules the grade history timestamp proxies submission time.
            $subrows = $DB->get_records_sql(
                "SELECT userid, MAX(timemodified) AS timemodified
                   FROM {grade_grades_history}
                  WHERE itemid = :itemid
                    AND userid $insql
                    AND source != 'local_latepenalty'
               GROUP BY userid",
                array_merge(['itemid' => $itemid], $inparams)
            );
            $submissiontimesbyuserid = [];
            foreach ($subrows as $subrow) {
                $submissiontimesbyuserid[(int) $subrow->userid] = (int) $subrow->timemodified;
            }
        } else {
            $submissiontimesbyuserid = penalty_helper::get_submission_times_bulk($userids, $cm);
        }

        // Pre-load grade history timestamps to detect teacher manual overrides.
        // update_final_grade() sets grade_grades.overridden, so that field cannot
        // distinguish our penalty writes from teacher edits. Instead, compare the
        // most recent non-latepenalty history timestamp against the most recent
        // latepenalty one: if a non-latepenalty record is newer the teacher edited
        // the grade after our last penalty and we must leave it untouched.
        $historyrows = $DB->get_records_sql(
            "SELECT userid,
                    MAX(CASE WHEN source = 'local_latepenalty'
                             THEN timemodified ELSE NULL END) AS lastpenalty,
                    MAX(CASE WHEN source IS NULL
                               OR source != 'local_latepenalty'
                             THEN timemodified ELSE NULL END) AS lastother
               FROM {grade_grades_history}
              WHERE itemid = :itemid
                AND userid $insql
              GROUP BY userid",
            array_merge(['itemid' => $itemid], $inparams)
        );
        $historybyuserid = [];
        foreach ($historyrows as $hrow) {
            $historybyuserid[(int) $hrow->userid] = $hrow;
        }

        foreach ($students as $student) {
            $userid   = (int) $student->userid;
            $rawgrade = (float) $student->rawgrade;

            // Resolve effective deadline and rates: user override > group override > rule default.
            $override = $overridesbyuserid[$userid] ?? null;
            $groupoverride = $groupoverridesbyuserid[$userid] ?? null;
            if ($override && $override->deadline !== null) {
                $effectivedeadline = (int) $override->deadline;
            } else if ($groupoverride && $groupoverride->deadline !== null) {
                $effectivedeadline = (int) $groupoverride->deadline;
            } else {
                $effectivedeadline = $moduledeadlinesbyuserid[$userid] ?? $newdeadline;
            }
            $effectivedaily = ($override && $override->daily_penalty !== null)
                ? (float) $override->daily_penalty
                : (($groupoverride && $groupoverride->daily_penalty !== null)
                    ? (float) $groupoverride->daily_penalty
                    : $daily);
            $effectivemax = ($override && $override->max_penalty !== null)
                ? (float) $override->max_penalty
                : (($groupoverride && $groupoverride->max_penalty !== null)
                    ? (float) $groupoverride->max_penalty
                    : $max);

            if (!$effectivedeadline) {
                continue;
            }

            $submissiontime = $submissiontimesbyuserid[$userid] ?? null;
            if ($submissiontime === null) {
                continue;
            }

            if ($rawgrade <= (float) $gradeitem->grademin) {
                continue;
            }

            $graderow = $gradebyuserid[$userid] ?? null;
            if (!empty($graderow->locked) || !empty($gradeitem->locked)) {
                continue;
            }

            $hrow        = $historybyuserid[$userid] ?? null;
            $lastpenalty = ($hrow !== null && $hrow->lastpenalty !== null) ? (int) $hrow->lastpenalty : 0;
            $lastother   = ($hrow !== null && $hrow->lastother !== null) ? (int) $hrow->lastother : 0;
            if ($lastother > $lastpenalty) {
                continue;
            }

            $dayslate = penalty_helper::calculate_days_late($submissiontime, $effectivedeadline);

            $newfinalgrade = ($dayslate <= 0)
                ? $rawgrade
                : penalty_helper::apply_penalty(
                    $rawgrade,
                    $dayslate,
                    $effectivedaily,
                    $effectivemax,
                    (float) $gradeitem->grademin
                );

            $currentgrade = (float) ($graderow->finalgrade ?? 0);
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

    /**
     * Recalculate the penalty for a single student after a per-user override
     * is saved or deleted.
     *
     * Unlike {@see recalculate()}, this method does not require a prior
     * local_latepenalty history entry — it works directly from
     * grade_grades.rawgrade, making it safe for restored courses or
     * activities whose grade was never written by this plugin.
     *
     * The "teacher manually edited" guard is applied only when a previous
     * latepenalty write exists (lastpenalty > 0). When no history entry
     * exists the grade is treated as the unmodified original and is always
     * eligible for recalculation.
     *
     * @param int   $cmid   Course module ID.
     * @param int   $userid Student user ID.
     * @param float $daily  Rule daily penalty percentage.
     * @param float $max    Rule maximum penalty cap percentage.
     * @return void
     */
    public static function recalculate_for_student(int $cmid, int $userid, float $daily, float $max): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/gradelib.php');

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);

        $gradeitem = \grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => $cm->modname,
            'iteminstance' => $cm->instance,
            'courseid'     => $cm->course,
        ]);
        if (!$gradeitem) {
            return;
        }

        $graderow = $DB->get_record('grade_grades', ['itemid' => $gradeitem->id, 'userid' => $userid]);
        if (!$graderow || !empty($graderow->locked) || !empty($gradeitem->locked)) {
            return;
        }

        if ($graderow->rawgrade === null) {
            return;
        }

        $rawgrade = (float) $graderow->rawgrade;
        if ($rawgrade <= (float) $gradeitem->grademin) {
            return;
        }

        // Resolve effective deadline and rates: user override > group override > rule default.
        $override = penalty_helper::get_override($cmid, $userid);
        $groupoverride = penalty_helper::get_group_override($cmid, $userid);
        if ($override && $override->deadline !== null) {
            $effectivedeadline = (int) $override->deadline;
        } else if ($groupoverride && $groupoverride->deadline !== null) {
            $effectivedeadline = (int) $groupoverride->deadline;
        } else {
            $effectivedeadline = penalty_helper::get_module_user_deadline($cm->modname, $cm->instance, $userid)
                ?? penalty_helper::get_deadline($cm)
                ?? 0;
        }
        if (!$effectivedeadline) {
            return;
        }

        $effectivedaily = ($override && $override->daily_penalty !== null)
            ? (float) $override->daily_penalty
            : (($groupoverride && $groupoverride->daily_penalty !== null)
                ? (float) $groupoverride->daily_penalty
                : $daily);
        $effectivemax = ($override && $override->max_penalty !== null)
            ? (float) $override->max_penalty
            : (($groupoverride && $groupoverride->max_penalty !== null)
                ? (float) $groupoverride->max_penalty
                : $max);

        // Resolve submission time.
        $submissiontime = penalty_helper::get_submission_time($userid, $cm);
        if ($submissiontime === null) {
            if (!in_array($cm->modname, penalty_helper::$submissionmodules)) {
                // Auto-graded module: use the most recent non-latepenalty history as proxy.
                $row = $DB->get_record_sql(
                    "SELECT MAX(timemodified) AS timemodified
                       FROM {grade_grades_history}
                      WHERE itemid = :itemid
                        AND userid = :userid
                        AND (source IS NULL OR source != 'local_latepenalty')",
                    ['itemid' => $gradeitem->id, 'userid' => $userid]
                );
                $submissiontime = ($row && $row->timemodified !== null) ? (int) $row->timemodified : null;
            }
            if ($submissiontime === null) {
                return;
            }
        }

        // Guard: skip only when a prior latepenalty write exists AND the teacher
        // edited the grade after that write. When no latepenalty entry exists the
        // grade is treated as the unmodified original (e.g. from a course restore).
        $hrow = $DB->get_record_sql(
            "SELECT MAX(CASE WHEN source = 'local_latepenalty'
                             THEN timemodified ELSE NULL END) AS lastpenalty,
                    MAX(CASE WHEN source IS NULL
                               OR source != 'local_latepenalty'
                             THEN timemodified ELSE NULL END) AS lastother
               FROM {grade_grades_history}
              WHERE itemid = :itemid
                AND userid = :userid",
            ['itemid' => $gradeitem->id, 'userid' => $userid]
        );
        $lastpenalty = ($hrow !== null && $hrow->lastpenalty !== null) ? (int) $hrow->lastpenalty : 0;
        $lastother   = ($hrow !== null && $hrow->lastother !== null) ? (int) $hrow->lastother : 0;
        if ($lastpenalty > 0 && $lastother > $lastpenalty) {
            return;
        }

        $dayslate = penalty_helper::calculate_days_late($submissiontime, $effectivedeadline);

        $newfinalgrade = ($dayslate <= 0)
            ? $rawgrade
            : penalty_helper::apply_penalty(
                $rawgrade,
                $dayslate,
                $effectivedaily,
                $effectivemax,
                (float) $gradeitem->grademin
            );

        $currentgrade = (float) ($graderow->finalgrade ?? 0);
        if (abs($newfinalgrade - $currentgrade) < 0.01) {
            return;
        }

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

    /**
     * Recalculate penalties for every member of a group after a group override
     * is saved or deleted.
     *
     * Pre-loads all grade records, overrides, submission times and teacher-edit
     * timestamps in bulk queries, then applies recalculation to each member in
     * a single pass without per-student DB calls.
     *
     * @param int   $cmid    Course module ID.
     * @param int   $groupid Group ID whose members should be recalculated.
     * @param float $daily   Rule daily penalty percentage (fallback when no override).
     * @param float $max     Rule maximum penalty cap percentage (fallback when no override).
     * @return void
     */
    public static function recalculate_for_group(int $cmid, int $groupid, float $daily, float $max): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/gradelib.php');

        $memberids = array_column(
            $DB->get_records('groups_members', ['groupid' => $groupid], '', 'userid'),
            'userid'
        );

        if (empty($memberids)) {
            return;
        }

        $userids = array_map('intval', $memberids);
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $isautograded = !in_array($cm->modname, penalty_helper::$submissionmodules);

        $gradeitem = \grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => $cm->modname,
            'iteminstance' => $cm->instance,
            'courseid'     => $cm->course,
        ]);
        if (!$gradeitem) {
            return;
        }

        $itemid = (int) $gradeitem->id;
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');

        // Pre-load grade records for all group members.
        $graderows = $DB->get_records_sql(
            "SELECT userid, rawgrade, finalgrade, locked
               FROM {grade_grades}
              WHERE itemid = :itemid
                AND userid $insql",
            array_merge(['itemid' => $itemid], $inparams)
        );

        if (empty($graderows)) {
            return;
        }

        // Pre-load plugin user overrides for this CM (filter per-student below).
        $overridesbyuserid = [];
        foreach ($DB->get_records('local_latepenalty_overrides', ['cmid' => $cmid]) as $override) {
            $overridesbyuserid[(int) $override->userid] = $override;
        }

        // Pre-load merged group overrides (reads the just-updated DB state).
        $groupoverridesbyuserid = penalty_helper::get_group_overrides_bulk($cmid, $userids);

        // Pre-load module-native per-student deadline overrides.
        $moduledeadlinesbyuserid = penalty_helper::get_module_user_deadlines_bulk(
            $cm->modname,
            $cm->instance,
            $userids
        );

        $ruledeadline = penalty_helper::get_deadline($cm) ?? 0;

        // Pre-load submission times.
        if ($isautograded) {
            $subrows = $DB->get_records_sql(
                "SELECT userid, MAX(timemodified) AS timemodified
                   FROM {grade_grades_history}
                  WHERE itemid = :itemid
                    AND userid $insql
                    AND source != 'local_latepenalty'
               GROUP BY userid",
                array_merge(['itemid' => $itemid], $inparams)
            );
            $submissiontimesbyuserid = [];
            foreach ($subrows as $subrow) {
                $submissiontimesbyuserid[(int) $subrow->userid] = (int) $subrow->timemodified;
            }
        } else {
            $submissiontimesbyuserid = penalty_helper::get_submission_times_bulk($userids, $cm);
        }

        // Pre-load history timestamps to detect teacher manual overrides.
        $historyrows = $DB->get_records_sql(
            "SELECT userid,
                    MAX(CASE WHEN source = 'local_latepenalty'
                             THEN timemodified ELSE NULL END) AS lastpenalty,
                    MAX(CASE WHEN source IS NULL
                               OR source != 'local_latepenalty'
                             THEN timemodified ELSE NULL END) AS lastother
               FROM {grade_grades_history}
              WHERE itemid = :itemid
                AND userid $insql
              GROUP BY userid",
            array_merge(['itemid' => $itemid], $inparams)
        );
        $historybyuserid = [];
        foreach ($historyrows as $hrow) {
            $historybyuserid[(int) $hrow->userid] = $hrow;
        }

        foreach ($graderows as $graderow) {
            $userid = (int) $graderow->userid;

            if ($graderow->rawgrade === null) {
                continue;
            }

            $rawgrade = (float) $graderow->rawgrade;
            if ($rawgrade <= (float) $gradeitem->grademin) {
                continue;
            }

            if (!empty($graderow->locked) || !empty($gradeitem->locked)) {
                continue;
            }

            // Resolve effective deadline and rates: user override > group override > rule default.
            $override = $overridesbyuserid[$userid] ?? null;
            $groupoverride = $groupoverridesbyuserid[$userid] ?? null;
            if ($override && $override->deadline !== null) {
                $effectivedeadline = (int) $override->deadline;
            } else if ($groupoverride && $groupoverride->deadline !== null) {
                $effectivedeadline = (int) $groupoverride->deadline;
            } else {
                $effectivedeadline = $moduledeadlinesbyuserid[$userid] ?? $ruledeadline;
            }

            if (!$effectivedeadline) {
                continue;
            }

            $effectivedaily = ($override && $override->daily_penalty !== null)
                ? (float) $override->daily_penalty
                : (($groupoverride && $groupoverride->daily_penalty !== null)
                    ? (float) $groupoverride->daily_penalty
                    : $daily);
            $effectivemax = ($override && $override->max_penalty !== null)
                ? (float) $override->max_penalty
                : (($groupoverride && $groupoverride->max_penalty !== null)
                    ? (float) $groupoverride->max_penalty
                    : $max);

            $submissiontime = $submissiontimesbyuserid[$userid] ?? null;
            if ($submissiontime === null) {
                continue;
            }

            // Guard: skip only when a prior latepenalty write exists AND the teacher
            // edited the grade after that write.
            $hrow        = $historybyuserid[$userid] ?? null;
            $lastpenalty = ($hrow !== null && $hrow->lastpenalty !== null) ? (int) $hrow->lastpenalty : 0;
            $lastother   = ($hrow !== null && $hrow->lastother !== null) ? (int) $hrow->lastother : 0;
            if ($lastpenalty > 0 && $lastother > $lastpenalty) {
                continue;
            }

            $dayslate = penalty_helper::calculate_days_late($submissiontime, $effectivedeadline);

            $newfinalgrade = ($dayslate <= 0)
                ? $rawgrade
                : penalty_helper::apply_penalty(
                    $rawgrade,
                    $dayslate,
                    $effectivedaily,
                    $effectivemax,
                    (float) $gradeitem->grademin
                );

            $currentgrade = (float) ($graderow->finalgrade ?? 0);
            if (abs($newfinalgrade - $currentgrade) < 0.01) {
                continue;
            }

            // Register the anti-recursion guard before writing.
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
