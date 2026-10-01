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

namespace local_latepenalty;

use local_latepenalty\local\penalty_writer;
use local_latepenalty\task\reprocess_grades;
use local_latepenalty\tests\latepenalty_testcase;

/**
 * Edge cases of the engine, the writer, the observers and the task.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\recalculator
 * @covers \local_latepenalty\observer
 * @covers \local_latepenalty\local\penalty_writer
 * @covers \local_latepenalty\task\reprocess_grades
 * @covers \local_latepenalty\penalty_helper
 * @covers \local_latepenalty\local\deadline_resolver
 * @covers \local_latepenalty\local\submission_resolver
 */
final class engine_edges_test extends latepenalty_testcase {
    /**
     * Assignment graded 100 two days after its due date (80 with the default rule).
     *
     * @return array [assign, student, teacher, course]
     */
    private function late_assign(): array {
        [$course, $student, $teacher] = $this->create_course_with_users();
        $assign = $this->create_assign_activity($course, ['duedate' => time() - 2 * DAYSECS + HOURSECS]);
        $this->enable_rule($assign->cmid);
        $this->submit_assign($assign, $student);
        $this->grade_assign($assign, $student, $teacher, 100);
        $this->assertSame(80.0, $this->final_grade('assign', $assign->id, $student->id));
        return [$assign, $student, $teacher, $course];
    }

    /**
     * has_penalised(): activity without grade items, before and after a penalty.
     *
     * @return void
     */
    public function test_has_penalised(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $student, $teacher] = $this->create_course_with_users();

        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->assertFalse(recalculator::has_penalised($page->cmid));

