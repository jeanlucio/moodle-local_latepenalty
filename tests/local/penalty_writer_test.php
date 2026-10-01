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

use local_latepenalty\recalculator;
use local_latepenalty\task\reprocess_grades;
use local_latepenalty\tests\latepenalty_testcase;

/**
 * How penalties are stored, changed and removed (F5) and the global invariants of every write.
 *
 * Most tests run on both storage paths: the deducted mark (core with MDL-88407)
 * and the overridden final grade (4.5, 5.0, unfixed branches, frozen gradebooks).
 * The override path is forced on sites that have the fix, so its logic is
 * exercised on every branch; the deducted-mark runs are skipped where the core
 * lacks the fix.
 *
 * Writes are counted through the grade history rows with this plugin as the
 * source: a loop between the plugin and the gradebook would pile them up.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\local\penalty_writer
 * @covers \local_latepenalty\recalculator
 * @covers \local_latepenalty\observer
 * @covers \local_latepenalty\task\reprocess_grades
 */
final class penalty_writer_test extends latepenalty_testcase {
    /**
     * Both storage paths.
     *
     * @return array
     */
    public static function storage_paths(): array {
        return [
            'deducted mark' => [true],
            'overridden final grade' => [false],
        ];
    }

    /**
     * Force one storage path, skipping the deducted mark where the core lacks the fix.
     *
     * @param bool $deductedmark Whether to use the deducted mark.
     * @return void
     */
    private function use_path(bool $deductedmark): void {
        if ($deductedmark && !penalty_writer::core_supports_deducted_mark()) {
            $this->markTestSkipped('This core has no MDL-88407 fix.');
        }
        penalty_writer::force_path_for_tests($deductedmark);
    }

