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
 * PHPUnit tests for the Late Penalty observer.
 *
 * Covers:
 *  - calculate_days_late(): pure timestamp arithmetic
 *  - apply_penalty(): discount formula and edge cases
 *  - get_submission_time(): forum with no posts returns null
 *  - Full observer chain via assign: no rule, rule disabled, no deadline,
 *    on-time, 1 day late, 2 days late, penalty capped at max
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
 * Tests for local_latepenalty\observer.
 *
 * @covers \local_latepenalty\observer
 */
final class observer_test extends advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    // Helpers: reflection wrappers for private static methods.

    /**
     * Call observer::calculate_days_late() via reflection.
     *
     * @param int $submissiontime
     * @param int $deadline
     * @return int
     */
    private function days_late(int $submissiontime, int $deadline): int {
        $m = new \ReflectionMethod(observer::class, 'calculate_days_late');
        $m->setAccessible(true);
        return (int) $m->invoke(null, $submissiontime, $deadline);
    }

    /**
     * Call observer::apply_penalty() via reflection.
     *
     * @param float $rawgrade
     * @param int $dayslate
     * @param float $daily
     * @param float $max
     * @return float
     */
    private function apply(float $rawgrade, int $dayslate, float $daily, float $max): float {
        $m = new \ReflectionMethod(observer::class, 'apply_penalty');
        $m->setAccessible(true);
        return (float) $m->invoke(null, $rawgrade, $dayslate, $daily, $max);
    }

    /**
     * Call observer::get_submission_time() via reflection.
     *
     * @param int $userid
     * @param \stdClass $cm
     * @return int|null
     */
    private function submission_time(int $userid, \stdClass $cm): ?int {
        $m = new \ReflectionMethod(observer::class, 'get_submission_time');
        $m->setAccessible(true);
        return $m->invoke(null, $userid, $cm);
    }

    // Helpers: integration test infrastructure.

    /**
     * Create a course, a student, an assign module with completionexpected set,
     * a local_latepenalty_rules record and an assign_submission record.
     *
     * @param int $submissionoffset Seconds relative to $deadline for submission time.
     *                             Negative means submitted before deadline.
     * @param bool $ruleenabled    Whether the penalty rule is active.
     * @param float $daily         Daily penalty percentage.
     * @param float $max           Maximum penalty percentage.
     * @param int|null $deadline   Absolute deadline timestamp; defaults to 5 days ago.
     * @return array{course: \stdClass, student: \stdClass, assign: \stdClass,
     *               gradeitem: grade_item, deadline: int}
     */
    private function make_scenario(
        int $submissionoffset,
        bool $ruleenabled = true,
        float $daily = 10.0,
        float $max = 50.0,
        ?int $deadline = null
    ): array {
        global $DB;

        $deadline = $deadline ?? (time() - 5 * DAYSECS);

        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'grade'  => 100,
            'duedate' => 0,
        ]);

        $DB->set_field('course_modules', 'completionexpected', $deadline, ['id' => $assign->cmid]);
        rebuild_course_cache($course->id);

        $this->upsert_rule($assign->cmid, $ruleenabled, $daily, $max);

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
     * Award a grade to a student, triggering the user_graded event and observer.
     *
     * @param array $s  Scenario array returned by setup().
     * @param float $rawgrade
     * @return float The final grade stored in the gradebook after observer ran.
     */
    private function grade_and_read(array $s, float $rawgrade): float {
        $s['gradeitem']->update_final_grade($s['student']->id, $rawgrade, 'test');

        $grade = new grade_grade(['itemid' => $s['gradeitem']->id, 'userid' => $s['student']->id]);
        $grade->load_optional_fields();

        return (float) ($grade->finalgrade ?? 0.0);
    }

    /**
     * Insert or update a penalty rule for the given course module.
     *
     * create_module() already triggers local_latepenalty_coursemodule_edit_post_actions()
     * which inserts a row with enabled=0. Using upsert avoids a duplicate-key error.
     *
     * @param int $cmid
     * @param bool $enabled
     * @param float $daily
     * @param float $max
     * @return void
     */
    private function upsert_rule(int $cmid, bool $enabled, float $daily, float $max): void {
        global $DB;

        $existing = $DB->get_record('local_latepenalty_rules', ['cmid' => $cmid]);
        if ($existing) {
            $existing->enabled       = $enabled ? 1 : 0;
            $existing->daily_penalty = $daily;
            $existing->max_penalty   = $max;
            $DB->update_record('local_latepenalty_rules', $existing);
        } else {
            $DB->insert_record('local_latepenalty_rules', (object) [
                'cmid'          => $cmid,
                'enabled'       => $enabled ? 1 : 0,
                'daily_penalty' => $daily,
                'max_penalty'   => $max,
            ]);
        }
    }

    // Tests for calculate_days_late: pure unit tests (no DB required).

    /**
     * Submission before deadline returns 0.
     */
    public function test_days_late_on_time_returns_zero(): void {
        $deadline = mktime(23, 59, 0, 5, 10, 2026);
        self::assertSame(0, $this->days_late($deadline - 60, $deadline));
    }

    /**
     * Submission exactly at the deadline returns 0.
     */
    public function test_days_late_exactly_on_deadline(): void {
        $deadline = mktime(9, 0, 0, 5, 10, 2026);
        self::assertSame(0, $this->days_late($deadline, $deadline));
    }

    /**
     * One second late rounds up to 1 day.
     */
    public function test_days_late_one_second_rounds_to_one_day(): void {
        $deadline = mktime(9, 0, 0, 5, 10, 2026);
        self::assertSame(1, $this->days_late($deadline + 1, $deadline));
    }

    /**
     * 23h59m late still rounds up to 1 day (ceil, not floor).
     */
    public function test_days_late_partial_day_rounds_up(): void {
        $deadline       = mktime(9, 0, 0, 5, 10, 2026);
        $submissiontime = mktime(8, 59, 0, 5, 11, 2026); // 23h59m late.
        self::assertSame(1, $this->days_late($submissiontime, $deadline));
    }

    /**
     * Exactly 2 days late returns 2.
     */
    public function test_days_late_exactly_two_days(): void {
        $deadline       = mktime(9, 0, 0, 5, 10, 2026);
        $submissiontime = mktime(9, 0, 0, 5, 12, 2026);
        self::assertSame(2, $this->days_late($submissiontime, $deadline));
    }

    // Tests for apply_penalty: pure unit tests (no DB required).

    /**
     * 1 day late, 10%/day: 100 → 90.
     */
    public function test_penalty_one_day_ten_percent(): void {
        self::assertEqualsWithDelta(90.0, $this->apply(100.0, 1, 10.0, 50.0), 0.01);
    }

    /**
     * 2 days late, 10%/day: 100 → 80.
     */
    public function test_penalty_two_days_ten_percent(): void {
        self::assertEqualsWithDelta(80.0, $this->apply(100.0, 2, 10.0, 50.0), 0.01);
    }

    /**
     * 10 days × 10%/day = 100%, capped at 50%: 100 → 50.
     */
    public function test_penalty_capped_at_max(): void {
        self::assertEqualsWithDelta(50.0, $this->apply(100.0, 10, 10.0, 50.0), 0.01);
    }

    /**
     * 100% maximum cap: grade never goes below zero.
     */
    public function test_penalty_never_negative(): void {
        self::assertEqualsWithDelta(0.0, $this->apply(100.0, 20, 10.0, 100.0), 0.01);
    }

    /**
     * Penalty applies correctly to non-round grades.
     */
    public function test_penalty_non_round_grade(): void {
        // 75.5 × 0.9 = 67.95.
        self::assertEqualsWithDelta(67.95, $this->apply(75.5, 1, 10.0, 50.0), 0.01);
    }

    // Tests for get_submission_time: targeted reflection tests.

    /**
     * Forum with no posts returns null (no submission to penalise).
     */
    public function test_forum_no_posts_returns_null(): void {
        global $DB;

        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $forum   = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        $cm = (object) ['modname' => 'forum', 'instance' => $forum->id];

        self::assertNull($this->submission_time($student->id, $cm));
    }

    /**
     * Assign with a submitted record returns the submission timemodified.
     */
    public function test_assign_submission_time_returned(): void {
        global $DB;

        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $assign  = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $expected = mktime(14, 30, 0, 5, 11, 2026);
        $DB->insert_record('assign_submission', (object) [
            'assignment'    => $assign->id,
            'userid'        => $student->id,
            'timecreated'   => $expected,
            'timemodified'  => $expected,
            'status'        => 'submitted',
            'groupid'       => 0,
            'attemptnumber' => 0,
            'latest'        => 1,
        ]);

        $cm = (object) ['modname' => 'assign', 'instance' => $assign->id];

        self::assertSame($expected, $this->submission_time($student->id, $cm));
    }

    /**
     * Assign with no submissions returns null.
     */
    public function test_assign_no_submission_returns_null(): void {
        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $assign  = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $cm = (object) ['modname' => 'assign', 'instance' => $assign->id];

        self::assertNull($this->submission_time($student->id, $cm));
    }

    // Full observer chain: integration tests via assign.

    /**
     * No penalty rule → grade unchanged.
     */
    public function test_no_rule_grade_unchanged(): void {
        global $DB;

        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $assign  = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 100]);

        $deadline = time() - 5 * DAYSECS;
        $DB->set_field('course_modules', 'completionexpected', $deadline, ['id' => $assign->cmid]);
        rebuild_course_cache($course->id);

        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => $student->id,
            'timecreated' => $deadline + DAYSECS, 'timemodified' => $deadline + DAYSECS,
            'status' => 'submitted', 'groupid' => 0, 'attemptnumber' => 0, 'latest' => 1,
        ]);

        $gradeitem = grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $assign->id, 'courseid' => $course->id,
        ]);
        $gradeitem->update_final_grade($student->id, 80.0, 'test');

        $grade = new grade_grade(['itemid' => $gradeitem->id, 'userid' => $student->id]);
        $grade->load_optional_fields();

        self::assertEqualsWithDelta(80.0, (float) $grade->finalgrade, 0.01);
    }

    /**
     * Rule exists but is disabled → grade unchanged.
     */
    public function test_rule_disabled_grade_unchanged(): void {
        $s = $this->make_scenario(DAYSECS, false);
        self::assertEqualsWithDelta(80.0, $this->grade_and_read($s, 80.0), 0.01);
    }

    /**
     * No deadline configured on the module → grade unchanged.
     */
    public function test_no_deadline_grade_unchanged(): void {
        global $DB;

        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $assign  = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'grade' => 100, 'duedate' => 0,
        ]);

        // Both completionexpected = 0 and duedate = 0 means no deadline at all.
        $DB->set_field('course_modules', 'completionexpected', 0, ['id' => $assign->cmid]);
        $DB->set_field('assign', 'duedate', 0, ['id' => $assign->id]);
        rebuild_course_cache($course->id);

        $this->upsert_rule($assign->cmid, true, 10.0, 50.0);
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => $student->id,
            'timecreated' => time(), 'timemodified' => time(),
            'status' => 'submitted', 'groupid' => 0, 'attemptnumber' => 0, 'latest' => 1,
        ]);

        $gradeitem = grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $assign->id, 'courseid' => $course->id,
        ]);
        $gradeitem->update_final_grade($student->id, 80.0, 'test');

        $grade = new grade_grade(['itemid' => $gradeitem->id, 'userid' => $student->id]);
        $grade->load_optional_fields();

        self::assertEqualsWithDelta(80.0, (float) $grade->finalgrade, 0.01);
    }

    /**
     * Submitted 1 day before deadline → grade unchanged.
     */
    public function test_on_time_submission_no_penalty(): void {
        $s = $this->make_scenario(-DAYSECS);
        self::assertEqualsWithDelta(100.0, $this->grade_and_read($s, 100.0), 0.01);
    }

    /**
     * 1 second late → ceil = 1 day → 10% discount: 100 → 90.
     */
    public function test_one_second_late_applies_one_day_penalty(): void {
        $s = $this->make_scenario(1);
        self::assertEqualsWithDelta(90.0, $this->grade_and_read($s, 100.0), 0.01);
    }

    /**
     * Exactly 1 day late → 10% discount: 100 → 90.
     */
    public function test_one_day_late_applies_penalty(): void {
        $s = $this->make_scenario(DAYSECS);
        self::assertEqualsWithDelta(90.0, $this->grade_and_read($s, 100.0), 0.01);
    }

    /**
     * Exactly 2 days late → 20% discount: 100 → 80.
     */
    public function test_two_days_late_applies_penalty(): void {
        $s = $this->make_scenario(2 * DAYSECS);
        self::assertEqualsWithDelta(80.0, $this->grade_and_read($s, 100.0), 0.01);
    }

    /**
     * 10 days late → would be 100%, capped at 50%: 100 → 50.
     */
    public function test_penalty_capped_at_max_fifty_percent(): void {
        $s = $this->make_scenario(10 * DAYSECS);
        self::assertEqualsWithDelta(50.0, $this->grade_and_read($s, 100.0), 0.01);
    }

    /**
     * Forum with no posts: professor grades anyway → no penalty (nothing to measure).
     */
    public function test_forum_no_posts_observer_skips_penalty(): void {
        global $DB;

        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        $forum    = $this->getDataGenerator()->create_module('forum', [
            'course'       => $course->id,
            'grade_forum'  => 100,
        ]);
        $deadline = time() - 5 * DAYSECS;
        $DB->set_field('course_modules', 'completionexpected', $deadline, ['id' => $forum->cmid]);
        rebuild_course_cache($course->id);

        $this->upsert_rule($forum->cmid, true, 10.0, 50.0);

        // No forum posts — professor grades the student directly.
        $gradeitem = grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'forum',
            'iteminstance' => $forum->id, 'courseid' => $course->id,
        ]);
        $gradeitem->update_final_grade($student->id, 80.0, 'test');

        $grade = new grade_grade(['itemid' => $gradeitem->id, 'userid' => $student->id]);
        $grade->load_optional_fields();

        self::assertEqualsWithDelta(
            80.0,
            (float) $grade->finalgrade,
            0.01,
            'Grade should not be penalised when student has no forum posts.'
        );
    }
}