        $assign = $this->create_assign_activity($course, ['duedate' => time() - 2 * DAYSECS + HOURSECS]);
        $this->assertFalse(recalculator::has_penalised($assign->cmid));
        $this->enable_rule($assign->cmid);
        $this->submit_assign($assign, $student);
        $this->grade_assign($assign, $student, $teacher, 100);
        $this->assertTrue(recalculator::has_penalised($assign->cmid));
    }

    /**
     * recalculate_users(): no students, or an activity that no longer exists, does nothing.
     *
     * @return void
     */
    public function test_recalculate_users_without_work(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$assign, $student] = $this->late_assign();
        $writes = $DB->count_records('grade_grades_history', ['source' => penalty_writer::SOURCE]);

        recalculator::recalculate_users($assign->cmid, [], 30.0, 50.0);
        recalculator::recalculate_users(999999, [$student->id], 30.0, 50.0);

        $this->assertSame($writes, $DB->count_records('grade_grades_history', ['source' => penalty_writer::SOURCE]));
        $this->assertSame(80.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * Override path: when the raw grade is gone, the plugin lifts its own override.
     *
     * @return void
     */
    public function test_override_lifted_when_raw_grade_removed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        penalty_writer::force_path_for_tests(false);
        [$assign, $student] = $this->late_assign();
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id,
            'itemnumber' => 0, 'courseid' => $assign->course]);
        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $student->id]);
        $this->assertNotEmpty($grade->overridden);

        // A grade whose raw value is gone (the writer receives it from the engine this way).
        $grade->rawgrade = null;
        $this->assertTrue(penalty_writer::apply($item, $grade, 0.0));

        $row = $this->grade_row('assign', $assign->id, $student->id);
        $this->assertEmpty($row->overridden);
        $this->assertNull($row->finalgrade);
        // A grade without override and without raw value: nothing left to do.
        $this->assertFalse(penalty_writer::apply($item, \grade_grade::fetch(['itemid' => $item->id,
            'userid' => $student->id]), 0.0));
    }

    /**
     * Deducted-mark path: no parent regrade while the item still needs a full regrade.
     *
     * @return void
     */
    public function test_no_parent_regrade_while_item_needs_update(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        if (!penalty_writer::core_supports_deducted_mark()) {
            $this->markTestSkipped('This core has no MDL-88407 fix.');
        }
        penalty_writer::force_path_for_tests(true);
        [$assign, $student, , $course] = $this->late_assign();
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id,
            'itemnumber' => 0, 'courseid' => $course->id]);
        $item->force_regrading();

        recalculator::recalculate($assign->cmid, time() - 2 * DAYSECS + HOURSECS, 30.0, 50.0);

        // Two days at 30% a day reach the 50% maximum.
        $this->assertSame(50.0, $this->final_grade('assign', $assign->id, $student->id));
        grade_regrade_final_grades($course->id);
        $this->assertSame(50.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * Observer: an event without item, a grade item whose activity is gone and a non-activity event are ignored.
     *
     * @return void
     */
    public function test_observer_ignores_what_it_cannot_place(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $student] = $this->create_course_with_users();
        $orphan = $this->getDataGenerator()->create_grade_item([
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => 987654,
            'itemnumber' => 0,
            'gradetype' => GRADE_TYPE_VALUE,
        ]);
        $writes = $DB->count_records('grade_grades_history', ['source' => penalty_writer::SOURCE]);

        // Core itself complains about the missing activity while building the grade event:
        // with mtrace() up to 4.5 and with debugging() since 5.0.
        ob_start();
        \grade_item::fetch(['id' => $orphan->id])->update_raw_grade($student->id, 50, 'test');
        ob_end_clean();
        $this->resetDebugging();
        observer::user_graded(\core\event\user_graded::create([
            'context' => \context_course::instance($course->id),
            'objectid' => 1,
            'relateduserid' => $student->id,
            'other' => ['itemid' => 0, 'overridden' => false, 'finalgrade' => 50],
        ]));
        observer::activity_deadline_changed(\core\event\course_viewed::create([
            'context' => \context_course::instance($course->id),
        ]));

        $this->assertSame($writes, $DB->count_records('grade_grades_history', ['source' => penalty_writer::SOURCE]));
        $final = $DB->get_field('grade_grades', 'finalgrade', ['itemid' => $orphan->id, 'userid' => $student->id]);
        $this->assertSame(50.0, round((float) $final, 2));
    }

    /**
     * Deleting a course without activities leaves the plugin tables alone.
     *
     * @return void
     */
    public function test_delete_empty_course(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$assign] = $this->late_assign();
        $empty = $this->getDataGenerator()->create_course();

        delete_course($empty, false);

        $this->assertTrue($DB->record_exists('local_latepenalty_rules', ['cmid' => $assign->cmid]));
    }

    /**
     * Task: its name, and on a deducted-mark site only frozen courses are processed.
     *
     * @return void
     */
    public function test_task_on_mixed_site(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->assertSame(get_string('task_reprocess_grades', 'local_latepenalty'), (new reprocess_grades())->get_name());
        if (!penalty_writer::core_supports_deducted_mark()) {
            $this->markTestSkipped('This core has no MDL-88407 fix.');
        }

        [$frozenassign, $frozenstudent, $teacher, $frozencourse] = $this->late_assign();
        set_config('gradebook_calculations_freeze_' . $frozencourse->id, 20150627);
        [$assign, $student, $otherteacher] = $this->late_assign();
        set_config(reprocess_grades::CURSOR, 0, 'local_latepenalty');

        // A new grade in each course after the penalty.
        $this->grade_assign($frozenassign, $frozenstudent, $teacher, 90);
        $this->grade_assign($assign, $student, $otherteacher, 90);
        $writes = $DB->count_records('grade_grades_history', ['source' => penalty_writer::SOURCE]);

        (new reprocess_grades())->execute();

        $this->assertSame(72.0, $this->final_grade('assign', $assign->id, $student->id));
        $this->assertSame(72.0, $this->final_grade('assign', $frozenassign->id, $frozenstudent->id));
        $after = $DB->count_records('grade_grades_history', ['source' => penalty_writer::SOURCE]);
        $this->assertLessThanOrEqual($writes + 1, $after);
    }
}
