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
 * Tests for grades that predate the rule (F19).
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty;

use local_latepenalty\local\penalty_writer;
use local_latepenalty\tests\latepenalty_testcase;

/**
 * Enabling the rule for the first time leaves the grades that already exist alone (F17), and no later
 * recalculation in the background touches them either, unless the plugin itself penalised them (F19).
 *
 * Every scenario: an assignment due three days ago, 10% per day, a student who handed in now and was
 * graded 100 before the rule was enabled for the first time. Three days late gives 70; two days, 80.
 *
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\recalculator
 * @covers \local_latepenalty\observer
 * @covers \local_latepenalty\task\recalculate_course
 * @covers ::local_latepenalty_coursemodule_edit_post_actions
 */
final class rule_cutoff_test extends latepenalty_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/group/lib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
    }

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
     * Turn the rule on or off through the activity form, as a teacher does.
     *
     * @param int $cmid Course module ID.
     * @param bool $enabled Whether the rule is on.
     * @return void
     */
    private function set_rule(int $cmid, bool $enabled): void {
        $this->save_activity_settings($cmid, [
            'latepenalty_enabled' => $enabled ? 1 : 0,
            'latepenalty_daily' => 10,
            'latepenalty_max' => 50,
            'latepenalty_recalc_deadline' => 1,
            'latepenalty_recalc_rate' => 1,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);
    }

    /**
     * Hand in and grade a student now.
     *
     * @param \stdClass $assign Assignment.
     * @param \stdClass $student Student.
     * @param \stdClass $teacher Teacher.
     * @return void
     */
    private function hand_in(\stdClass $assign, \stdClass $student, \stdClass $teacher): void {
        $this->submit_assign($assign, $student);
        $this->grade_assign($assign, $student, $teacher, 100);
    }

    /**
     * Assignment due three days ago whose student was graded 100 before the rule was enabled for the first time.
     *
     * @return array [course, assign, student, teacher]
     */
    private function pre_rule_grade(): array {
        [$course, $student, $teacher] = $this->create_course_with_users();
        $assign = $this->create_assign_activity($course, ['duedate' => time() - 3 * DAYSECS + 60]);
        $this->hand_in($assign, $student, $teacher);
        // The rule is enabled later than the grade arrived, as a teacher would do it.
        $this->waitForSecond();
        $this->set_rule($assign->cmid, true);
        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id), 'First enabling');
        return [$course, $assign, $student, $teacher];
    }

    /**
     * Deleting a group that changes no deadline touches no grade and queues no work (F19-01).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_unrelated_group_deletion_leaves_pre_rule_grade(bool $deductedmark): void {
        global $DB;

        $this->use_path($deductedmark);
        [$course, $assign, $student] = $this->pre_rule_grade();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        groups_delete_group($group->id);

        $this->assertSame(0, $DB->count_records('task_adhoc', ['classname' => '\\' . task\recalculate_course::class]));
        $this->runAdhocTasks(task\recalculate_course::class);
        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * Deleting a group recalculates the activities where it changed the deadline, and only those (F19-02, F19-03).
     *
     * Activity B: rule on from the start, a group extending the deadline, a member graded 100 within
     * the extension, who loses it with the group. Activity A: the grade that predates the rule.
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_group_deletion_recalculates_affected_activities_only(bool $deductedmark): void {
        $this->use_path($deductedmark);
        [$course, $assign, $student, $teacher] = $this->pre_rule_grade();
        $member = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->create_assign_activity($course, ['duedate' => time() - 3 * DAYSECS + 60]);
        $this->set_rule($other->cmid, true);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->lp_generator()->create_group_override([
            'cmid' => $other->cmid,
            'groupid' => $group->id,
            'deadline' => time() + 2 * DAYSECS,
        ]);
        groups_add_member($group->id, $member->id);
        $this->hand_in($other, $member, $teacher);
        $this->assertSame(100.0, $this->final_grade('assign', $other->id, $member->id), 'Within the extension');

        groups_delete_group($group->id);
        $this->runAdhocTasks(task\recalculate_course::class);

        $this->assertSame(70.0, $this->final_grade('assign', $other->id, $member->id), 'Lost the extension');
        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id), 'Predates the rule');
    }

    /**
     * A Late Penalty override for the student leaves the grade that predates the rule (F19-04).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_plugin_override_leaves_pre_rule_grade(bool $deductedmark): void {
        $this->use_path($deductedmark);
        [, $assign, $student] = $this->pre_rule_grade();
        $this->lp_generator()->create_override([
            'cmid' => $assign->cmid,
            'userid' => $student->id,
            'deadline' => time() - 2 * DAYSECS + 60,
        ]);

        // What the override pages call after saving.
        recalculator::recalculate_for_student($assign->cmid, $student->id, 10.0, 50.0);

        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * An extension granted in the assignment leaves the grade that predates the rule (F19-05).
     *
     * @return void
     */
    public function test_activity_extension_leaves_pre_rule_grade(): void {
        [$course, $assign, $student] = $this->pre_rule_grade();
        [, $cm] = get_course_and_cm_from_cmid($assign->cmid, 'assign');
        $instance = new \assign(\context_module::instance($cm->id), $cm, $course);

        $this->assertTrue($instance->save_user_extension($student->id, time() - 2 * DAYSECS + 60));

        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * Joining a group with a deadline leaves the grade that predates the rule (F19-06).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_group_join_leaves_pre_rule_grade(bool $deductedmark): void {
        $this->use_path($deductedmark);
        [$course, $assign, $student] = $this->pre_rule_grade();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->lp_generator()->create_group_override([
            'cmid' => $assign->cmid,
            'groupid' => $group->id,
            'deadline' => time() - 2 * DAYSECS + 60,
        ]);

        groups_add_member($group->id, $student->id);

        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * A grade the plugin penalised keeps following overrides (F19-07).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_penalised_grade_still_follows_overrides(bool $deductedmark): void {
        $this->use_path($deductedmark);
        [$course, $student, $teacher] = $this->create_course_with_users();
        $assign = $this->create_assign_activity($course, ['duedate' => time() - 3 * DAYSECS + 60]);
        $this->set_rule($assign->cmid, true);
        $this->hand_in($assign, $student, $teacher);
        $this->assertSame(70.0, $this->final_grade('assign', $assign->id, $student->id));
        $this->lp_generator()->create_override([
            'cmid' => $assign->cmid,
            'userid' => $student->id,
            'deadline' => time() - 2 * DAYSECS + 60,
        ]);

        recalculator::recalculate_for_student($assign->cmid, $student->id, 10.0, 50.0);

        $this->assertSame(80.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * Enabling the rule again reaches grades given while it was off, not those that predate it (F19-08, F19-09).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_reenabling_skips_grades_that_predate_the_rule(bool $deductedmark): void {
        $this->use_path($deductedmark);
        [$course, $assign, $student, $teacher] = $this->pre_rule_grade();
        $penalised = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $whileoff = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->hand_in($assign, $penalised, $teacher);
        $this->assertSame(70.0, $this->final_grade('assign', $assign->id, $penalised->id));

        $this->set_rule($assign->cmid, false);
        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $penalised->id), 'Disabling gives it back');
        $this->hand_in($assign, $whileoff, $teacher);
        $this->set_rule($assign->cmid, true);

        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id), 'Predates the rule');
        $this->assertSame(70.0, $this->final_grade('assign', $assign->id, $penalised->id), 'Penalised before');
        $this->assertSame(70.0, $this->final_grade('assign', $assign->id, $whileoff->id), 'Given while off');
    }

    /**
     * A grade that predates the rule and is graded again after it is enabled is penalised (F19-10).
     *
     * @return void
     */
    public function test_pre_rule_grade_graded_again_is_penalised(): void {
        [, $assign, $student, $teacher] = $this->pre_rule_grade();

        $this->grade_assign($assign, $student, $teacher, 90);

        $this->assertSame(63.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * The first enabling is dated once: creating the activity with the rule on, or turning it on (F19-11).
     *
     * @return void
     */
    public function test_first_enabling_is_dated_once(): void {
        global $DB;

        [$course] = $this->create_course_with_users();
        $timeenabled = fn(\stdClass $assign): int => (int) $DB->get_field(
            'local_latepenalty_rules',
            'timeenabled',
            ['cmid' => $assign->cmid]
        );
        $before = time();
        $created = $this->create_assign_activity($course, [
            'latepenalty_enabled' => 1,
            'latepenalty_daily' => 10,
            'latepenalty_max' => 50,
        ]);
        $this->assertGreaterThanOrEqual($before, $timeenabled($created), 'Created with the rule on');

        $assign = $this->create_assign_activity($course);
        $this->assertSame(0, $timeenabled($assign), 'Never enabled');
        $this->set_rule($assign->cmid, true);
        $first = $timeenabled($assign);
        $this->assertGreaterThanOrEqual($before, $first);
        $this->waitForSecond();
        $this->set_rule($assign->cmid, false);
        $this->set_rule($assign->cmid, true);
        $this->assertSame($first, $timeenabled($assign), 'Off and on again');
    }

    /**
     * Saving the activity through the course API keeps the date (F19-12).
     *
     * @return void
     */
    public function test_update_without_section_keeps_first_enabling(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');

        [, $assign, $student] = $this->pre_rule_grade();
        $first = (int) $DB->get_field('local_latepenalty_rules', 'timeenabled', ['cmid' => $assign->cmid]);
        $cm = get_coursemodule_from_id('', $assign->cmid, 0, false, MUST_EXIST);
        [, , , $data] = get_moduleinfo_data($cm, get_course($cm->course));
        $data->assignsubmission_onlinetext_enabled = 1;
        $data->name = 'Renamed by a script';

        update_module($data);

        $this->assertSame($first, (int) $DB->get_field('local_latepenalty_rules', 'timeenabled', ['cmid' => $assign->cmid]));
        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * Without grade history, the date of the grade tells when it arrived (F19-13).
     *
     * @return void
     */
    public function test_without_grade_history_uses_grade_date(): void {
        global $CFG;

        $CFG->disablegradehistory = 1;
        [, $assign, $student] = $this->pre_rule_grade();
        $this->lp_generator()->create_override([
            'cmid' => $assign->cmid,
            'userid' => $student->id,
            'deadline' => time() - 2 * DAYSECS + 60,
        ]);

        recalculator::recalculate_for_student($assign->cmid, $student->id, 10.0, 50.0);

        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * A grade arriving now with an older date reported by the module does not count as predating (F19-14).
     *
     * An external tool reports the grade as given two days ago, after the deadline was set but
     * before the rule was enabled a day ago; it reaches the gradebook now, on time. An override
     * moving the deadline earlier then makes it one day late: the grade history says it arrived
     * after the rule, so it is recalculated.
     *
     * @return void
     */
    public function test_older_reported_date_arriving_now_is_recalculated(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        [$course, $student] = $this->create_course_with_users();
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id, 'grade' => 100]);
        $this->set_reminder($lti->cmid, time());
        $this->lp_generator()->create_rule(['cmid' => $lti->cmid, 'enabled' => 1, 'timeenabled' => time() - DAYSECS]);
        $result = grade_update('mod/lti', $course->id, 'mod', 'lti', $lti->id, 0, [
            'userid' => $student->id,
            'rawgrade' => 100,
            'dategraded' => time() - 2 * DAYSECS,
        ]);
        $this->assertSame(GRADE_UPDATE_OK, $result);
        $this->assertSame(100.0, $this->final_grade('lti', $lti->id, $student->id), 'On time');
        $this->lp_generator()->create_override([
            'cmid' => $lti->cmid,
            'userid' => $student->id,
            'deadline' => time() - 3 * DAYSECS,
        ]);

        recalculator::recalculate_for_student($lti->cmid, $student->id, 10.0, 50.0);

        $this->assertSame(90.0, $this->final_grade('lti', $lti->id, $student->id));
    }

    /**
     * A recalculation task queued by 1.3.0 (no list of activities) covers the course, still leaving older grades (F19-18).
     *
     * @return void
     */
    public function test_task_queued_without_activities_covers_the_course(): void {
        global $DB;

        [$course, $assign, $student, $teacher] = $this->pre_rule_grade();
        $late = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->lp_generator()->create_override([
            'cmid' => $assign->cmid,
            'userid' => $late->id,
            'deadline' => time() + DAYSECS,
        ]);
        $this->hand_in($assign, $late, $teacher);
        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $late->id), 'Within the override');
        // The override goes away without any recalculation, then the old task runs.
        $DB->delete_records('local_latepenalty_overrides', ['cmid' => $assign->cmid, 'userid' => $late->id]);
        $task = new task\recalculate_course();
        $task->set_custom_data(['courseid' => $course->id]);
        \core\task\manager::queue_adhoc_task($task);

        $this->runAdhocTasks(task\recalculate_course::class);

        $this->assertSame(70.0, $this->final_grade('assign', $assign->id, $late->id));
        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * A deleted group's assignment override is read before the assignment deletes it (F19-19).
     *
     * @return void
     */
    public function test_deleted_group_assignment_override_is_found(): void {
        [$course, $student, $teacher] = $this->create_course_with_users();
        $assign = $this->create_assign_activity($course, ['duedate' => time() - 3 * DAYSECS + 60]);
        $this->set_rule($assign->cmid, true);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_override([
            'assignid' => $assign->id,
            'groupid' => $group->id,
            'duedate' => time() + 2 * DAYSECS,
            'sortorder' => 1,
        ]);
        groups_add_member($group->id, $student->id);
        $this->hand_in($assign, $student, $teacher);
        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id), 'Within the extension');

        groups_delete_group($group->id);
        $this->runAdhocTasks(task\recalculate_course::class);

        $this->assertSame(70.0, $this->final_grade('assign', $assign->id, $student->id));
    }
}
