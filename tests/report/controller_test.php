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
 * PHPUnit tests for the Late Penalty report controller.
 *
 * @package    local_latepenalty
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty\report;

use advanced_testcase;
use context_course;
use grade_item;
use stdClass;

/**
 * Tests for local_latepenalty\report\controller.
 *
 * @covers \local_latepenalty\report\controller
 */
final class controller_test extends advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Creates a course with two groups, each holding one student, and one assign
     * activity with an enabled penalty rule. Both students submit one day late
     * and are graded, so the plugin's real observer chain writes genuine
     * source = 'local_latepenalty' rows into grade_grades_history.
     *
     * @param int      $coursegroupmode Course-level group mode (default SEPARATEGROUPS).
     * @param int      $groupmodeforce  Whether the course forces its group mode onto activities.
     * @param int|null $cmgroupmode     Activity-level group mode override, or null to leave the
     *                                  value create_module() assigns (0 = inherit from course).
     * @return array{course: stdClass, context: context_course, cm: stdClass,
     *               group1: stdClass, group2: stdClass, student1: stdClass, student2: stdClass}
     */
    private function make_scenario(
        int $coursegroupmode = SEPARATEGROUPS,
        int $groupmodeforce = 1,
        ?int $cmgroupmode = null
    ): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $course = $this->getDataGenerator()->create_course([
            'groupmode'      => $coursegroupmode,
            'groupmodeforce' => $groupmodeforce,
        ]);
        $context = context_course::instance($course->id);

        $student1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $student2 = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $group1 = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $group2 = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        groups_add_member($group1->id, $student1->id);
        groups_add_member($group2->id, $student2->id);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course'  => $course->id,
            'grade'   => 100,
            'duedate' => 0,
        ]);

        if ($cmgroupmode !== null) {
            $DB->set_field('course_modules', 'groupmode', $cmgroupmode, ['id' => $assign->cmid]);
        }

        $deadline = time() - 5 * DAYSECS;
        $DB->set_field('course_modules', 'completionexpected', $deadline, ['id' => $assign->cmid]);
        rebuild_course_cache($course->id);

        $rule = $DB->get_record('local_latepenalty_rules', ['cmid' => $assign->cmid], '*', MUST_EXIST);
        $rule->enabled       = 1;
        $rule->daily_penalty = 10.0;
        $rule->max_penalty   = 50.0;
        $DB->update_record('local_latepenalty_rules', $rule);

        $gradeitem = grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'assign',
            'iteminstance' => $assign->id,
            'courseid'     => $course->id,
        ]);

        $submissiontime = $deadline + DAYSECS;
        foreach ([$student1, $student2] as $student) {
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
            $gradeitem->update_raw_grade($student->id, 100.0, 'mod/assign');
        }

        return [
            'course'   => $course,
            'context'  => $context,
            'cm'       => $assign,
            'group1'   => $group1,
            'group2'   => $group2,
            'student1' => $student1,
            'student2' => $student2,
        ];
    }

    /**
     * Enrol a user as a plain (non-editing) teacher, the archetype that does not
     * carry moodle/site:accessallgroups by default.
     *
     * @param \stdClass $course
     * @return \stdClass The enrolled teacher.
     */
    private function enrol_teacher(stdClass $course): stdClass {
        return $this->getDataGenerator()->create_and_enrol($course, 'teacher');
    }

    /**
     * Instantiate the report controller from a resolve_group_restriction() result.
     *
     * @param array $scenario Scenario array from make_scenario().
     * @param array $groupscope Return value of controller::resolve_group_restriction().
     * @return controller
     */
    private function make_controller(array $scenario, array $groupscope): controller {
        return new controller(
            (int) $scenario['course']->id,
            $scenario['context'],
            0,
            0,
            $groupscope['groupids'],
            $groupscope['restrictedcmids']
        );
    }

    // Tests for resolve_group_restriction().

    /**
     * A non-editing teacher without accessallgroups, in a course-wide
     * separate-groups course, is restricted to their own group on the activity
     * carrying the rule.
     */
    public function test_resolve_group_restriction_separategroups_without_accessallgroups(): void {
        $s = $this->make_scenario();
        $teacher = $this->enrol_teacher($s['course']);
        groups_add_member($s['group1']->id, $teacher->id);
        $this->setUser($teacher);

        $result = controller::resolve_group_restriction($s['course'], $s['context']);

        self::assertSame([(int) $s['group1']->id], $result['groupids']);
        self::assertSame([(int) $s['cm']->cmid], $result['restrictedcmids']);
    }

    /**
     * An editing teacher (moodle/site:accessallgroups by default archetype) is
     * never restricted, even in a separate-groups course.
     */
    public function test_resolve_group_restriction_editingteacher_sees_all(): void {
        $s = $this->make_scenario();
        $editingteacher = $this->getDataGenerator()->create_and_enrol($s['course'], 'editingteacher');
        $this->setUser($editingteacher);

        $result = controller::resolve_group_restriction($s['course'], $s['context']);

        self::assertSame([], $result['restrictedcmids']);
    }

    /**
     * A course not using separate groups, with no activity overriding it, never
     * restricts anything.
     */
    public function test_resolve_group_restriction_non_separategroups_returns_no_restriction(): void {
        $course  = $this->getDataGenerator()->create_course(['groupmode' => NOGROUPS]);
        $context = context_course::instance($course->id);
        $teacher = $this->enrol_teacher($course);
        $this->setUser($teacher);

        $result = controller::resolve_group_restriction($course, $context);

        self::assertSame([], $result['restrictedcmids']);
    }

    /**
     * A non-editing teacher belonging to no group at all, in a separate-groups
     * course, is still restricted (to an empty group set) rather than
     * unrestricted.
     */
    public function test_resolve_group_restriction_teacher_with_no_group(): void {
        $s = $this->make_scenario();
        $teacher = $this->enrol_teacher($s['course']);
        $this->setUser($teacher);

        $result = controller::resolve_group_restriction($s['course'], $s['context']);

        self::assertSame([], $result['groupids']);
        self::assertSame([(int) $s['cm']->cmid], $result['restrictedcmids']);
    }

    /**
     * Regression guard for the activity-level group mode gap: a course in
     * NOGROUPS (unforced) with an activity individually set to SEPARATEGROUPS
     * must still restrict that activity's rows to the caller's own group.
     *
     * groups_get_course_groupmode() alone would miss this, because it only
     * reads $course->groupmode; the activity's own groupmode applies here since
     * the course does not force its mode onto activities.
     */
    public function test_resolve_group_restriction_activity_level_separategroups(): void {
        $s = $this->make_scenario(NOGROUPS, 0, SEPARATEGROUPS);
        $teacher = $this->enrol_teacher($s['course']);
        groups_add_member($s['group1']->id, $teacher->id);
        $this->setUser($teacher);

        $result = controller::resolve_group_restriction($s['course'], $s['context']);

        self::assertSame([(int) $s['cm']->cmid], $result['restrictedcmids']);
    }

    // Tests for the report itself, driven by the resolved restriction.

    /**
     * A teacher restricted to group1 sees only group1's student in the report
     * table and in the student filter — group2's student must not leak through.
     */
    public function test_report_restricts_to_own_group(): void {
        $s = $this->make_scenario();
        $teacher = $this->enrol_teacher($s['course']);
        groups_add_member($s['group1']->id, $teacher->id);
        $this->setUser($teacher);

        $groupscope = controller::resolve_group_restriction($s['course'], $s['context']);
        $ctx = $this->make_controller($s, $groupscope)->get_template_context();

        self::assertCount(1, $ctx['penalties']);

        $useroptionvalues = array_column($ctx['useroptions'], 'value');
        self::assertContains((int) $s['student1']->id, $useroptionvalues);
        self::assertNotContains((int) $s['student2']->id, $useroptionvalues);
    }

    /**
     * A teacher with no group in a separate-groups course sees an empty report,
     * not the whole course's penalties.
     */
    public function test_report_zero_groups_sees_nothing(): void {
        $s = $this->make_scenario();
        $teacher = $this->enrol_teacher($s['course']);
        $this->setUser($teacher);

        $groupscope = controller::resolve_group_restriction($s['course'], $s['context']);
        $ctx = $this->make_controller($s, $groupscope)->get_template_context();

        self::assertSame([], $ctx['penalties']);
        // Only the "all students" placeholder option remains.
        self::assertCount(1, $ctx['useroptions']);
    }

    /**
     * An editing teacher (unrestricted) still sees both groups' students —
     * the fix must not over-restrict a caller who legitimately has
     * moodle/site:accessallgroups.
     */
    public function test_report_editingteacher_sees_all_groups(): void {
        $s = $this->make_scenario();
        $editingteacher = $this->getDataGenerator()->create_and_enrol($s['course'], 'editingteacher');
        $this->setUser($editingteacher);

        $groupscope = controller::resolve_group_restriction($s['course'], $s['context']);
        $ctx = $this->make_controller($s, $groupscope)->get_template_context();

        self::assertCount(2, $ctx['penalties']);
    }

    /**
     * The CSV/Excel export data must honour the same group restriction as the
     * on-screen report — this is the path that lets a restricted teacher
     * exfiltrate every group's data in a single file if left unfiltered.
     */
    public function test_export_data_restricts_to_own_group(): void {
        $s = $this->make_scenario();
        $teacher = $this->enrol_teacher($s['course']);
        groups_add_member($s['group1']->id, $teacher->id);
        $this->setUser($teacher);

        $groupscope = controller::resolve_group_restriction($s['course'], $s['context']);
        [, $rows] = $this->make_controller($s, $groupscope)->get_export_data();

        self::assertCount(1, $rows);
    }

    /**
     * End-to-end regression guard for the activity-level group mode gap: with the
     * course in NOGROUPS (unforced) and the activity individually set to
     * SEPARATEGROUPS, the report must still show only the caller's own group's
     * student, not both — the exact scenario the course-level-only check missed.
     */
    public function test_report_activity_level_separategroups_restricts_to_own_group(): void {
        $s = $this->make_scenario(NOGROUPS, 0, SEPARATEGROUPS);
        $teacher = $this->enrol_teacher($s['course']);
        groups_add_member($s['group1']->id, $teacher->id);
        $this->setUser($teacher);

        $groupscope = controller::resolve_group_restriction($s['course'], $s['context']);
        $ctx = $this->make_controller($s, $groupscope)->get_template_context();

        self::assertCount(1, $ctx['penalties']);
    }
}