    /**
     * Number of grade history rows written by the plugin for one grade.
     *
     * @param string $modname Module name.
     * @param int $instance Module instance ID.
     * @param int $userid User ID.
     * @param int $itemnumber Grade item number.
     * @return int
     */
    private function plugin_writes(string $modname, int $instance, int $userid, int $itemnumber = 0): int {
        global $DB;

        return $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {grade_grades_history} h
               JOIN {grade_items} gi ON gi.id = h.itemid
              WHERE gi.itemtype = 'mod' AND gi.itemmodule = :modname AND gi.iteminstance = :instance
                AND gi.itemnumber = :itemnumber AND h.userid = :userid AND h.source = :source",
            [
                'modname' => $modname,
                'instance' => $instance,
                'itemnumber' => $itemnumber,
                'userid' => $userid,
                'source' => penalty_writer::SOURCE,
            ]
        );
    }

    /**
     * The grade item of a module.
     *
     * @param string $modname Module name.
     * @param \stdClass $module Module record.
     * @return \grade_item
     */
    private function item(string $modname, \stdClass $module): \grade_item {
        return \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => $modname,
            'iteminstance' => $module->id,
            'itemnumber' => 0,
            'courseid' => $module->course,
        ]);
    }

    /**
     * Run the reprocess task.
     *
     * @return void
     */
    private function run_task(): void {
        (new reprocess_grades())->execute();
    }

    /**
     * Assignment graded 100 two days after its due date (rule 10%/day, cap 50%).
     *
     * @return array [assign, student, teacher, course, duedate]
     */
    private function late_assign(): array {
        [$course, $student, $teacher] = $this->create_course_with_users();
        $duedate = time() - 2 * DAYSECS + HOURSECS;
        $assign = $this->create_assign_activity($course, ['duedate' => $duedate]);
        $this->enable_rule($assign->cmid);
        $this->submit_assign($assign, $student);
        $this->grade_assign($assign, $student, $teacher, 100);
        return [$assign, $student, $teacher, $course, $duedate];
    }

    /**
     * Quiz (highest grade) whose reminder was 5 days ago.
     *
     * @return array [quiz, student, deadline, course]
     */
    private function quiz_scenario(): array {
        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $quiz = $this->create_quiz_with_questions($course, 10, ['grademethod' => QUIZ_GRADEHIGHEST]);
        $this->set_reminder($quiz->cmid, $deadline);
        $this->enable_rule($quiz->cmid);
        return [$quiz, $student, $deadline, $course];
    }

    /**
     * Detection follows the core method, never the version; tests can force the override path (F5-12).
     *
     * @return void
     */
    public function test_detection(): void {
        $this->resetAfterTest();

        $supported = method_exists(\core_grades\penalty_manager::class, 'apply_grade_item_factors');
        $this->assertSame($supported, penalty_writer::core_supports_deducted_mark());
        $this->assertSame($supported, penalty_writer::uses_deducted_mark());

        penalty_writer::force_path_for_tests(false);
        $this->assertFalse(penalty_writer::uses_deducted_mark());
        penalty_writer::force_path_for_tests(true);
        $this->assertSame($supported, penalty_writer::uses_deducted_mark());
        penalty_writer::force_path_for_tests(null);
        $this->assertSame($supported, penalty_writer::uses_deducted_mark());
    }

    /**
     * A better late attempt after a penalised one reaches the gradebook (F5-01, G11).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_better_attempt_reaches_gradebook(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$quiz, $student, $deadline] = $this->quiz_scenario();

        $late = $deadline + 2 * DAYSECS - 60;
        $this->attempt_quiz($quiz, $student, 6, $late);
        $this->assertSame(48.0, $this->final_grade('quiz', $quiz->id, $student->id));

        $this->attempt_quiz($quiz, $student, 9, $late + 60);
        if (!$deductedmark) {
            // The override hides the new raw grade until the task runs.
            $this->assertSame(48.0, $this->final_grade('quiz', $quiz->id, $student->id));
            $this->run_task();
        }

        $this->assertSame(72.0, $this->final_grade('quiz', $quiz->id, $student->id));
        $this->assertLessThanOrEqual(2, $this->plugin_writes('quiz', $quiz->id, $student->id));
        $this->assert_recalculations_keep($quiz, 'quiz', $student->id, 72.0);
    }

    /**
     * A full gradebook regrade keeps the penalty and writes nothing (F5-02, G11).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_full_regrade_keeps_penalty(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$assign, $student, , $course] = $this->late_assign();
        $this->assertSame(80.0, $this->final_grade('assign', $assign->id, $student->id));
        $writes = $this->plugin_writes('assign', $assign->id, $student->id);

        $this->item('assign', $assign)->force_regrading();
        grade_regrade_final_grades($course->id);

        $this->assertSame(80.0, $this->final_grade('assign', $assign->id, $student->id));
        $this->assertSame($writes, $this->plugin_writes('assign', $assign->id, $student->id));
    }

    /**
     * The module sending the same grade again keeps the penalty (F5-03, G11).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_same_grade_sent_again(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$assign, $student, $teacher] = $this->late_assign();
        $writes = $this->plugin_writes('assign', $assign->id, $student->id);

        $this->grade_assign($assign, $student, $teacher, 100);
        $this->run_task();

        $this->assertSame(80.0, $this->final_grade('assign', $assign->id, $student->id));
        $this->assertLessThanOrEqual($writes + 1, $this->plugin_writes('assign', $assign->id, $student->id));
    }

    /**
     * Changing the item maximum gives the core's own result for the penalised grade (F5-04).
     *
     * The quiz has no manual rescaling, so the core maps the penalised raw grade
     * to the new range (48 of 100 -> 24 of 50). The assignment rescales its grades
     * itself (assign_rescale_activity_grades()), so without that the core only caps
     * the value at the new maximum.
     *
     * @return void
     */
    public function test_grademax_change_follows_core(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path(true);

        [$quiz, $student, $deadline, $course] = $this->quiz_scenario();
        $this->attempt_quiz($quiz, $student, 6, $deadline + 2 * DAYSECS - 60);
        $item = $this->item('quiz', $quiz);
        $item->grademax = 50;
        $item->update();
        grade_regrade_final_grades($course->id);
        $this->assertSame(24.0, $this->final_grade('quiz', $quiz->id, $student->id));

        [$assign, $student, , $course] = $this->late_assign();
        $item = $this->item('assign', $assign);
        $item->grademax = 50;
        $item->update();
        grade_regrade_final_grades($course->id);
        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $student->id]);
        $this->assertSame(
            round((float) $item->adjust_raw_grade(80.0, $grade->rawgrademin, $grade->rawgrademax), 2),
            $this->final_grade('assign', $assign->id, $student->id)
        );
        $this->assertSame(50.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * A teacher's edit wins over later attempts, recalculations and the task (F5-05, G07).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_teacher_edit_wins(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$quiz, $student, $deadline] = $this->quiz_scenario();

        $late = $deadline + 2 * DAYSECS - 60;
        $this->attempt_quiz($quiz, $student, 6, $late);
        $this->item('quiz', $quiz)->update_final_grade($student->id, 55.0, 'gradebook');

        $this->attempt_quiz($quiz, $student, 9, $late + 60);
        $this->run_task();

        $this->assertSame(55.0, $this->final_grade('quiz', $quiz->id, $student->id));
        $this->assert_recalculations_keep($quiz, 'quiz', $student->id, 55.0);
    }

    /**
     * Lifting the teacher's own override lets the plugin compute again (F5-06).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_teacher_lifts_own_override(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$quiz, $student, $deadline] = $this->quiz_scenario();

        $late = $deadline + 2 * DAYSECS - 60;
        $this->attempt_quiz($quiz, $student, 6, $late);
        $item = $this->item('quiz', $quiz);
        $item->update_final_grade($student->id, 55.0, 'gradebook');
        $this->attempt_quiz($quiz, $student, 9, $late + 60);

        // What the grader report does when the teacher unticks "Overridden".
        \grade_grade::fetch(['itemid' => $item->id, 'userid' => $student->id])->set_overridden(false);
        $this->run_task();

        $this->assertSame(72.0, $this->final_grade('quiz', $quiz->id, $student->id));
    }

    /**
     * A gradebook frozen at a pre-fix calculation version uses the override path, without loops (F5-07).
     *
     * @return void
     */
    public function test_frozen_gradebook(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$quiz, $student, $deadline, $course] = $this->quiz_scenario();
        set_config('gradebook_calculations_freeze_' . $course->id, 20150627);

        $this->assertFalse(penalty_writer::uses_deducted_mark((int) $course->id));

        $late = $deadline + 2 * DAYSECS - 60;
        $this->attempt_quiz($quiz, $student, 6, $late);
        $this->assertSame(48.0, $this->final_grade('quiz', $quiz->id, $student->id));
        $this->attempt_quiz($quiz, $student, 9, $late + 60);
        $this->run_task();
        $this->assertSame(72.0, $this->final_grade('quiz', $quiz->id, $student->id));

        $writes = $this->plugin_writes('quiz', $quiz->id, $student->id);
        $this->item('quiz', $quiz)->force_regrading();
        grade_regrade_final_grades($course->id);
        $this->assertSame(72.0, $this->final_grade('quiz', $quiz->id, $student->id));
        $this->assertSame($writes, $this->plugin_writes('quiz', $quiz->id, $student->id));
    }

    /**
     * Back on time (extension, exempting override, plugin override): the original grade returns (F5-08).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_back_on_time_restores_original(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);

        // Extension.
        [$assign, $student, , , $duedate] = $this->late_assign();
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_extension([
            'cmid' => $assign->cmid,
            'userid' => $student->id,
            'extensionduedate' => $duedate + 3 * DAYSECS,
        ]);
        recalculator::recalculate_for_student($assign->cmid, $student->id, 10.0, 50.0);
        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id));
        $row = $this->grade_row('assign', $assign->id, $student->id);
        $this->assertEmpty($row->overridden);

        // Assignment override without a due date: exempt.
        [$assign, $student] = $this->late_assign();
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_override([
            'assignid' => $assign->id,
            'userid' => $student->id,
            'duedate' => 0,
        ]);
        recalculator::recalculate_for_student($assign->cmid, $student->id, 10.0, 50.0);
        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id));

        // Late Penalty override with a later deadline.
        [$assign, $student, , , $duedate] = $this->late_assign();
        $this->lp_generator()->create_override([
            'cmid' => $assign->cmid,
            'userid' => $student->id,
            'deadline' => $duedate + 3 * DAYSECS,
        ]);
        recalculator::recalculate_for_student($assign->cmid, $student->id, 10.0, 50.0);
        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * An earlier deadline for an already penalised student increases the discount (F5-09).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_earlier_deadline_increases_penalty(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$assign, $student, , , $duedate] = $this->late_assign();

        $this->lp_generator()->create_override([
            'cmid' => $assign->cmid,
            'userid' => $student->id,
            'deadline' => $duedate - 2 * DAYSECS,
        ]);
        recalculator::recalculate_for_student($assign->cmid, $student->id, 10.0, 50.0);

        $this->assertSame(60.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * A rate change recalculates the penalised students (F5-10).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_rate_change(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$assign, $student, , , $duedate] = $this->late_assign();

        recalculator::recalculate($assign->cmid, $duedate, 5.0, 50.0);
        $this->assertSame(90.0, $this->final_grade('assign', $assign->id, $student->id));

        recalculator::recalculate($assign->cmid, $duedate, 30.0, 40.0);
        $this->assertSame(60.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * Deducted mark: the core penalty indicator shows the right points for any module (F5-11).
     *
     * @return void
     */
    public function test_core_penalty_indicator(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path(true);
        [$quiz, $student, $deadline] = $this->quiz_scenario();

        $this->attempt_quiz($quiz, $student, 6, $deadline + 2 * DAYSECS - 60);

        $grade = \grade_grade::fetch(['itemid' => $this->item('quiz', $quiz)->id, 'userid' => $student->id]);
        $this->assertEqualsWithDelta(12.0, (float) $grade->deductedmark, 0.001);
        $this->assertEqualsWithDelta(60.0, (float) $grade->rawgrade, 0.001);
        $this->assertEmpty($grade->overridden);
        $this->assertTrue($grade->is_penalty_applied_to_final_grade());
        $this->assertNotEmpty(\core_grades\penalty_manager::show_penalty_indicator($grade));
    }

    /**
     * Overrides written before 1.2.0 stay as they are on a deducted-mark site, until the teacher lifts them (F5-13).
     *
     * @return void
     */
    public function test_old_override_left_alone_after_upgrade(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path(false);
        [$quiz, $student, $deadline] = $this->quiz_scenario();
        $late = $deadline + 2 * DAYSECS - 60;
        $this->attempt_quiz($quiz, $student, 6, $late);
        $this->assertSame(48.0, $this->final_grade('quiz', $quiz->id, $student->id));

        // The site now has the fix.
        $this->use_path(true);
        $this->attempt_quiz($quiz, $student, 9, $late + 60);
        $this->run_task();
        recalculator::recalculate_for_student($quiz->cmid, $student->id, 10.0, 50.0);
        $this->assertSame(48.0, $this->final_grade('quiz', $quiz->id, $student->id));

        $item = $this->item('quiz', $quiz);
        \grade_grade::fetch(['itemid' => $item->id, 'userid' => $student->id])->set_overridden(false);

        $this->assertSame(72.0, $this->final_grade('quiz', $quiz->id, $student->id));
        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $student->id]);
        $this->assertEmpty($grade->overridden);
        $this->assertEqualsWithDelta(18.0, (float) $grade->deductedmark, 0.001);
    }

    /**
     * Task: only grades whose raw grade changed get a new write; a second run does nothing (F5-17, F5-19).
     *
     * @return void
     */
    public function test_task_candidates_and_idempotence(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path(false);
        [$quiz, $student, $deadline, $course] = $this->quiz_scenario();
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $late = $deadline + 2 * DAYSECS - 60;
        $this->attempt_quiz($quiz, $student, 6, $late);
        $this->attempt_quiz($quiz, $other, 6, $late);
        $this->run_task();
        $otherwrites = $this->plugin_writes('quiz', $quiz->id, $other->id);

        $this->attempt_quiz($quiz, $student, 9, $late + 60);
        $this->run_task();
        $this->assertSame(72.0, $this->final_grade('quiz', $quiz->id, $student->id));
        $this->assertSame(48.0, $this->final_grade('quiz', $quiz->id, $other->id));
        $this->assertSame($otherwrites, $this->plugin_writes('quiz', $quiz->id, $other->id));

        $writes = $this->plugin_writes('quiz', $quiz->id, $student->id);
        $this->run_task();
        $this->assertSame($writes, $this->plugin_writes('quiz', $quiz->id, $student->id));
    }

    /**
     * Task: locked grades, disabled rules and deleted activities are left alone (F5-18, G06, G08).
     *
     * @return void
     */
    public function test_task_leaves_protected_grades_alone(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path(false);

        // Locked grade.
        [$quiz, $student, $deadline] = $this->quiz_scenario();
        $late = $deadline + 2 * DAYSECS - 60;
        $this->attempt_quiz($quiz, $student, 6, $late);
        \grade_grade::fetch(['itemid' => $this->item('quiz', $quiz)->id, 'userid' => $student->id])->set_locked(1);
        $this->attempt_quiz($quiz, $student, 9, $late + 60);
        $this->run_task();
        recalculator::recalculate_for_student($quiz->cmid, $student->id, 10.0, 50.0);
        $this->assertSame(48.0, $this->final_grade('quiz', $quiz->id, $student->id));

        // Rule disabled after the penalty.
        [$quiz, $student, $deadline] = $this->quiz_scenario();
        $this->attempt_quiz($quiz, $student, 6, $late);
        $DB->set_field('local_latepenalty_rules', 'enabled', 0, ['cmid' => $quiz->cmid]);
        $writes = $this->plugin_writes('quiz', $quiz->id, $student->id);
        $this->attempt_quiz($quiz, $student, 9, $late + 60);
        $this->run_task();
        $this->assertSame($writes, $this->plugin_writes('quiz', $quiz->id, $student->id));

        // Activity deleted: the task keeps working.
        [$quiz, $student] = $this->quiz_scenario();
        $this->attempt_quiz($quiz, $student, 6, $late);
        if (method_exists(\core_courseformat\local\cmactions::class, 'delete')) {
            \core_courseformat\formatactions::cm($quiz->course)->delete($quiz->cmid);
        } else {
            course_delete_module($quiz->cmid);
        }
        $this->run_task();
        $this->assertGreaterThan(0, (int) get_config('local_latepenalty', reprocess_grades::CURSOR));
    }

    /**
     * Task cursor: it advances, persists and the next run starts after it (F5-20).
     *
     * @return void
     */
    public function test_task_cursor(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path(false);
        [$quiz, $student, $deadline] = $this->quiz_scenario();
        $this->attempt_quiz($quiz, $student, 6, $deadline + DAYSECS);

        $this->run_task();
        $nonplugin = (int) $DB->get_field_sql(
            "SELECT MAX(id) FROM {grade_grades_history} WHERE source IS NULL OR source <> :source",
            ['source' => penalty_writer::SOURCE]
        );
        $this->assertGreaterThanOrEqual($nonplugin, (int) get_config('local_latepenalty', reprocess_grades::CURSOR));

        $reads = $DB->perf_get_reads();
        $this->run_task();
        $this->assertLessThanOrEqual(3, $DB->perf_get_reads() - $reads);
    }

    /**
     * Recalculating many students whose grades are already right costs the same as a few (F5-21, G14).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_bulk_recalculation_queries_do_not_grow(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        global $DB;

        [$course, , $teacher] = $this->create_course_with_users();
        $assign = $this->create_assign_activity($course, ['duedate' => time() - 2 * DAYSECS + HOURSECS]);
        $this->enable_rule($assign->cmid);
        $userids = [];
        for ($i = 0; $i < 30; $i++) {
            $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $this->submit_assign($assign, $student);
            $this->grade_assign($assign, $student, $teacher, 100);
            $userids[] = (int) $student->id;
        }

        // Warm the per-request caches (schema checks, course module info) first.
        recalculator::recalculate_users($assign->cmid, [$userids[0]], 10.0, 50.0);

        $reads = $DB->perf_get_reads();
        recalculator::recalculate_users($assign->cmid, array_slice($userids, 0, 3), 10.0, 50.0);
        $few = $DB->perf_get_reads() - $reads;

        $reads = $DB->perf_get_reads();
        recalculator::recalculate_users($assign->cmid, $userids, 10.0, 50.0);
        $many = $DB->perf_get_reads() - $reads;

        $this->assertSame($few, $many);
    }

    /**
     * Task with grade history disabled: no failure and nothing written (F5-22).
     *
     * @return void
     */
    public function test_task_without_grade_history(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path(false);
        $CFG->disablegradehistory = 1;
        [$quiz, $student, $deadline] = $this->quiz_scenario();
        $late = $deadline + 2 * DAYSECS - 60;
        $this->attempt_quiz($quiz, $student, 6, $late);
        $this->attempt_quiz($quiz, $student, 9, $late + 60);

        $this->run_task();

        // Limitation: without history the stuck grade cannot be found nor proved ours.
        $this->assertSame(48.0, $this->final_grade('quiz', $quiz->id, $student->id));
    }

    /**
     * Task where penalties are stored as a deducted mark: nothing to do (F5-23).
     *
     * @return void
     */
    public function test_task_with_deducted_mark_does_nothing(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path(true);
        [$assign, $student] = $this->late_assign();
        $writes = $this->plugin_writes('assign', $assign->id, $student->id);

        $this->run_task();

        $this->assertSame($writes, $this->plugin_writes('assign', $assign->id, $student->id));
        $this->assertSame(
            (int) $DB->get_field_sql('SELECT MAX(id) FROM {grade_grades_history}'),
            (int) get_config('local_latepenalty', reprocess_grades::CURSOR)
        );
    }

    /**
     * The module removing the grade (G05).
     *
     * With the deducted mark the penalty goes away with the grade. With an
     * overridden final grade the core keeps the override and records nothing at
     * all when the raw grade is removed, as for any override, so nothing changes.
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_removed_grade(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$assign, $student, $teacher] = $this->late_assign();
        $writes = $this->plugin_writes('assign', $assign->id, $student->id);

        $this->grade_assign($assign, $student, $teacher, -1);
        $this->run_task();

        $this->assertSame($writes, $this->plugin_writes('assign', $assign->id, $student->id));
        $row = $this->grade_row('assign', $assign->id, $student->id);
        if ($deductedmark) {
            $this->assertNull($row->finalgrade);
            $this->assertEmpty($row->overridden);
            $this->assertEqualsWithDelta(0.0, (float) $row->deductedmark, 0.001);
        } else {
            $this->assertSame(80.0, $this->final_grade('assign', $assign->id, $student->id));
        }
    }

    /**
     * A locked grade or item is left alone by the observer and every recalculation (G06).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_locked_item(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$quiz, $student, $deadline] = $this->quiz_scenario();
        $late = $deadline + 2 * DAYSECS - 60;
        $this->attempt_quiz($quiz, $student, 6, $late);

        $this->item('quiz', $quiz)->set_locked(1, false, false);
        // The quiz prints a "locked in the gradebook" notice when it regrades a locked item.
        ob_start();
        $this->attempt_quiz($quiz, $student, 9, $late + 60);
        ob_end_clean();
        $this->run_task();
        recalculator::recalculate($quiz->cmid, $deadline, 30.0, 50.0);

        $this->assertSame(48.0, $this->final_grade('quiz', $quiz->id, $student->id));
    }

    /**
     * Only the late student changes; other students, activities and courses stay as they were (G10).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_only_late_student_changes(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$assign, $student, $teacher, $course, $duedate] = $this->late_assign();

        $ontime = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->grade_assign($assign, $ontime, $teacher, 100);
        $otherassign = $this->create_assign_activity($course, ['duedate' => time() + DAYSECS]);
        $this->enable_rule($otherassign->cmid);
        $this->submit_assign($otherassign, $student);
        $this->grade_assign($otherassign, $student, $teacher, 100);
        [$elsewhere, $elsewherestudent] = $this->late_assign();

        recalculator::recalculate($assign->cmid, $duedate, 20.0, 50.0);

        $this->assertSame(60.0, $this->final_grade('assign', $assign->id, $student->id));
        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $ontime->id));
        $this->assertSame(100.0, $this->final_grade('assign', $otherassign->id, $student->id));
        $this->assertSame(80.0, $this->final_grade('assign', $elsewhere->id, $elsewherestudent->id));
        $this->assertSame(0, $this->plugin_writes('assign', $assign->id, $ontime->id));
    }

    /**
     * Item multiplier, offset and range give the same result as the core's own scaling (G13).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_item_factors(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$course, $student, $teacher] = $this->create_course_with_users();
        $assign = $this->create_assign_activity($course, ['duedate' => time() - 2 * DAYSECS + HOURSECS, 'grade' => 25]);
        $this->enable_rule($assign->cmid);
        $item = $this->item('assign', $assign);
        $item->multfactor = 0.8;
        $item->plusfactor = 2;
        $item->update();

        $this->submit_assign($assign, $student);
        $this->grade_assign($assign, $student, $teacher, 20);

        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $student->id]);
        $expected = round((float) $item->adjust_raw_grade(16.0, $grade->rawgrademin, $grade->rawgrademax), 2);
        $this->assertSame($expected, $this->final_grade('assign', $assign->id, $student->id));
        $this->assertSame(14.8, $expected);
    }

    /**
     * Course total reflects the penalty and every write is logged as the plugin's (G15, G16).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_course_total_and_history(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$assign, $student, , $course] = $this->late_assign();

        $courseitem = \grade_item::fetch_course_item($course->id);
        grade_regrade_final_grades($course->id);
        $total = \grade_grade::fetch(['itemid' => $courseitem->id, 'userid' => $student->id]);

        $this->assertEqualsWithDelta(80.0, (float) $total->finalgrade, 0.001);
        $this->assertSame(1, $this->plugin_writes('assign', $assign->id, $student->id));
    }
}
