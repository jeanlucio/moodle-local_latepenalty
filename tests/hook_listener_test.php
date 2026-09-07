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
 * PHPUnit tests for the Late Penalty hook listener.
 *
 * @package    local_latepenalty
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty;

use advanced_testcase;
use core\hook\output\before_standard_footer_html_generation;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Tests for local_latepenalty\hook_listener.
 *
 * @covers \local_latepenalty\hook_listener
 */
final class hook_listener_test extends advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Insert an enabled penalty rule for a course module.
     *
     * @param int $cmid Course module ID.
     * @return void
     */
    private function enable_rule(int $cmid): void {
        global $DB;

        // Create_module() already inserted a disabled rule via
        // local_latepenalty_coursemodule_edit_post_actions(); upsert to avoid a
        // duplicate-key error on the unique cmid index.
        $existing = $DB->get_record('local_latepenalty_rules', ['cmid' => $cmid]);
        if ($existing) {
            $existing->enabled = 1;
            $existing->daily_penalty = 10.0;
            $existing->max_penalty = 50.0;
            $DB->update_record('local_latepenalty_rules', $existing);
            return;
        }

        $DB->insert_record('local_latepenalty_rules', (object) [
            'cmid'               => $cmid,
            'enabled'            => 1,
            'daily_penalty'      => 10.0,
            'max_penalty'        => 50.0,
            'recalc_on_deadline' => 1,
            'recalc_on_rate'     => 1,
            'last_deadline'      => 0,
        ]);
    }

    /**
     * Builds a hook instance without running its constructor.
     *
     * inject_course_notices() never reads the hook's own state (it only uses the
     * type hint to declare when it runs), so a real renderer_base is unnecessary
     * scaffolding for this test.
     *
     * @return before_standard_footer_html_generation
     */
    private function make_hook(): before_standard_footer_html_generation {
        $class = new ReflectionClass(before_standard_footer_html_generation::class);

        return $class->newInstanceWithoutConstructor();
    }

    /**
     * Reads the AMD inline JS code queued on $PAGE->requires via reflection.
     *
     * js_call_amd() only appends to this protected array; no theme/output
     * bootstrap is required to read it back.
     *
     * @return string Concatenated AMD inline JS code.
     */
    private function amd_code(): string {
        global $PAGE;

        $property = new ReflectionProperty($PAGE->requires, 'amdjscode');
        $property->setAccessible(true);

        return implode("\n", $property->getValue($PAGE->requires));
    }

    /**
     * A hidden activity's cmid, deadline and penalty rate must not appear in the
     * course-notices payload sent to a student, even though the underlying query
     * joins course_modules without any visibility filter.
     *
     * Regression guard: the AMD payload is serialized straight into the page for
     * every enabled rule in the course, so a student could otherwise read the
     * existence, deadline and penalty policy of an activity the course UI hides
     * from them (hidden, stealth, or access-restricted).
     */
    public function test_hidden_activity_excluded_from_student_payload(): void {
        global $DB, $PAGE;

        $course = $this->getDataGenerator()->create_course();

        // Set the theme-affecting $PAGE state before any enrolment call: enrolling a
        // user can trigger a course-welcome-message send, whose HTML rendering lazily
        // initialises $PAGE's theme via get_renderer() — after which moodle_page::
        // set_course() throws "the theme has already been set up for this page".
        $PAGE->set_course($course);
        $PAGE->set_pagetype('course-view-topics');

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $deadline = time() - 5 * DAYSECS;

        $visible = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $DB->set_field('course_modules', 'completionexpected', $deadline, ['id' => $visible->cmid]);
        $this->enable_rule($visible->cmid);

        $hidden = $this->getDataGenerator()->create_module('assign', [
            'course'  => $course->id,
            'visible' => 0,
        ]);
        $DB->set_field('course_modules', 'completionexpected', $deadline, ['id' => $hidden->cmid]);
        $this->enable_rule($hidden->cmid);

        rebuild_course_cache($course->id);

        $this->setUser($student);

        hook_listener::inject_course_notices($this->make_hook());

        $code = $this->amd_code();

        self::assertStringContainsString(
            '"cmid":' . $visible->cmid,
            $code,
            'The visible activity must still be reported to the student.'
        );
        self::assertStringNotContainsString(
            '"cmid":' . $hidden->cmid,
            $code,
            'A hidden activity must not leak its cmid/deadline/rate to the student payload.'
        );
    }

    /**
     * A teacher (with local/latepenalty:viewreport) is not subject to the same
     * visibility filter — they must still see notices for hidden activities,
     * since they are the ones responsible for managing them.
     */
    public function test_teacher_still_sees_hidden_activity(): void {
        global $DB, $PAGE;

        $course = $this->getDataGenerator()->create_course();

        // See test_hidden_activity_excluded_from_student_payload() for why $PAGE's
        // theme-affecting state must be set before any enrolment call.
        $PAGE->set_course($course);
        $PAGE->set_pagetype('course-view-topics');

        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $deadline = time() - 5 * DAYSECS;

        $hidden = $this->getDataGenerator()->create_module('assign', [
            'course'  => $course->id,
            'visible' => 0,
        ]);
        $DB->set_field('course_modules', 'completionexpected', $deadline, ['id' => $hidden->cmid]);
        $this->enable_rule($hidden->cmid);

        rebuild_course_cache($course->id);

        $this->setUser($teacher);

        hook_listener::inject_course_notices($this->make_hook());

        self::assertStringContainsString('"cmid":' . $hidden->cmid, $this->amd_code());
    }

    /**
     * The course-page notice payload carries the deadline formatted with
     * both date and time (via penalty_helper::format_deadline()), not just
     * a date — integration-level regression guard for the date/time
     * formatting introduced this session, at the actual point it reaches
     * the student.
     */
    public function test_notice_includes_formatted_deadline_date_and_time(): void {
        global $DB, $PAGE;

        $course = $this->getDataGenerator()->create_course();

        // See test_hidden_activity_excluded_from_student_payload() for why $PAGE's
        // theme-affecting state must be set before any enrolment call.
        $PAGE->set_course($course);
        $PAGE->set_pagetype('course-view-topics');

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $deadline = time() + 5 * DAYSECS;

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $DB->set_field('course_modules', 'completionexpected', $deadline, ['id' => $assign->cmid]);
        $this->enable_rule($assign->cmid);

        rebuild_course_cache($course->id);

        $this->setUser($student);

        hook_listener::inject_course_notices($this->make_hook());

        // The AMD payload is JSON-encoded with plain json_encode(), which escapes
        // "/" as "\/" by default — the raw formatted string never appears
        // literally in the emitted JS, only its JSON-escaped form.
        $expected = trim(json_encode(penalty_helper::format_deadline($deadline)), '"');
        self::assertStringContainsString($expected, $this->amd_code());
    }

    /**
     * Invokes the private static hook_listener::count_pending_students() via reflection.
     *
     * @param \cm_info $cm Course module info for the activity.
     * @return int Pending student count.
     */
    private function count_pending_students(\cm_info $cm): int {
        $method = new ReflectionMethod(hook_listener::class, 'count_pending_students');
        $method->setAccessible(true);

        return $method->invoke(null, $cm);
    }

    /**
     * Regression guard for the activity-page badge (inject_activity_notice()):
     * a non-editing teacher confined to one group in a separate-groups activity
     * must only count pending students from their own group, not the whole course.
     *
     * Before this fix, count_pending_students() counted every enrolled student in
     * the course context, leaking the existence/size of activity in groups the
     * caller cannot otherwise see on the report or override pages.
     */
    public function test_count_pending_students_scoped_to_callers_group(): void {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $course = $this->getDataGenerator()->create_course([
            'groupmode'      => SEPARATEGROUPS,
            'groupmodeforce' => 1,
        ]);

        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        groups_add_member($groupa->id, $teacher->id);

        $studenta = $this->getDataGenerator()->create_and_enrol($course, 'student');
        groups_add_member($groupa->id, $studenta->id);
        $studentb = $this->getDataGenerator()->create_and_enrol($course, 'student');
        groups_add_member($groupb->id, $studentb->id);

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $this->enable_rule($assign->cmid);
        rebuild_course_cache($course->id);

        $this->setUser($teacher);
        $cm = get_fast_modinfo($course->id)->get_cm($assign->cmid);

        self::assertSame(1, $this->count_pending_students($cm));
    }

    /**
     * Control case for the fix above: a caller with moodle/site:accessallgroups
     * (editingteacher) must still see the full course-wide count, unaffected by
     * the new group scoping.
     */
    public function test_count_pending_students_unrestricted_for_accessallgroups(): void {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $course = $this->getDataGenerator()->create_course([
            'groupmode'      => SEPARATEGROUPS,
            'groupmodeforce' => 1,
        ]);

        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $studenta = $this->getDataGenerator()->create_and_enrol($course, 'student');
        groups_add_member($groupa->id, $studenta->id);
        $studentb = $this->getDataGenerator()->create_and_enrol($course, 'student');
        groups_add_member($groupb->id, $studentb->id);

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $this->enable_rule($assign->cmid);
        rebuild_course_cache($course->id);

        $this->setUser($teacher);
        $cm = get_fast_modinfo($course->id)->get_cm($assign->cmid);

        self::assertSame(2, $this->count_pending_students($cm));
    }

    /**
     * A restricted caller belonging to no group at all in a separate-groups
     * activity must see zero pending students, not the unrestricted total —
     * mirrors group_scope::resolve_activity_restriction()'s own empty-array
     * semantics (see group_scope_test::test_returns_empty_array_when_caller_has_no_group()).
     */
    public function test_count_pending_students_zero_when_caller_has_no_group(): void {
        $course = $this->getDataGenerator()->create_course([
            'groupmode'      => SEPARATEGROUPS,
            'groupmodeforce' => 1,
        ]);

        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $this->getDataGenerator()->create_and_enrol($course, 'student');

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $this->enable_rule($assign->cmid);
        rebuild_course_cache($course->id);

        $this->setUser($teacher);
        $cm = get_fast_modinfo($course->id)->get_cm($assign->cmid);

        self::assertSame(0, $this->count_pending_students($cm));
    }

    /**
     * Regression guard for the course-page badge (inject_course_notices()): the
     * bulk load_pending_counts() path must apply the same group scoping as the
     * single-activity path, not just report a course-wide count for every caller.
     */
    public function test_course_notice_pending_count_scoped_to_callers_group(): void {
        global $DB, $PAGE, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $course = $this->getDataGenerator()->create_course([
            'groupmode'      => SEPARATEGROUPS,
            'groupmodeforce' => 1,
        ]);

        // See test_hidden_activity_excluded_from_student_payload() for why $PAGE's
        // theme-affecting state must be set before any enrolment call.
        $PAGE->set_course($course);
        $PAGE->set_pagetype('course-view-topics');

        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        groups_add_member($groupa->id, $teacher->id);

        $studenta = $this->getDataGenerator()->create_and_enrol($course, 'student');
        groups_add_member($groupa->id, $studenta->id);
        $studentb = $this->getDataGenerator()->create_and_enrol($course, 'student');
        groups_add_member($groupb->id, $studentb->id);

        $deadline = time() - 5 * DAYSECS;
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $DB->set_field('course_modules', 'completionexpected', $deadline, ['id' => $assign->cmid]);
        $this->enable_rule($assign->cmid);

        rebuild_course_cache($course->id);

        $this->setUser($teacher);

        hook_listener::inject_course_notices($this->make_hook());

        $code = $this->amd_code();

        // The badge label embeds a middle-dot separator ("Penalty: 50% (max) ·
        // 1 pending") that json_encode() escapes as non-ASCII, so assert on the
        // plain-ASCII tail of the string instead of the literal character.
        self::assertStringContainsString('1 pending', $code);
        self::assertStringNotContainsString('2 pending', $code);
    }
}
