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
 * PHPUnit tests for the Late Penalty recalculator.
 *
 * Covers recalculator::recalculate() scenarios:
 *  - Extended deadline reduces an existing penalty
 *  - Extended deadline past submission restores the raw grade
 *  - Changed daily rate recalculates the penalty
 *  - Students with no penalty history record are left untouched
 *  - Per-user override: override deadline, override daily rate, override max cap
 *
 * @package    local_latepenalty
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty;

use advanced_testcase;
use grade_grade;
use grade_item;

/**
 * Tests for local_latepenalty\recalculator.
 *
 * @covers \local_latepenalty\recalculator
 */
final class recalculator_test extends advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    // Helpers.

    /**
     * Insert or update a penalty rule for the given course module.
     *
     * @param int   $cmid    Course module ID.
     * @param bool  $enabled Whether the rule is active.
     * @param float $daily   Daily penalty percentage.
     * @param float $max     Maximum penalty cap percentage.
     * @return void
     */
    private function upsert_rule(int $cmid, bool $enabled, float $daily, float $max): void {
        global $DB;

        $existing = $DB->get_record('local_latepenalty_rules', ['cmid' => $cmid]);
        if ($existing) {
            $existing->enabled              = $enabled ? 1 : 0;
            $existing->daily_penalty        = $daily;
            $existing->max_penalty          = $max;
            $existing->recalc_on_deadline   = 1;
            $existing->recalc_on_rate       = 1;
            $DB->update_record('local_latepenalty_rules', $existing);
        } else {
            $DB->insert_record('local_latepenalty_rules', (object) [
                'cmid'                => $cmid,
                'enabled'             => $enabled ? 1 : 0,
                'daily_penalty'       => $daily,
                'max_penalty'         => $max,
                'recalc_on_deadline'  => 1,
                'recalc_on_rate'      => 1,
                'last_deadline'       => 0,
            ]);
        }
    }

    /**
     * Create a course, student, assign module, submission record, and penalty rule.
     *
     * The deadline is fixed at 5 days in the past. $submissionoffset is relative to that.
     *
     * @param int   $submissionoffset Seconds from deadline (positive = late).
     * @param float $daily            Daily penalty percentage.
     * @param float $max              Maximum penalty cap percentage.
     * @return array{course: \stdClass, student: \stdClass, assign: \stdClass,
     *               gradeitem: grade_item, deadline: int}
     */
    private function make_scenario(
        int $submissionoffset,
        float $daily = 10.0,
        float $max = 50.0
    ): array {
        global $DB;

        $deadline = time() - 5 * DAYSECS;

        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course'  => $course->id,
            'grade'   => 100,
            'duedate' => 0,
        ]);

        $DB->set_field('course_modules', 'completionexpected', $deadline, ['id' => $assign->cmid]);
        rebuild_course_cache($course->id);

        $this->upsert_rule($assign->cmid, true, $daily, $max);

        $submissiontime = $deadline + $submissionoffset;
        $DB->insert_record('assign_submission', (object) [
            'assignment'    => $assign->id,
            'userid'        => $student->id,
            'timecreated'   => $submissiontime,
            'timemodified'  => $submissiontime,
            'status'        => 'submitted',
            'groupid'       => 0,
            'attemptnumber' => 0,
            'latest'        => 1,
        ]);

        $gradeitem = grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'assign',
            'iteminstance' => $assign->id,
            'courseid'     => $course->id,
        ]);

        return [
            'course'    => $course,
            'student'   => $student,
            'assign'    => $assign,
            'gradeitem' => $gradeitem,
            'deadline'  => $deadline,
        ];
    }

    /**
     * Submit a grade via the module pathway so rawgrade is persisted in grade_grades.
     *
     * Using update_raw_grade() (not update_final_grade()) ensures that
     * grade_grades.rawgrade is set to the teacher's value and remains unchanged
     * when the observer later calls update_final_grade() to apply the penalty.
     * This mirrors what activity modules do in production.
     *
     * @param array $s     Scenario array returned by make_scenario().
     * @param float $grade Raw grade to submit.
     * @return void
     */
    private function grade_via_module(array $s, float $grade): void {
        $s['gradeitem']->update_raw_grade($s['student']->id, $grade, 'mod/assign');
    }

    /**
     * Read the current finalgrade for the student in a scenario.
     *
     * @param array $s Scenario array returned by make_scenario().
     * @return float
     */
    private function read_final_grade(array $s): float {
        $grade = new grade_grade(['itemid' => $s['gradeitem']->id, 'userid' => $s['student']->id]);
        $grade->load_optional_fields();
        return (float) ($grade->finalgrade ?? 0.0);
    }

    /**
     * Insert or update a per-user penalty override for the given course module.
     *
     * @param int        $cmid     Course module ID.
     * @param int        $userid   User ID.
     * @param int|null   $deadline Custom deadline timestamp; null = inherit from activity.
     * @param float|null $daily    Custom daily penalty percentage; null = inherit from rule.
     * @param float|null $max      Custom maximum penalty percentage; null = inherit from rule.
     * @return void
     */
    private function upsert_override(int $cmid, int $userid, ?int $deadline, ?float $daily, ?float $max): void {
        global $DB;

        $existing = $DB->get_record('local_latepenalty_overrides', ['cmid' => $cmid, 'userid' => $userid]);
        if ($existing) {
            $existing->deadline      = $deadline;
            $existing->daily_penalty = $daily;
            $existing->max_penalty   = $max;
            $existing->timemodified  = time();
            $DB->update_record('local_latepenalty_overrides', $existing);
        } else {
            $DB->insert_record('local_latepenalty_overrides', (object) [
                'cmid'         => $cmid,
                'userid'       => $userid,
                'deadline'     => $deadline,
                'daily_penalty' => $daily,
                'max_penalty'  => $max,
                'timecreated'  => time(),
                'timemodified' => time(),
            ]);
        }
    }

    // Tests.

    /**
     * Extending the deadline reduces the accumulated penalty.
     *
     * Student was 3 days late (30% off → 70). Deadline extended by 1 day makes
     * the student 2 days late (20% off → 80).
     */
    public function test_extended_deadline_reduces_penalty(): void {
        $s = $this->make_scenario(3 * DAYSECS);
        $this->grade_via_module($s, 100.0);

        $newdeadline = $s['deadline'] + DAYSECS;
        recalculator::recalculate($s['assign']->cmid, $newdeadline, 10.0, 50.0);

        self::assertEqualsWithDelta(80.0, $this->read_final_grade($s), 0.01);
    }

    /**
     * Extending the deadline past the submission time restores the raw grade.
     *
     * Student was 1 second late (1-day penalty → 90). Deadline extended by 2 days
     * makes the student on time → raw grade 100 is restored.
     */
    public function test_extended_deadline_restores_ontime_grade(): void {
        $s = $this->make_scenario(1);
        $this->grade_via_module($s, 100.0);

        $newdeadline = $s['deadline'] + 2 * DAYSECS;
        recalculator::recalculate($s['assign']->cmid, $newdeadline, 10.0, 50.0);

        self::assertEqualsWithDelta(100.0, $this->read_final_grade($s), 0.01);
    }

    /**
     * Reducing the daily rate recalculates the penalty with the new rate.
     *
     * Student was 2 days late at 10%/day (20% off → 80). Rate drops to 5%/day
     * (10% off → 90).
     */
    public function test_rate_change_recalculates_penalty(): void {
        $s = $this->make_scenario(2 * DAYSECS);
        $this->grade_via_module($s, 100.0);

        recalculator::recalculate($s['assign']->cmid, $s['deadline'], 5.0, 50.0);

        self::assertEqualsWithDelta(90.0, $this->read_final_grade($s), 0.01);
    }

    // Override scenarios: recalculator must respect per-user overrides.

    /**
     * Recalculation uses override deadline instead of the new rule deadline.
     *
     * Student submitted at rule_deadline + 3 days. Override deadline = rule_deadline + 1 day,
     * so student is 2 days late relative to override. Recalculate passes new rule deadline
     * = rule_deadline + 2 days; without override that yields 1 day late (90), but the
     * override locks the effective deadline at +1 day → 2 days late → 20% off → 80.
     */
    public function test_recalculate_respects_override_deadline(): void {
        $s = $this->make_scenario(3 * DAYSECS);
        $this->grade_via_module($s, 100.0);

        $this->upsert_override($s['assign']->cmid, $s['student']->id, $s['deadline'] + DAYSECS, null, null);

        recalculator::recalculate($s['assign']->cmid, $s['deadline'] + 2 * DAYSECS, 10.0, 50.0);

        self::assertEqualsWithDelta(80.0, $this->read_final_grade($s), 0.01);
    }

    /**
     * Recalculation uses override daily rate instead of the new rule rate.
     *
     * Student 2 days late. Override daily = 5%/day. Recalculate with rule rate = 15%/day;
     * the override yields 2 × 5% = 10% off → 90 (not 2 × 15% = 30% off → 70).
     */
    public function test_recalculate_respects_override_daily_rate(): void {
        $s = $this->make_scenario(2 * DAYSECS);
        $this->grade_via_module($s, 100.0);

        $this->upsert_override($s['assign']->cmid, $s['student']->id, null, 5.0, null);

        recalculator::recalculate($s['assign']->cmid, $s['deadline'], 15.0, 50.0);

        self::assertEqualsWithDelta(90.0, $this->read_final_grade($s), 0.01);
    }

    /**
     * Recalculation uses override max cap instead of the new rule max.
     *
     * Student 10 days late at 10%/day. Override max = 20%. Recalculate with rule max = 80%;
     * the override caps the penalty at 20% → 80 (not 80% off → 20).
     */
    public function test_recalculate_respects_override_max(): void {
        $s = $this->make_scenario(10 * DAYSECS);
        $this->grade_via_module($s, 100.0);

        $this->upsert_override($s['assign']->cmid, $s['student']->id, null, null, 20.0);

        recalculator::recalculate($s['assign']->cmid, $s['deadline'], 10.0, 80.0);

        self::assertEqualsWithDelta(80.0, $this->read_final_grade($s), 0.01);
    }

    /**
     * Students with no penalty history record are not touched by recalculate().
     *
     * The student submitted on time so the observer never wrote a
     * grade_grades_history row with source = 'local_latepenalty'. The
     * recalculator must leave the grade unchanged.
     */
    public function test_ontime_student_not_affected(): void {
        $s = $this->make_scenario(-DAYSECS);
        $this->grade_via_module($s, 80.0);

        recalculator::recalculate($s['assign']->cmid, $s['deadline'], 10.0, 50.0);

        self::assertEqualsWithDelta(80.0, $this->read_final_grade($s), 0.01);
    }
}
