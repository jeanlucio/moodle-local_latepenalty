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
 * Computes and writes late penalties for one or many students.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty;

use local_latepenalty\local\deadline_resolver;
use local_latepenalty\local\penalty_writer;
use local_latepenalty\local\submission_resolver;

/**
 * The single penalty engine behind the observer, the recalculations and the scheduled task.
 *
 * Every path goes through run(), so a grade gets the same result whichever
 * of them reaches it: deadline from deadline_resolver, submission time from
 * submission_resolver, storage by penalty_writer.
 *
 * Grades the engine never touches: locked grades and items, grades without a
 * raw grade, and overrides that are not the plugin's own (teacher edits, and
 * any override at all where penalties are stored as a deducted mark, including
 * overrides written by versions before 1.2.0).
 */
class recalculator {
    /** @var string[] Deadline origins that are the activity's own date rather than a per-student one. */
    private const ACTIVITY_ORIGINS = [
        deadline_resolver::ORIGIN_DUEDATE,
        deadline_resolver::ORIGIN_REMINDER,
        deadline_resolver::ORIGIN_NONE,
    ];

    /**
     * Recalculate the students already penalised in an activity after its rule or deadline changed.
     *
     * Only students with a previous penalty are reconsidered, so moving the
     * activity deadline earlier never penalises students who were on time. A
     * new deadline of 0 (the activity no longer has one) gives back the original
     * grade to every student left without an effective deadline; students with
     * an override or extension of their own follow it.
     *
     * @param int   $cmid        Course module ID.
     * @param int   $newdeadline New activity deadline (0: none); per-student overrides and extensions still win.
     * @param float $daily       New daily penalty percentage.
     * @param float $max         New maximum penalty cap percentage.
     * @return void
     */
    public static function recalculate(int $cmid, int $newdeadline, float $daily, float $max): void {
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        foreach (penalty_helper::get_penalisable_items($cm) as $gradeitem) {
            self::run($cm, $gradeitem, self::penalised_users($gradeitem), $daily, $max, $newdeadline);
        }
    }

    /**
     * Give back the original grade to every student the plugin penalised in an activity (rule disabled).
     *
     * Teacher edits and locked grades stay as they are.
     *
     * @param int $cmid Course module ID.
     * @return void
     */
    public static function restore(int $cmid): void {
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        foreach (penalty_helper::get_penalisable_items($cm) as $gradeitem) {
            self::run($cm, $gradeitem, self::penalised_users($gradeitem), 0.0, 0.0, null, true);
        }
    }

    /**
     * Apply the rule to every student with a grade in an activity (rule enabled).
     *
     * @param int   $cmid  Course module ID.
     * @param float $daily Rule daily penalty percentage.
     * @param float $max   Rule maximum penalty cap percentage.
     * @return void
     */
    public static function recalculate_all(int $cmid, float $daily, float $max): void {
        global $DB;

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        foreach (penalty_helper::get_penalisable_items($cm) as $gradeitem) {
            $userids = $DB->get_fieldset_select(
                'grade_grades',
                'userid',
                'itemid = :itemid AND rawgrade IS NOT NULL',
                ['itemid' => $gradeitem->id]
            );
            self::run($cm, $gradeitem, $userids, $daily, $max, null);
        }
    }

