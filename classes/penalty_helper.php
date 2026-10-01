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
 * Shared penalty calculation helpers used by the observer and the recalculator.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty;

/**
 * Static helpers for grade items, dates and grade maths.
 */
class penalty_helper {
    /**
     * Whether late penalties apply to a grade item.
     *
     * Every numeric grade item of an activity is penalised (forum ratings and
     * whole-forum grading alike), except outcome items and the workshop
     * assessment item (itemnumber 1), which grades how the student assessed
     * peers rather than work the student handed in. Scale and "none" items are
     * never penalised: a percentage of a position in a scale means nothing, and
     * the core's own late penalty also applies to numeric grades only.
     *
     * @param \grade_item $gradeitem Grade item.
     * @return bool
     */
    public static function is_penalisable_item(\grade_item $gradeitem): bool {
        if ($gradeitem->itemtype !== 'mod' || !empty($gradeitem->outcomeid)) {
            return false;
        }
        if ((int) $gradeitem->gradetype !== GRADE_TYPE_VALUE) {
            return false;
        }
        return !($gradeitem->itemmodule === 'workshop' && (int) $gradeitem->itemnumber === 1);
    }

    /**
     * Course modules, among the given ones, that have at least one penalisable grade item.
     *
     * @param \stdClass[] $cms Course modules (id, modname, instance) of one course.
     * @param int $courseid Course ID.
     * @return array True for each course module ID with a penalisable item.
     */
    public static function cms_with_penalisable_items(array $cms, int $courseid): array {
        global $DB;

        $rows = $DB->get_recordset_select(
            'grade_items',
            "courseid = :courseid AND itemtype = 'mod' AND outcomeid IS NULL AND gradetype = :gradetype",
            ['courseid' => $courseid, 'gradetype' => GRADE_TYPE_VALUE],
            '',
            'id, itemmodule, iteminstance, itemnumber'
        );
        $found = [];
        foreach ($rows as $row) {
            if (!($row->itemmodule === 'workshop' && (int) $row->itemnumber === 1)) {
                $found[$row->itemmodule][(int) $row->iteminstance] = true;
            }
        }
        $rows->close();

        $result = [];
        foreach ($cms as $cm) {
            if (!empty($found[$cm->modname][(int) $cm->instance])) {
                $result[(int) $cm->id] = true;
            }
        }
        return $result;
    }

    /**
     * Assignments, among the given ones, where the core late penalty is in charge.
     *
     * Mirrors \mod_assign\penalty\helper::is_penalty_enabled() (Moodle 5.0+): the
     * assignment penalty is enabled site-wide, and the assignment has a due date,
     * a numeric grade and its own "Apply penalty" setting on. Late Penalty then
     * steps aside so the two never discount the same grade.
     *
     * @param int[] $assignids Assignment IDs.
     * @return array True for each assignment ID with the core penalty on.
     */
    public static function native_penalty_assignments(array $assignids): array {
        global $DB;

        $available = class_exists(\mod_assign\penalty\helper::class)
            && class_exists(\core_grades\penalty_manager::class)
            && \core_grades\penalty_manager::is_penalty_enabled_for_module('assign');
        if (!$available || empty($assignids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($assignids, SQL_PARAMS_NAMED, 'asg');
        $ids = $DB->get_fieldset_select('assign', 'id', "id $insql AND duedate > 0 AND grade > 0 AND gradepenalty = 1", $params);
        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * Whether the core late penalty is in charge of an activity (assignments only).
     *
     * @param \stdClass $cm Course module (modname, instance).
     * @return bool
     */
    public static function native_penalty_active(\stdClass $cm): bool {
        return $cm->modname === 'assign' && !empty(self::native_penalty_assignments([(int) $cm->instance]));
    }

    /**
     * Grade items of an activity that late penalties apply to, ordered by item number.
     *
     * Activities may own several grade items (workshop, forum with ratings and
     * whole-forum grading), so callers must never look the item up by module
     * alone: grade_item::fetch() throws when more than one item matches.
     *
     * @param \stdClass $cm Course module (modname, instance, course).
     * @return \grade_item[]
     */
    public static function get_penalisable_items(\stdClass $cm): array {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $items = \grade_item::fetch_all([
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
            'courseid' => $cm->course,
        ]) ?: [];

        $items = array_values(array_filter($items, [self::class, 'is_penalisable_item']));
        usort($items, fn(\grade_item $a, \grade_item $b): int => (int) $a->itemnumber <=> (int) $b->itemnumber);

        return $items;
    }

    /**
     * Where a resolved deadline comes from, in words.
     *
     * @param \local_latepenalty\local\deadline $deadline Resolved deadline.
     * @return string
     */
    public static function deadline_origin_label(\local_latepenalty\local\deadline $deadline): string {
        $label = get_string('deadline_origin_' . $deadline->origin, 'local_latepenalty');
        if (!$deadline->exists() && $deadline->origin !== \local_latepenalty\local\deadline_resolver::ORIGIN_NONE) {
            // An override removed the due date for this student.
            return get_string('deadline_origin_exempt', 'local_latepenalty', $label);
        }
        return $label;
    }

    /**
     * Format a deadline timestamp as a locale-aware date and time string.
     *
     * Combines core's own per-language date and time format strings (e.g. the
     * en_us langpack overrides strftimedatefullshort to %m/%d/%y, while en
     * keeps %d/%m/%y) rather than a single hardcoded field order, so every
     * installed language renders the correct day/month/year order on its
     * own. Only the separator between the two parts is this plugin's own.
     *
     * @param int $timestamp Deadline timestamp.
     * @return string Formatted date and time, e.g. "12/08/26 - 09:07".
     */
    public static function format_deadline(int $timestamp): string {
        $date = userdate($timestamp, get_string('strftimedatefullshort', 'langconfig'));
        $time = userdate($timestamp, get_string('strftimetime24', 'langconfig'));

        return get_string('deadline_datetime', 'local_latepenalty', (object) [
            'date' => $date,
            'time' => $time,
        ]);
    }

    /**
     * Calculate the number of days a submission is late.
     *
     * @param int $submissiontime Timestamp when the student submitted.
     * @param int $deadline       Deadline timestamp.
     * @return int Number of days late (0 if on time).
     */
    public static function calculate_days_late(int $submissiontime, int $deadline): int {
        if ($submissiontime <= $deadline) {
            return 0;
        }

        return (int) ceil(($submissiontime - $deadline) / DAYSECS);
    }

    /**
     * Apply the late penalty to a raw grade.
     *
     * @param float $rawgrade     Original grade before penalty.
     * @param int   $dayslate     Number of days late.
     * @param float $dailypenalty Daily penalty percentage (0–100).
     * @param float $maxpenalty   Maximum penalty cap percentage (0–100).
     * @param float $grademin     Minimum allowed grade (floor). Defaults to 0.
     * @return float Final grade after penalty (never below $grademin).
     */
    public static function apply_penalty(
        float $rawgrade,
        int $dayslate,
        float $dailypenalty,
        float $maxpenalty,
        float $grademin = 0.0
    ): float {
        $discountpct = min($dayslate * $dailypenalty, $maxpenalty);
        $finalgrade  = $rawgrade * (1.0 - $discountpct / 100.0);

        return max($grademin, $finalgrade);
    }
}
