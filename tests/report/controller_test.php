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
     * Creates a SEPARATEGROUPS course with two groups, each holding one student,
     * and one assign activity with an enabled penalty rule. Both students submit
     * one day late and are graded, so the plugin's real observer chain writes
     * genuine source = 'local_latepenalty' rows into grade_grades_history.
     *
     * @return array{course: stdClass, context: context_course, cm: stdClass,
     *               group1: stdClass, group2: stdClass, student1: stdClass, student2: stdClass}
     */
    private function make_scenario(): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $course = $this->getDataGenerator()->create_course([
            'groupmode'      => SEPARATEGROUPS,
            'groupmodeforce' => 1,
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

    // Tests for resolve_group_restriction().

    /**
     * A non-editing teacher without accessallgroups, in a separate-groups course,
     * is restricted to their own group.
     */
    public function test_resolve_group_restriction_separategroups_without_accessallgroups(): void {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $s = $this->make_scenario();
        $teacher = $this->enrol_teacher($s['course']);
        groups_add_member($s['group1']->id, $teacher->id);
        $this->setUser($teacher);

        $restriction = controller::resolve_group_restriction($s['course'], $s['context']);

        self::assertSame([(int) $s['group1']->id], $restriction);
    }

    /**
     * An editing teacher (moodle/site:accessallgroups by default archetype) is
     * never restricted, even in a separate-groups course.
     */
    public function test_resolve_group_restriction_editingteacher_sees_all(): void {
        $s = $this->make_scenario();
        $editingteacher = $this->getDataGenerator()->create_and_enrol($s['course'], 'editingteacher');
        $this->setUser($editingteacher);

        self::assertNull(controller::resolve_group_restriction($s['course'], $s['context']));
    }

    /**
     * A course not using separate groups is never restricted, regardless of capability.
     */
    public function test_resolve_group_restriction_non_separategroups_course_returns_null(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['groupmode' => NOGROUPS]);
        $context = context_course::instance($course->id);
        $teacher = $this->enrol_teacher($course);
        $this->setUser($teacher);

        self::assertNull(controller::resolve_group_restriction($course, $context));
    }

    /**
     * A non-editing teacher belonging to no group at all, in a separate-groups
     * course, is restricted to an empty set (must see nothing, not everything).
     */
    public function test_resolve_group_restriction_teacher_with_no_group_returns_empty_array(): void {
        $s = $this->make_scenario();
        $teacher = $this->enrol_teacher($s['course']);
        $this->setUser($teacher);

        self::assertSame([], controller::resolve_group_restriction($s['course'], $s['context']));
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

        $restriction = controller::resolve_group_restriction($s['course'], $s['context']);
        $reportcontroller = new controller((int) $s['course']->id, $s['context'], 0, 0, $restriction);
        $ctx = $reportcontroller->get_template_context();

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

        $restriction = controller::resolve_group_restriction($s['course'], $s['context']);
        $reportcontroller = new controller((int) $s['course']->id, $s['context'], 0, 0, $restriction);
        $ctx = $reportcontroller->get_template_context();

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

        $restriction = controller::resolve_group_restriction($s['course'], $s['context']);
        $reportcontroller = new controller((int) $s['course']->id, $s['context'], 0, 0, $restriction);
        $ctx = $reportcontroller->get_template_context();

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

        $restriction = controller::resolve_group_restriction($s['course'], $s['context']);
        $reportcontroller = new controller((int) $s['course']->id, $s['context'], 0, 0, $restriction);
        [, $rows] = $reportcontroller->get_export_data();

        self::assertCount(1, $rows);
    }
}
