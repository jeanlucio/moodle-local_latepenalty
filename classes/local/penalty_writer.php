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

namespace local_latepenalty\local;

/**
 * Writes, changes and removes a late penalty on a grade.
 *
 * Two storage paths, chosen by what the core supports:
 *  - deducted mark (core with MDL-88407: 5.1+ updated branches): the raw grade
 *    stays as the module wrote it, grade_grades.deductedmark holds the penalty in
 *    raw points and the final grade is rebuilt from both, exactly as
 *    \core_grades\penalty_manager does. The core keeps the penalty through
 *    regrades and clears it whenever the module writes a new raw grade, so a
 *    better attempt reaches the plugin at once.
 *  - overridden final grade (4.5, 5.0, 5.1/5.2 without the fix): the penalised
 *    final grade is written as an override, as before v1.2.0. A raw grade that
 *    changes later fires no event (the final grade is locked by the override),
 *    so the reprocess_grades scheduled task picks those grades up.
 *
 * Every write is logged in the grade history with this plugin as the source;
 * the report and the ownership checks below rely on it. The writer never
 * touches timecreated/timemodified (the submission and grading dates the module
 * reported), unlike penalty_manager, because the plugin reads them back.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class penalty_writer {
    /** @var string Grade history source of every write of this plugin. */
    public const SOURCE = 'local_latepenalty';

    /** @var bool|null Forced storage path for tests: true deducted mark, false override, null detect. */
    private static ?bool $forceddeductedmark = null;

    /** @var array Expected final grades (float or null) of our own pending user_graded events, keyed "userid_itemid". */
    private static array $pending = [];

    /**
     * Whether penalties are stored as a deducted mark, on the site or in one course.
     *
     * Detected by the method the MDL-88407 fix added, never by version number or by
     * the deductedmark column: Moodle 5.0 has the column, but its regrade ignores
     * the deducted mark, which would loop forever. A course whose gradebook is
     * frozen at a pre-fix calculation version also ignores the deducted mark of any
     * module but the assignment when it regrades, so it uses the override path.
     *
     * @param int|null $courseid Course ID, or null for the site-wide answer.
     * @return bool
     */
    public static function uses_deducted_mark(?int $courseid = null): bool {
        $supported = self::core_supports_deducted_mark();
        if (self::$forceddeductedmark !== null) {
            $supported = self::$forceddeductedmark && $supported;
        }
        if (!$supported || $courseid === null) {
            return $supported;
        }
        return !\core_grades\penalty_manager::is_frozen_for_legacy_penalty($courseid);
    }

    /**
     * Whether the core has the fixed penalty_manager (MDL-88407).
     *
     * @return bool
     */
    public static function core_supports_deducted_mark(): bool {
        return method_exists(\core_grades\penalty_manager::class, 'apply_grade_item_factors');
    }

    /**
     * Force a storage path (tests only), so the override path also runs where the core has the fix.
     *
     * @param bool|null $deductedmark True or false to force, null to detect again.
     * @return void
     */
    public static function force_path_for_tests(?bool $deductedmark): void {
        if (!PHPUNIT_TEST) {
            throw new \coding_exception('force_path_for_tests() is for PHPUnit only.');
        }
        self::$forceddeductedmark = $deductedmark;
    }

    /**
     * Forget forced paths and pending echoes (tests only).
     *
     * @return void
     */
    public static function reset_for_tests(): void {
        if (!PHPUNIT_TEST) {
            throw new \coding_exception('reset_for_tests() is for PHPUnit only.');
        }
        self::$forceddeductedmark = null;
        self::$pending = [];
    }

    /**
     * Whether a user_graded event is the echo of our own write, consuming the mark.
     *
     * @param int $userid User ID.
     * @param int $itemid Grade item ID.
     * @param float|null $finalgrade Final grade carried by the event.
     * @return bool
     */
    public static function is_own_echo(int $userid, int $itemid, ?float $finalgrade): bool {
        $key = $userid . '_' . $itemid;
        if (!array_key_exists($key, self::$pending)) {
            return false;
        }
        $expected = self::$pending[$key];
        unset(self::$pending[$key]);
        return !self::differs($finalgrade, $expected);
    }

    /**
     * Which overridden grades are overrides written by this plugin, by user.
     *
     * A grade is ours when its current final grade is the one of our latest
     * write and nothing written after it changed the final grade: a teacher edit
     * changes the final grade, a new raw grade from the module does not (the
     * override keeps it). Without grade history nothing can be proved, so no
     * override counts as ours and teacher edits stay safe.
     *
     * @param \grade_item $gradeitem Grade item.
     * @param array $grades grade_grades rows (userid, finalgrade, overridden) keyed by user ID.
     * @return array True for each user ID whose override is ours.
     */
    public static function own_overrides(\grade_item $gradeitem, array $grades): array {
        global $DB;

        $userids = [];
        foreach ($grades as $userid => $grade) {
            if (!empty($grade->overridden)) {
                $userids[] = (int) $userid;
            }
        }
        if (empty($userids)) {
            return [];
        }

        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        $rs = $DB->get_recordset_sql(
            "SELECT h.id, h.userid, h.source, h.finalgrade
               FROM {grade_grades_history} h
              WHERE h.itemid = :itemid
                AND h.userid $usql
                AND h.id >= (SELECT MAX(h2.id)
                               FROM {grade_grades_history} h2
                              WHERE h2.itemid = h.itemid
                                AND h2.userid = h.userid
                                AND h2.source = :source)
           ORDER BY h.userid, h.id",
            ['itemid' => $gradeitem->id, 'source' => self::SOURCE] + $uparams
        );
        $ours = [];
        $broken = [];
        $reference = [];
        foreach ($rs as $row) {
            $userid = (int) $row->userid;
            if (!array_key_exists($userid, $reference)) {
                // First row of the user is our latest write.
                $reference[$userid] = $row->finalgrade;
                continue;
            }
            if (self::differs($row->finalgrade, $reference[$userid])) {
                $broken[$userid] = true;
            }
        }
        $rs->close();

        foreach ($reference as $userid => $finalgrade) {
            if (empty($broken[$userid]) && !self::differs($grades[$userid]->finalgrade, $finalgrade)) {
                $ours[$userid] = true;
            }
        }
        return $ours;
    }

    /**
     * Set the penalty of one grade, in raw grade points; 0 removes it.
     *
     * Does nothing when the grade already carries that penalty.
     *
     * @param \grade_item $gradeitem Grade item.
     * @param \grade_grade $grade The user's grade, with a raw grade.
     * @param float $points Penalty in raw grade points.
     * @return bool Whether the grade changed.
     */
    public static function apply(\grade_item $gradeitem, \grade_grade $grade, float $points): bool {
        if ($grade->rawgrade === null) {
            // The module removed the grade: only an override of ours can still hold a value.
            return self::apply_override($gradeitem, $grade, 0.0, null);
        }
        $raw = (float) $grade->rawgrade;
        $points = max(0.0, min($points, $raw - (float) $gradeitem->grademin));
        $finalgrade = $gradeitem->adjust_raw_grade($raw - $points, $grade->rawgrademin, $grade->rawgrademax);

        if (self::uses_deducted_mark((int) $gradeitem->courseid)) {
            return self::apply_deducted_mark($gradeitem, $grade, $points, $finalgrade);
        }
        return self::apply_override($gradeitem, $grade, $points, $finalgrade);
    }

    /**
     * Deducted mark path: same write as \core_grades\penalty_manager (non-legacy branch).
     *
     * @param \grade_item $gradeitem Grade item.
     * @param \grade_grade $grade Grade.
     * @param float $points Penalty in raw points.
     * @param float $finalgrade Resulting final grade.
     * @return bool Whether the grade changed.
     */
    private static function apply_deducted_mark(
        \grade_item $gradeitem,
        \grade_grade $grade,
        float $points,
        float $finalgrade
    ): bool {
        $oldfinalgrade = $grade->finalgrade;
        $samemark = !self::differs((float) $grade->deductedmark, $points);
        if ($samemark && !self::differs($oldfinalgrade, $finalgrade)) {
            return false;
        }

        $grade->deductedmark = $points;
        $grade->finalgrade = $finalgrade;
        $grade->update(self::SOURCE);

        if (self::differs($oldfinalgrade, $finalgrade)) {
            self::$pending[$grade->userid . '_' . $gradeitem->id] = (float) $finalgrade;
            \core\event\user_graded::create_from_grade($grade)->trigger();
            self::regrade_parents($gradeitem, (int) $grade->userid);
        }
        return true;
    }

    /**
     * Override path: penalised final grade as an override; removing the penalty lifts the override.
     *
     * @param \grade_item $gradeitem Grade item.
     * @param \grade_grade $grade Grade.
     * @param float $points Penalty in raw points.
     * @param float|null $finalgrade Resulting final grade (null when the module removed the grade).
     * @return bool Whether the grade changed.
     */
    private static function apply_override(
        \grade_item $gradeitem,
        \grade_grade $grade,
        float $points,
        ?float $finalgrade
    ): bool {
        $userid = (int) $grade->userid;

        if ($points <= 0) {
            if (empty($grade->overridden)) {
                return false;
            }
            $oldfinalgrade = $grade->finalgrade;
            $grade->overridden = 0;
            $grade->finalgrade = $finalgrade;
            $grade->update(self::SOURCE);
            if (self::differs($oldfinalgrade, $finalgrade)) {
                self::$pending[$userid . '_' . $gradeitem->id] = $finalgrade;
                \core\event\user_graded::create_from_grade($grade)->trigger();
                self::regrade_parents($gradeitem, $userid);
            }
            return true;
        }

        if (!empty($grade->overridden) && !self::differs($grade->finalgrade, $finalgrade)) {
            return false;
        }
        self::$pending[$userid . '_' . $gradeitem->id] = (float) $gradeitem->bounded_grade($finalgrade);
        // Pass the grade's own date: without it, core stamps the time of this write, and modules that
        // report no submission date have their submission time read from it in later recalculations.
        $timemodified = empty($grade->timemodified) ? null : (int) $grade->timemodified;
        $gradeitem->update_final_grade($userid, $finalgrade, self::SOURCE, false, FORMAT_MOODLE, null, $timemodified, true);
        return true;
    }

    /**
     * Regrade the parent category and course totals of one user, as penalty_manager does.
     *
     * @param \grade_item $gradeitem Grade item.
     * @param int $userid User ID.
     * @return void
     */
    private static function regrade_parents(\grade_item $gradeitem, int $userid): void {
        $courseitem = \grade_item::fetch_course_item($gradeitem->courseid);
        if ($gradeitem->needsupdate || $courseitem->needsupdate) {
            return;
        }
        $parent = \grade_item::fetch([
            'itemtype' => 'category',
            'iteminstance' => $gradeitem->categoryid,
            'courseid' => $gradeitem->courseid,
        ]) ?: $courseitem;
        if (grade_regrade_final_grades($gradeitem->courseid, $userid, $parent) !== true) {
            $parent->force_regrading();
        }
    }

    /**
     * Whether two grade values differ (null-aware).
     *
     * @param mixed $a First value.
     * @param mixed $b Second value.
     * @return bool
     */
    private static function differs($a, $b): bool {
        if ($a === null || $b === null) {
            return $a !== $b;
        }
        return grade_floats_different((float) $a, (float) $b);
    }
}
