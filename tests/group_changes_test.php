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
 * Tests for recalculation when group membership or groups change.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty;

use local_latepenalty\tests\latepenalty_testcase;

/**
 * Joining or leaving a group, or deleting it, gives or takes away its deadline.
 *
 * Every scenario: an assignment due three days ago, 10% per day, a student who
 * hands in now and is graded 100, and a group whose override extends the
 * deadline to two days from now. Inside the group the grade is 100, outside 70.
 *
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\observer
 * @covers \local_latepenalty\recalculator
 * @covers \local_latepenalty\task\recalculate_course
 */
final class group_changes_test extends latepenalty_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/group/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Late assignment graded 100, with a group whose plugin override extends the deadline.
     *
     * @param bool $ingroup Whether the student is in the group when graded.
     * @param bool $plugin Whether the extension is a Late Penalty override (else the assignment's own).
     * @return array [assign, student, group, course]
     */
    private function graded_with_group(bool $ingroup, bool $plugin = true): array {
        [$course, $student, $teacher] = $this->create_course_with_users();
        $assign = $this->create_assign_activity($course, ['duedate' => time() - 3 * DAYSECS + 60]);
        $this->enable_rule($assign->cmid);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        if ($plugin) {
            $this->lp_generator()->create_group_override([
                'cmid' => $assign->cmid,
                'groupid' => $group->id,
                'deadline' => time() + 2 * DAYSECS,
            ]);
        } else {
            $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_override([
                'assignid' => $assign->id,
                'groupid' => $group->id,
                'duedate' => time() + 2 * DAYSECS,
                'sortorder' => 1,
            ]);
        }
        if ($ingroup) {
            groups_add_member($group->id, $student->id);
        }
        $this->submit_assign($assign, $student);
        $this->grade_assign($assign, $student, $teacher, 100);
        $this->assertSame($ingroup ? 100.0 : 70.0, $this->final_grade('assign', $assign->id, $student->id));
        return [$assign, $student, $group, $course];
    }

    /**
     * Leaving the group takes its extension away at once.
     *
     * @return void
     */
    public function test_leaving_group_recalculates(): void {
        [$assign, $student, $group] = $this->graded_with_group(true);

        groups_remove_member($group->id, $student->id);

        $this->assertSame(70.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * Joining the group gives its extension at once.
     *
     * @return void
     */
    public function test_joining_group_recalculates(): void {
        [$assign, $student, $group] = $this->graded_with_group(false);

        groups_add_member($group->id, $student->id);

        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * The assignment's own group override counts as well.
     *
     * @return void
     */
    public function test_joining_group_with_assignment_override_recalculates(): void {
        [$assign, $student, $group] = $this->graded_with_group(false, false);

        groups_add_member($group->id, $student->id);

        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * A group that changes no deadline triggers no work at all.
     *
     * @return void
     */
    public function test_group_without_deadline_writes_nothing(): void {
        global $DB;

        [$assign, $student, , $course] = $this->graded_with_group(false);
        $other = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $writes = $DB->count_records('grade_grades_history', ['userid' => $student->id]);

        groups_add_member($other->id, $student->id);

        $this->assertSame(70.0, $this->final_grade('assign', $assign->id, $student->id));
        $this->assertSame($writes, $DB->count_records('grade_grades_history', ['userid' => $student->id]));
    }

    /**
     * Where a group changes deadlines: Late Penalty, assignment, quiz and lesson group overrides.
     *
     * Activities without an enabled rule, with an override of another group, or in
     * another course are left out.
     *
     * @return void
     */
    public function test_rules_with_group_deadline(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $group = $generator->create_group(['courseid' => $course->id]);
        $othergroup = $generator->create_group(['courseid' => $course->id]);
        $later = time() + DAYSECS;

        $plugin = $this->create_assign_activity($course);
        $this->lp_generator()->create_group_override(['cmid' => $plugin->cmid, 'groupid' => $group->id, 'deadline' => $later]);
        $assign = $this->create_assign_activity($course);
        $generator->get_plugin_generator('mod_assign')->create_override(
            ['assignid' => $assign->id, 'groupid' => $group->id, 'duedate' => $later, 'sortorder' => 1]
        );
        $quiz = $generator->create_module('quiz', ['course' => $course->id]);
        $generator->get_plugin_generator('mod_quiz')->create_override(
            ['quiz' => $quiz->id, 'groupid' => $group->id, 'timeclose' => $later]
        );
        $lesson = $generator->create_module('lesson', ['course' => $course->id]);
        $generator->get_plugin_generator('mod_lesson')->create_override(
            ['lessonid' => $lesson->id, 'groupid' => $group->id, 'deadline' => $later]
        );
        $disabled = $this->create_assign_activity($course);
        $this->lp_generator()->create_group_override(['cmid' => $disabled->cmid, 'groupid' => $group->id, 'deadline' => $later]);
        $othergroupcm = $this->create_assign_activity($course);
        $this->lp_generator()->create_group_override(
            ['cmid' => $othergroupcm->cmid, 'groupid' => $othergroup->id, 'deadline' => $later]
        );
        foreach ([$plugin, $assign, $quiz, $lesson, $othergroupcm] as $module) {
            $this->enable_rule($module->cmid);
        }

        $rules = recalculator::rules_with_group_deadline((int) $course->id, (int) $group->id);

        $expected = [(int) $plugin->cmid, (int) $assign->cmid, (int) $quiz->cmid, (int) $lesson->cmid];
        sort($expected);
        $cmids = array_keys($rules);
        sort($cmids);
        $this->assertSame($expected, $cmids);
    }

    /**
     * Deleting the group recalculates its former members in the background.
     *
     * The members are gone when the event fires, so the course's activities are
     * recalculated by an adhoc task, which also runs after the modules' own
     * group_deleted observers removed their group overrides.
     *
     * @return void
     */
    public function test_deleting_group_recalculates_in_background(): void {
        [$assign, $student, $group] = $this->graded_with_group(true);

        groups_delete_group($group->id);

        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id), 'Not yet');
        $this->runAdhocTasks(task\recalculate_course::class);
        $this->assertSame(70.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * A course reset that removes groups or members recalculates nothing.
     *
     * A reset closes a term: punishing now the students who had an extension in it
     * is not what a teacher means by removing the groups.
     *
     * @return void
     */
    public function test_course_reset_recalculates_nothing(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        [$assign, $student, $group, $course] = $this->graded_with_group(true);
        [$assign2, $student2, $group2, $course2] = $this->graded_with_group(true);

        reset_course_userdata((object) ['id' => $course->id, 'reset_groups_remove' => 1]);
        reset_course_userdata((object) ['id' => $course2->id, 'reset_groups_members' => 1]);
        $this->runAdhocTasks(task\recalculate_course::class);

        $this->assertFalse($DB->record_exists('groups', ['id' => $group->id]));
        $this->assertFalse($DB->record_exists('groups_members', ['groupid' => $group2->id]));
        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id));
        $this->assertSame(100.0, $this->final_grade('assign', $assign2->id, $student2->id));
    }

    /**
     * Unenrolling a student leaves their grade as it was, ready to be recovered.
     *
     * Core removes the student's groups after the enrolment and before deleting
     * the grade, which "Recover grades" brings back from the history on a new
     * enrolment: a recalculation in between would bring back a penalised grade.
     *
     * @return void
     */
    public function test_unenrolled_student_grade_untouched(): void {
        global $DB;

        [, $student, , $course] = $this->graded_with_group(true);
        $before = $DB->count_records('grade_grades_history', ['userid' => $student->id, 'source' => 'local_latepenalty']);

        $instances = enrol_get_instances($course->id, true);
        enrol_get_plugin('manual')->unenrol_user(reset($instances), $student->id);

        $this->assertSame(
            $before,
            $DB->count_records('grade_grades_history', ['userid' => $student->id, 'source' => 'local_latepenalty'])
        );
    }
}
