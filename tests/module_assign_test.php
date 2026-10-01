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

use local_latepenalty\tests\latepenalty_testcase;

/**
 * Late penalties on assignments, driven through the assignment's real API.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\observer
 * @covers \local_latepenalty\penalty_helper
 * @covers \local_latepenalty\recalculator
 * @covers \local_latepenalty\local\deadline_resolver
 * @covers \local_latepenalty\local\submission_resolver
 * @covers \local_latepenalty\local\penalty_writer
 * @covers \local_latepenalty\local\deadline
 */
final class module_assign_test extends latepenalty_testcase {
    /**
     * Control: a late submission without any extension is penalised.
     *
     * @return void
     */
    public function test_late_submission_without_extension_is_penalised(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $student, $teacher] = $this->create_course_with_users();
        $assign = $this->create_assign_activity($course, ['duedate' => time() - 5 * DAYSECS + HOURSECS]);
        $this->enable_rule($assign->cmid);

        $this->submit_assign($assign, $student);
        $this->grade_assign($assign, $student, $teacher, 100);

        $this->assertSame(50.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * An extension granted through "Grant extension" must be the deadline (F6-01).
     *
     * @return void
     */
    public function test_extension_granted_to_student_is_respected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $student, $teacher] = $this->create_course_with_users();
        $assign = $this->create_assign_activity($course, ['duedate' => time() - 5 * DAYSECS + HOURSECS]);
        $this->enable_rule($assign->cmid);
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_extension([
            'cmid' => $assign->cmid,
            'userid' => $student->id,
            'extensionduedate' => time() + 2 * DAYSECS,
        ]);

        $this->submit_assign($assign, $student);
        $this->grade_assign($assign, $student, $teacher, 100);

        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * A submission after the extension is late from the extension, not from the due date (F6-02, F6-09).
     *
     * Replaces the former observer_test case that stored the "extension" in
     * assign_overrides and therefore passed while real extensions were ignored.
     *
     * @return void
     */
    public function test_submission_after_extension_counts_from_extension(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $student, $teacher] = $this->create_course_with_users();
        $duedate = time() - 3 * DAYSECS + HOURSECS;
        $assign = $this->create_assign_activity($course, ['duedate' => $duedate]);
        $this->enable_rule($assign->cmid);
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_extension([
            'cmid' => $assign->cmid,
            'userid' => $student->id,
            'extensionduedate' => $duedate + DAYSECS,
        ]);

        $this->submit_assign($assign, $student);
        $this->grade_assign($assign, $student, $teacher, 100);

        // Two days after the extension (three after the due date): 20%, not 30%.
        $this->assertSame(80.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * A scale grade is never moved by the penalty (F18).
     *
     * @return void
     */
    public function test_scale_grade_is_not_penalised(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $student, $teacher] = $this->create_course_with_users();
        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Poor,Good,Excellent', 'courseid' => $course->id]);
        $assign = $this->create_assign_activity($course, [
            'duedate' => time() - 5 * DAYSECS + HOURSECS,
            'grade' => -$scale->id,
        ]);
        $this->enable_rule($assign->cmid);

        $this->submit_assign($assign, $student);
        $this->grade_assign($assign, $student, $teacher, 3);

        $this->assertSame(3.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * Scale grades stay untouched by every recalculation and the task too (F18, G12).
     *
     * @return void
     */
    public function test_scale_grade_untouched_by_recalculations(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $student, $teacher] = $this->create_course_with_users();
        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Poor,Good,Excellent', 'courseid' => $course->id]);
        $duedate = time() - 5 * DAYSECS + HOURSECS;
        $assign = $this->create_assign_activity($course, ['duedate' => $duedate, 'grade' => -$scale->id]);
        $this->enable_rule($assign->cmid);
        $this->submit_assign($assign, $student);
        $this->grade_assign($assign, $student, $teacher, 3);

        recalculator::recalculate($assign->cmid, $duedate, 30.0, 50.0);
        recalculator::recalculate_all($assign->cmid, 30.0, 50.0);
        recalculator::recalculate_for_student($assign->cmid, $student->id, 30.0, 50.0);
        (new task\reprocess_grades())->execute();

        $this->assertSame(3.0, $this->final_grade('assign', $assign->id, $student->id));
        $this->assertSame([], penalty_helper::get_penalisable_items(get_coursemodule_from_id('assign', $assign->cmid)));
    }

    /**
     * With the core assignment penalty on, Late Penalty does not act anywhere (F9).
     *
     * @return void
     */
    public function test_native_penalty_steps_aside(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        if (!class_exists(\mod_assign\penalty\helper::class)) {
            $this->markTestSkipped('The core assignment penalty exists only from Moodle 5.0.');
        }
        \core_grades\penalty_manager::enable_module('assign');

        [$course, $student, $teacher] = $this->create_course_with_users();
        $duedate = time() - 2 * DAYSECS + HOURSECS;
        $assign = $this->create_assign_activity($course, ['duedate' => $duedate, 'gradepenalty' => 1]);
        $this->enable_rule($assign->cmid);
        $this->assertTrue(penalty_helper::native_penalty_active(get_coursemodule_from_id('assign', $assign->cmid)));

        $this->submit_assign($assign, $student);
        $this->grade_assign($assign, $student, $teacher, 100);
        recalculator::recalculate($assign->cmid, $duedate, 30.0, 50.0);
        recalculator::recalculate_for_student($assign->cmid, $student->id, 30.0, 50.0);
        (new task\reprocess_grades())->execute();

        // No penalty plugin is configured, so the core penalty itself deducts nothing here.
        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id));
        $this->assertSame(0, $DB->count_records('grade_grades_history', ['source' => 'local_latepenalty']));
    }

    /**
     * The native check follows the assignment's own conditions (F9).
     *
     * @return void
     */
    public function test_native_penalty_conditions(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        if (!class_exists(\mod_assign\penalty\helper::class)) {
            $this->assertSame([], penalty_helper::native_penalty_assignments([1, 2]));
            return;
        }

        [$course] = $this->create_course_with_users();
        $on = $this->create_assign_activity($course, ['duedate' => time(), 'gradepenalty' => 1]);
        $nodue = $this->create_assign_activity($course, ['duedate' => 0, 'gradepenalty' => 1]);
        $off = $this->create_assign_activity($course, ['duedate' => time(), 'gradepenalty' => 0]);
        $ids = [$on->id, $nodue->id, $off->id];

        $this->assertSame([], penalty_helper::native_penalty_assignments($ids));
        \core_grades\penalty_manager::enable_module('assign');
        $this->assertSame([(int) $on->id => true], penalty_helper::native_penalty_assignments($ids));
        foreach ([$on, $nodue, $off] as $assign) {
            $this->assertSame(
                \mod_assign\penalty\helper::is_penalty_enabled((int) $assign->id),
                penalty_helper::native_penalty_active(get_coursemodule_from_id('assign', $assign->cmid))
            );
        }
    }
}
