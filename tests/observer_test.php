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
 *  - format_deadline(): combines the locale's date and time strings via the
 *    plugin's own separator string, rather than a hardcoded format
 *  - submission time lookup: forum with no posts, h5pactivity without a grade,
 *    assign individual and team submissions
 *  - Full observer chain via assign: no rule, rule disabled, no deadline,
 *    on-time, 1 day late, 2 days late, penalty capped at max
 *  - Full observer chain via quiz: 1 day late
 *  - Full observer chain via h5pactivity: late (event-timestamp fallback) and on-time
 *  - Per-user override: custom deadline, custom daily rate, custom max cap,
 *    penalty waived (daily = 0), all-null override inherits rule
 *
 * The deadline chain itself is covered by local/deadline_resolver_test.php.
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
use local_latepenalty\local\submission_resolver;

/**
 * Tests for local_latepenalty\observer and \local_latepenalty\penalty_helper.
 *
 * @covers \local_latepenalty\observer
 * @covers \local_latepenalty\penalty_helper
 */
final class observer_test extends advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    // Helpers: thin wrappers around penalty_helper public methods.

    /**
     * Delegate to penalty_helper::calculate_days_late().
     *
     * @param int $submissiontime
     * @param int $deadline
     * @return int
     */
    private function days_late(int $submissiontime, int $deadline): int {
        return penalty_helper::calculate_days_late($submissiontime, $deadline);
    }

    /**
     * Delegate to penalty_helper::apply_penalty().
     *
     * @param float $rawgrade
     * @param int $dayslate
     * @param float $daily
     * @param float $max
     * @return float
     */
    private function apply(float $rawgrade, int $dayslate, float $daily, float $max): float {
        return penalty_helper::apply_penalty($rawgrade, $dayslate, $daily, $max);
    }

    /**
     * Submission time of a student as the plugin resolves it for one grade item.
     *
     * @param int $userid Student ID.
     * @param \stdClass $cm Object with modname and instance.
     * @param int $itemnumber Grade item number.
     * @return int|null
     */
    private function submission_time(int $userid, \stdClass $cm, int $itemnumber = 0): ?int {
        $cm = get_coursemodule_from_instance($cm->modname, $cm->instance, 0, false, MUST_EXIST);
        $gradeitem = grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
            'itemnumber' => $itemnumber,
            'courseid' => $cm->course,
        ]);
        return submission_resolver::for_user($cm, $gradeitem, $userid);
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
        $s['gradeitem']->update_raw_grade($s['student']->id, $rawgrade, 'mod/assign');

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

    // Tests for format_deadline: locale-aware date+time combination.

    /**
     * format_deadline() must combine the current locale's own short-date and
     * 24-hour time strings (strftimedatefullshort / strftimetime24 from
     * langconfig) via the plugin's own deadline_datetime string, never a
     * format hardcoded in code. This pins the exact bug fixed this session:
     * an earlier version used a plugin-owned literal '%d/%m/%y - %H:%M',
     * which silently ignored languages that override the field order (e.g.
     * en_us renders month/day instead of day/month).
     *
     * The PHPUnit test environment only ships the 'en' language pack, so
     * this cannot assert the per-locale field order actually differs — that
     * was verified manually, across en/en_us/es/fr/pt_br, in a real browser
     * this session. What this test does verify is the wiring: the exact
     * core strings combined, in the exact order, via the exact plugin
     * string — a regression here (wrong string, wrong order, dropped
     * placeholder) fails even though the literal 'en' output can't change.
     */
    public function test_format_deadline_combines_locale_date_and_time(): void {
        $timestamp = mktime(9, 7, 0, 8, 12, 2027);

        $expected = get_string('deadline_datetime', 'local_latepenalty', (object) [
            'date' => userdate($timestamp, get_string('strftimedatefullshort', 'langconfig')),
            'time' => userdate($timestamp, get_string('strftimetime24', 'langconfig')),
        ]);

        self::assertSame($expected, penalty_helper::format_deadline($timestamp));
    }

    /**
     * The combined string must contain a dash-separated, 24-hour clock time
     * component — a structural guard, independent of locale, that catches a
     * regression dropping the time portion entirely and reverting to a
     * date-only display.
     */
    public function test_format_deadline_contains_dash_separated_time(): void {
        $timestamp = mktime(9, 7, 0, 8, 12, 2027);

        $result = penalty_helper::format_deadline($timestamp);

        self::assertStringContainsString(' - ', $result);
        self::assertMatchesRegularExpression('/\d{2}:\d{2}/', $result);
    }

    // Tests for the submission time lookup.

    /**
     * Forum with no posts returns null (no submission to penalise).
     */
    public function test_forum_no_posts_returns_null(): void {
        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $forum   = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'grade_forum' => 100]);

        $cm = (object) ['modname' => 'forum', 'instance' => $forum->id];

        self::assertNull($this->submission_time($student->id, $cm, 1));
    }

    /**
     * h5pactivity without a grade has nothing reported to the gradebook; it returns null.
     *
     * For modules without their own lookup the submission time is what the
     * module reports with the grade, so no grade means no submission time.
     */
    public function test_h5pactivity_submission_time_returns_null(): void {
        $this->setAdminUser();
        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $h5p     = $this->getDataGenerator()->create_module('h5pactivity', ['course' => $course->id]);

        $cm = (object) ['modname' => 'h5pactivity', 'instance' => $h5p->id, 'course' => $course->id];

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

        $cm = (object) ['modname' => 'assign', 'instance' => $assign->id, 'course' => $course->id];

        self::assertNull($this->submission_time($student->id, $cm));
    }

    /**
     * Assign team submission (userid = 0, groupid = X) returns the group submission timestamp.
     */
    public function test_assign_team_submission_time_returned(): void {
        global $DB;

        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $assign  = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);

        $expected = mktime(14, 30, 0, 5, 11, 2026);
        $DB->insert_record('assign_submission', (object) [
            'assignment'    => $assign->id,
            'userid'        => 0,
            'groupid'       => $group->id,
            'timecreated'   => $expected,
            'timemodified'  => $expected,
            'status'        => 'submitted',
            'attemptnumber' => 0,
            'latest'        => 1,
        ]);

        $cm = (object) ['modname' => 'assign', 'instance' => $assign->id, 'course' => $course->id];

        self::assertSame($expected, $this->submission_time($student->id, $cm));
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
        $gradeitem->update_raw_grade($student->id, 80.0, 'mod/assign');

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
        $gradeitem->update_raw_grade($student->id, 80.0, 'mod/assign');

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
     * Reopen the assignment for a student and record a new submitted attempt.
     *
     * Mirrors assign's "reopen submission" flow: the previous attempt stops being the latest
     * one and a new attempt row is created.
     *
     * @param array $s Scenario array returned by make_scenario().
     * @param int $submissiontime Timestamp of the new attempt.
     * @return void
     */
    private function add_resubmission(array $s, int $submissiontime): void {
        global $DB;

        $DB->set_field('assign_submission', 'latest', 0, [
            'assignment' => $s['assign']->id,
            'userid'     => $s['student']->id,
        ]);
        $DB->insert_record('assign_submission', (object) [
            'assignment'    => $s['assign']->id,
            'userid'        => $s['student']->id,
            'timecreated'   => $submissiontime,
            'timemodified'  => $submissiontime,
            'status'        => 'submitted',
            'groupid'       => 0,
            'attemptnumber' => 1,
            'latest'        => 1,
        ]);
    }

    /**
     * On-time submission graded, then resubmitted late: the penalty is applied only at the regrade.
     *
     * The plugin listens to user_graded, so a late resubmission changes nothing until the teacher
     * grades again. At that point the last submission time is what is measured against the deadline.
     */
    public function test_late_resubmission_penalised_only_when_regraded(): void {
        // First attempt one day before the deadline: graded with no penalty.
        $s = $this->make_scenario(-DAYSECS);
        self::assertEqualsWithDelta(100.0, $this->grade_and_read($s, 100.0), 0.01);

        // Reopened and resubmitted two days after the deadline; nobody has regraded yet.
        $this->add_resubmission($s, $s['deadline'] + 2 * DAYSECS);
        $grade = new grade_grade(['itemid' => $s['gradeitem']->id, 'userid' => $s['student']->id]);
        self::assertEqualsWithDelta(100.0, (float) $grade->finalgrade, 0.01);

        // The teacher regrades the new attempt: 2 days late at 10%/day → 90 becomes 72.
        self::assertEqualsWithDelta(72.0, $this->grade_and_read($s, 90.0), 0.01);
    }

    /**
     * Manually marking the activity as complete on time does not shield a late resubmission.
     *
     * The plugin never reads the completion state to measure lateness; only the last submission
     * time and the resolved deadline matter.
     */
    public function test_manual_completion_does_not_shield_late_resubmission(): void {
        global $DB;

        $s = $this->make_scenario(-DAYSECS);
        self::assertEqualsWithDelta(100.0, $this->grade_and_read($s, 100.0), 0.01);

        $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid'  => $s['assign']->cmid,
            'userid'          => $s['student']->id,
            'completionstate' => COMPLETION_COMPLETE,
            'viewed'          => 0,
            'overrideby'      => null,
            'timemodified'    => $s['deadline'] - DAYSECS,
        ]);

        $this->add_resubmission($s, $s['deadline'] + DAYSECS);

        // 1 day late at 10%/day → 90 becomes 81.
        self::assertEqualsWithDelta(81.0, $this->grade_and_read($s, 90.0), 0.01);
    }

    /**
     * When completionexpected is 0, the deadline comes from assign.duedate.
     *
     * This exercises the activity due date step of the deadline chain.
     */
    public function test_deadline_resolved_from_module_duedate(): void {
        global $DB;

        $deadline = time() - 5 * DAYSECS;

        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course'  => $course->id,
            'grade'   => 100,
            'duedate' => $deadline,
        ]);

        // Setting completionexpected = 0 forces the fallback to assign.duedate.
        $DB->set_field('course_modules', 'completionexpected', 0, ['id' => $assign->cmid]);
        rebuild_course_cache($course->id);

        $this->upsert_rule($assign->cmid, true, 10.0, 50.0);

        $submissiontime = $deadline + DAYSECS;
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
        $gradeitem->update_raw_grade($student->id, 100.0, 'mod/assign');

        $grade = new grade_grade(['itemid' => $gradeitem->id, 'userid' => $student->id]);
        $grade->load_optional_fields();

        // 1 day late at 10%/day → 90.
        self::assertEqualsWithDelta(
            90.0,
            (float) $grade->finalgrade,
            0.01,
            'Penalty should apply when deadline is read from assign.duedate (completionexpected=0).'
        );
    }

    /**
     * Team submission 1 day late → 10% penalty applied through the full observer chain.
     *
     * The assign is configured with teamsubmission = 1. The submission record has
     * userid = 0 and groupid = <group>. The plugin must resolve the group submission
     * timestamp and penalise the student accordingly.
     */
    public function test_team_submission_penalty_applied(): void {
        global $DB;

        $deadline = time() - 5 * DAYSECS;

        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course'         => $course->id,
            'grade'          => 100,
            'duedate'        => 0,
            'teamsubmission' => 1,
        ]);

        $DB->set_field('course_modules', 'completionexpected', $deadline, ['id' => $assign->cmid]);
        rebuild_course_cache($course->id);

        $this->upsert_rule($assign->cmid, true, 10.0, 50.0);

        $submissiontime = $deadline + DAYSECS;
        $DB->insert_record('assign_submission', (object) [
            'assignment'    => $assign->id,
            'userid'        => 0,
            'groupid'       => $group->id,
            'timecreated'   => $submissiontime,
            'timemodified'  => $submissiontime,
            'status'        => 'submitted',
            'attemptnumber' => 0,
            'latest'        => 1,
        ]);

        $gradeitem = grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'assign',
            'iteminstance' => $assign->id,
            'courseid'     => $course->id,
        ]);
        $gradeitem->update_raw_grade($student->id, 100.0, 'mod/assign');

        $grade = new grade_grade(['itemid' => $gradeitem->id, 'userid' => $student->id]);
        $grade->load_optional_fields();

        self::assertEqualsWithDelta(
            90.0,
            (float) $grade->finalgrade,
            0.01,
            'Penalty must apply when submission is a group record (userid = 0).'
        );
    }

    // Full observer chain: integration test via quiz.

    /**
     * Full observer chain via quiz: attempt finishes 1 day late → 10% penalty applied.
     *
     * Simulates the quiz grading path: inserts a finished quiz_attempts record with
     * timefinish = deadline + 1 day, then submits the grade via update_raw_grade()
     * (matching how the quiz module calls grade_update internally). Verifies the
     * observer reads quiz_attempts.timefinish as submission time and applies the penalty.
     */
    public function test_quiz_one_day_late_applies_penalty(): void {
        global $DB;

        $deadline = time() - 5 * DAYSECS;

        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course'    => $course->id,
            'grade'     => 100,
            'timeclose' => 0,
        ]);

        $DB->set_field('course_modules', 'completionexpected', $deadline, ['id' => $quiz->cmid]);
        rebuild_course_cache($course->id);

        $this->upsert_rule($quiz->cmid, true, 10.0, 50.0);

        $submissiontime = $deadline + DAYSECS;

        // Create question_usages record to satisfy quiz_attempts.uniqueid FK constraint.
        $modulecontext = \context_module::instance($quiz->cmid);
        $qubaid = $DB->insert_record('question_usages', (object) [
            'contextid'          => $modulecontext->id,
            'component'          => 'mod_quiz',
            'preferredbehaviour' => 'deferredfeedback',
        ]);

        $DB->insert_record('quiz_attempts', (object) [
            'quiz'         => $quiz->id,
            'userid'       => $student->id,
            'attempt'      => 1,
            'uniqueid'     => $qubaid,
            'layout'       => '',
            'currentpage'  => 0,
            'preview'      => 0,
            'state'        => 'finished',
            'timestart'    => $submissiontime - 3600,
            'timefinish'   => $submissiontime,
            'timemodified' => $submissiontime,
            'sumgrades'    => 10.0,
        ]);

        $gradeitem = grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'quiz',
            'iteminstance' => $quiz->id,
            'courseid'     => $course->id,
        ]);
        $gradeitem->update_raw_grade($student->id, 100.0, 'mod/quiz');

        $grade = new grade_grade(['itemid' => $gradeitem->id, 'userid' => $student->id]);
        $grade->load_optional_fields();

        // 1 day late at 10%/day → 10% off → 90.
        self::assertEqualsWithDelta(
            90.0,
            (float) $grade->finalgrade,
            0.01,
            'Quiz: 1 day late at 10%/day must reduce grade from 100 to 90.'
        );
    }

    // Full observer chain: integration tests via h5pactivity.

    /**
     * Full observer chain via h5pactivity: graded after deadline → penalty applied.
     *
     * h5pactivity has no submission table. The observer falls back to the grade-event
     * timestamp as a proxy for the submission time. completionexpected is set 5 days in
     * the past; the event fires "now", so the student is ~5 days late. At 10%/day with
     * a 50% cap: 50% off → grade 100 → 50.
     */
    public function test_h5pactivity_late_applies_penalty(): void {
        global $DB;

        $this->setAdminUser();
        $deadline = time() - 5 * DAYSECS;

        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        $h5p = $this->getDataGenerator()->create_module('h5pactivity', [
            'course' => $course->id,
            'grade'  => 100,
        ]);

        $DB->set_field('course_modules', 'completionexpected', $deadline, ['id' => $h5p->cmid]);
        rebuild_course_cache($course->id);

        $this->upsert_rule($h5p->cmid, true, 10.0, 50.0);

        $gradeitem = grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'h5pactivity',
            'iteminstance' => $h5p->id,
            'courseid'     => $course->id,
        ]);
        $gradeitem->update_raw_grade($student->id, 100.0, 'mod/h5pactivity');

        $grade = new grade_grade(['itemid' => $gradeitem->id, 'userid' => $student->id]);
        $grade->load_optional_fields();

        // Event timestamp ≈ now = ~5 days after deadline → 50% cap at 10%/day → 50.
        self::assertEqualsWithDelta(
            50.0,
            (float) $grade->finalgrade,
            0.01,
            'H5P: penalty must be applied when the grade event fires after completionexpected.'
        );
    }

    /**
     * Full observer chain via h5pactivity: graded before deadline → no penalty.
     *
     * completionexpected is set 10 days in the future. The grade event fires "now",
     * which is before the deadline → 0 days late → grade unchanged.
     */
    public function test_h5pactivity_on_time_no_penalty(): void {
        global $DB;

        $this->setAdminUser();
        $deadline = time() + 10 * DAYSECS;

        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        $h5p = $this->getDataGenerator()->create_module('h5pactivity', [
            'course' => $course->id,
            'grade'  => 100,
        ]);

        $DB->set_field('course_modules', 'completionexpected', $deadline, ['id' => $h5p->cmid]);
        rebuild_course_cache($course->id);

        $this->upsert_rule($h5p->cmid, true, 10.0, 50.0);

        $gradeitem = grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'h5pactivity',
            'iteminstance' => $h5p->id,
            'courseid'     => $course->id,
        ]);
        $gradeitem->update_raw_grade($student->id, 100.0, 'mod/h5pactivity');

        $grade = new grade_grade(['itemid' => $gradeitem->id, 'userid' => $student->id]);
        $grade->load_optional_fields();

        self::assertEqualsWithDelta(
            100.0,
            (float) $grade->finalgrade,
            0.01,
            'H5P: grade must not be penalised when the event fires before completionexpected.'
        );
    }

    // Override scenarios: observer must respect per-user overrides.

    /**
     * Override deadline shifts lateness: student 3 days late by rule, 2 days late by override.
     *
     * Rule deadline is 5 days ago. Override deadline = rule + 1 day (4 days ago).
     * Student submitted at rule + 3 days (2 days ago) → 2 days late → 20% off: 100 → 80.
     */
    public function test_override_deadline_reduces_lateness(): void {
        $s = $this->make_scenario(3 * DAYSECS);
        $this->upsert_override($s['assign']->cmid, $s['student']->id, $s['deadline'] + DAYSECS, null, null);
        self::assertEqualsWithDelta(80.0, $this->grade_and_read($s, 100.0), 0.01);
    }

    /**
     * Override deadline makes an otherwise-late submission on time.
     *
     * Rule deadline is 5 days ago. Student submitted 1 second after it (1-day penalty).
     * Override extends deadline by 2 days → student is now on time → grade unchanged.
     */
    public function test_override_deadline_makes_ontime(): void {
        $s = $this->make_scenario(1);
        $this->upsert_override($s['assign']->cmid, $s['student']->id, $s['deadline'] + 2 * DAYSECS, null, null);
        self::assertEqualsWithDelta(100.0, $this->grade_and_read($s, 100.0), 0.01);
    }

    /**
     * Override daily rate: student 1 day late, override 5%/day instead of rule's 10%.
     *
     * 1 × 5% = 5% off → 95.
     */
    public function test_override_daily_rate(): void {
        $s = $this->make_scenario(DAYSECS);
        $this->upsert_override($s['assign']->cmid, $s['student']->id, null, 5.0, null);
        self::assertEqualsWithDelta(95.0, $this->grade_and_read($s, 100.0), 0.01);
    }

    /**
     * Override max cap: student 10 days late at 10%/day, override caps penalty at 20%.
     *
     * Without override: 10 × 10% = 100% → capped at 50% → 50.
     * With override max = 20%: 100% → capped at 20% → 80.
     */
    public function test_override_max_penalty(): void {
        $s = $this->make_scenario(10 * DAYSECS);
        $this->upsert_override($s['assign']->cmid, $s['student']->id, null, null, 20.0);
        self::assertEqualsWithDelta(80.0, $this->grade_and_read($s, 100.0), 0.01);
    }

    /**
     * Override with daily_penalty = 0 waives the penalty entirely.
     */
    public function test_override_waives_penalty(): void {
        $s = $this->make_scenario(DAYSECS);
        $this->upsert_override($s['assign']->cmid, $s['student']->id, null, 0.0, null);
        self::assertEqualsWithDelta(100.0, $this->grade_and_read($s, 100.0), 0.01);
    }

    /**
     * Override with all null fields inherits rule values — identical to no override.
     *
     * Rule: 10%/day, max 50%. 1 day late → 90.
     */
    public function test_override_null_fields_inherit_rule(): void {
        $s = $this->make_scenario(DAYSECS);
        $this->upsert_override($s['assign']->cmid, $s['student']->id, null, null, null);
        self::assertEqualsWithDelta(90.0, $this->grade_and_read($s, 100.0), 0.01);
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

    // Cleanup on course module deletion.

    /**
     * Deleting a course module removes the plugin's rule, per-user override and
     * per-group override rows for that cmid, and leaves other activities untouched.
     *
     * Exercises the real deletion call (not a hand-rolled event trigger) so the
     * assertion covers the actual deletion path, including context removal
     * happening before the event fires. course_delete_module() is deprecated
     * since Moodle 5.2 (MDL-86856) in favour of
     * core_courseformat\local\cmactions::delete(), but that method does not
     * exist yet on Moodle 4.5, which this plugin still supports — guarded with
     * method_exists() rather than a hardcoded version check.
     */
    public function test_course_module_deleted_cleans_up_plugin_tables(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $assign  = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $group   = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $this->upsert_rule($assign->cmid, true, 10.0, 50.0);
        $this->upsert_override($assign->cmid, $student->id, null, 5.0, null);
        $DB->insert_record('local_latepenalty_group_overrides', (object) [
            'cmid'          => $assign->cmid,
            'groupid'       => $group->id,
            'deadline'      => null,
            'daily_penalty' => null,
            'max_penalty'   => null,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);

        // A second, untouched activity proves the cleanup is scoped to the deleted cmid.
        $otherassign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $this->upsert_rule($otherassign->cmid, true, 10.0, 50.0);

        if (method_exists(\core_courseformat\local\cmactions::class, 'delete')) {
            \core_courseformat\formatactions::cm($course->id)->delete($assign->cmid);
        } else {
            course_delete_module($assign->cmid);
        }

        self::assertSame(0, $DB->count_records('local_latepenalty_rules', ['cmid' => $assign->cmid]));
        self::assertSame(0, $DB->count_records('local_latepenalty_overrides', ['cmid' => $assign->cmid]));
        self::assertSame(0, $DB->count_records('local_latepenalty_group_overrides', ['cmid' => $assign->cmid]));

        self::assertSame(1, $DB->count_records('local_latepenalty_rules', ['cmid' => $otherassign->cmid]));
    }

    /**
     * Deleting the whole course removes the plugin's rows for every course module
     * in it, even though remove_course_contents() never fires course_module_deleted.
     *
     * Regression guard for the gap found in course_module_deleted() alone: a
     * whole-course delete_course() call does not go through course_delete_module()
     * for its activities, so only the before_course_deleted hook catches this path.
     */
    public function test_course_deleted_cleans_up_plugin_tables(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $assign  = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $group   = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $this->upsert_rule($assign->cmid, true, 10.0, 50.0);
        $this->upsert_override($assign->cmid, $student->id, null, 5.0, null);
        $DB->insert_record('local_latepenalty_group_overrides', (object) [
            'cmid'          => $assign->cmid,
            'groupid'       => $group->id,
            'deadline'      => null,
            'daily_penalty' => null,
            'max_penalty'   => null,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);

        // A second course, untouched, proves the cleanup is scoped to the deleted course.
        $othercourse = $this->getDataGenerator()->create_course();
        $otherassign = $this->getDataGenerator()->create_module('assign', ['course' => $othercourse->id]);
        $this->upsert_rule($otherassign->cmid, true, 10.0, 50.0);

        delete_course($course, false);

        self::assertSame(0, $DB->count_records('local_latepenalty_rules', ['cmid' => $assign->cmid]));
        self::assertSame(0, $DB->count_records('local_latepenalty_overrides', ['cmid' => $assign->cmid]));
        self::assertSame(0, $DB->count_records('local_latepenalty_group_overrides', ['cmid' => $assign->cmid]));

        self::assertSame(1, $DB->count_records('local_latepenalty_rules', ['cmid' => $otherassign->cmid]));
    }
}