    /**
     * Whether the plugin has ever written a penalty in an activity.
     *
     * @param int $cmid Course module ID.
     * @return bool
     */
    public static function has_penalised(int $cmid): bool {
        global $DB;

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $itemids = array_map(fn(\grade_item $item): int => (int) $item->id, penalty_helper::get_penalisable_items($cm));
        if (empty($itemids)) {
            return false;
        }
        [$insql, $params] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'item');
        return $DB->record_exists_select(
            'grade_grades_history',
            "itemid $insql AND source = :source",
            $params + ['source' => penalty_writer::SOURCE]
        );
    }

    /**
     * Students the plugin has written a penalty for on a grade item.
     *
     * @param \grade_item $gradeitem Grade item.
     * @return int[]
     */
    private static function penalised_users(\grade_item $gradeitem): array {
        global $DB;

        return $DB->get_fieldset_sql(
            "SELECT DISTINCT userid
               FROM {grade_grades_history}
              WHERE itemid = :itemid
                AND source = :source",
            ['itemid' => $gradeitem->id, 'source' => penalty_writer::SOURCE]
        );
    }

    /**
     * Recalculate one student, e.g. after an override of that student changed.
     *
     * @param int   $cmid   Course module ID.
     * @param int   $userid Student user ID.
     * @param float $daily  Rule daily penalty percentage.
     * @param float $max    Rule maximum penalty cap percentage.
     * @return void
     */
    public static function recalculate_for_student(int $cmid, int $userid, float $daily, float $max): void {
        self::recalculate_users($cmid, [$userid], $daily, $max);
    }

    /**
     * Recalculate every member of a group, e.g. after a group override changed.
     *
     * @param int   $cmid    Course module ID.
     * @param int   $groupid Group ID whose members should be recalculated.
     * @param float $daily   Rule daily penalty percentage (fallback when no override).
     * @param float $max     Rule maximum penalty cap percentage (fallback when no override).
     * @return void
     */
    public static function recalculate_for_group(int $cmid, int $groupid, float $daily, float $max): void {
        global $DB;

        $userids = $DB->get_fieldset_select('groups_members', 'userid', 'groupid = :groupid', ['groupid' => $groupid]);
        self::recalculate_users($cmid, $userids, $daily, $max);
    }

    /**
     * Recalculate a set of students of one activity, every penalisable grade item.
     *
     * @param int   $cmid    Course module ID.
     * @param int[] $userids Student IDs.
     * @param float $daily   Rule daily penalty percentage (fallback when no override).
     * @param float $max     Rule maximum penalty cap percentage (fallback when no override).
     * @return void
     */
    public static function recalculate_users(int $cmid, array $userids, float $daily, float $max): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        if (empty($userids)) {
            return;
        }
        $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        foreach (penalty_helper::get_penalisable_items($cm) as $gradeitem) {
            self::run($cm, $gradeitem, $userids, $daily, $max, null);
        }
    }

    /**
     * Compute and write the penalty of one grade item for a set of students.
     *
     * @param \stdClass   $cm                Course module.
     * @param \grade_item $gradeitem         Penalisable grade item of that module.
     * @param int[]       $userids           Student IDs.
     * @param float       $daily             Rule daily penalty (overrides may replace it per student).
     * @param float       $max               Rule penalty cap (overrides may replace it per student).
     * @param int|null    $activitydeadline  New activity deadline replacing the activity's own date, if any.
     * @param bool        $restore           Give back the original grades instead of computing penalties.
     * @return void
     */
    public static function run(
        \stdClass $cm,
        \grade_item $gradeitem,
        array $userids,
        float $daily,
        float $max,
        ?int $activitydeadline,
        bool $restore = false
    ): void {
        global $DB;

        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (empty($userids) || $gradeitem->is_locked() || !penalty_helper::is_penalisable_item($gradeitem)) {
            return;
        }
        if (penalty_helper::native_penalty_active($cm)) {
            // The core assignment penalty is in charge; grades penalised before stay as they are.
            return;
        }

        $grades = [];
        foreach (\grade_grade::fetch_users_grades($gradeitem, $userids, false) as $userid => $grade) {
            // The item is already known (and checked above): attach it so no grade loads it again.
            $grade->grade_item = $gradeitem;
            if (empty($grade->locked)) {
                $grades[(int) $userid] = $grade;
            }
        }

        $deductedmark = penalty_writer::uses_deducted_mark((int) $gradeitem->courseid);
        $ownoverrides = $deductedmark ? [] : penalty_writer::own_overrides($gradeitem, $grades);
        foreach (array_keys($grades) as $userid) {
            $ours = !empty($ownoverrides[$userid]);
            if (!empty($grades[$userid]->overridden) && !$ours) {
                unset($grades[$userid]);
            } else if ($grades[$userid]->rawgrade === null) {
                // No grade from the module: only an override of ours is left to lift.
                if ($ours) {
                    penalty_writer::apply($gradeitem, $grades[$userid], 0.0);
                }
                unset($grades[$userid]);
            }
        }
        if (empty($grades)) {
            return;
        }

        $ids = array_keys($grades);
        $deadlines = deadline_resolver::for_users($cm, $ids);
        $submissions = submission_resolver::for_users($cm, $gradeitem, $ids);
        $keepbest = (bool) $DB->get_field('local_latepenalty_rules', 'keepbest', ['cmid' => $cm->id]);
        $candidates = $restore ? null : submission_resolver::best_candidates($cm, $gradeitem, $ids, $keepbest);

        foreach ($grades as $userid => $grade) {
            if ($restore) {
                penalty_writer::apply($gradeitem, $grade, 0.0);
                continue;
            }
            $submissiontime = $submissions[$userid] ?? null;
            if ($submissiontime === null) {
                continue;
            }

            $resolved = $deadlines[$userid];
            $deadline = $resolved->time;
            if ($activitydeadline !== null && in_array($resolved->origin, self::ACTIVITY_ORIGINS, true)) {
                $deadline = $activitydeadline;
            }

            $raw = (float) $grade->rawgrade;
            $points = 0.0;
            if ($deadline > 0) {
                $rates = [$resolved->daily ?? $daily, $resolved->max ?? $max, (float) $gradeitem->grademin];
                if (!empty($candidates[$userid])) {
                    // Highest grade: the best grade after each attempt's own penalty (F15).
                    $best = null;
                    foreach ($candidates[$userid] as [$candidateraw, $candidatetime]) {
                        $value = self::penalised($candidateraw, $candidatetime, $deadline, $rates);
                        $best = $best === null ? $value : max($best, $value);
                    }
                    $points = max(0.0, $raw - $best);
                } else {
                    $points = $raw - self::penalised($raw, $submissiontime, $deadline, $rates);
                }
            }

            penalty_writer::apply($gradeitem, $grade, $points);
        }
    }

    /**
     * A grade after the penalty for its submission time.
     *
     * @param float $raw Raw grade.
     * @param int $time Submission time.
     * @param int $deadline Deadline.
     * @param array $rates [daily penalty, penalty cap, grade minimum].
     * @return float
     */
    private static function penalised(float $raw, int $time, int $deadline, array $rates): float {
        [$daily, $max, $grademin] = $rates;
        $dayslate = penalty_helper::calculate_days_late($time, $deadline);
        if ($dayslate <= 0) {
            return $raw;
        }
        return penalty_helper::apply_penalty($raw, $dayslate, $daily, $max, $grademin);
    }
}
