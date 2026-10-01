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
 * Late penalties on lessons, following the lesson's own grading code.
 *
 * Lesson grades are only written by the lesson player and the essay grading
 * page, so the helpers below replicate exactly what that code writes.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\observer
 * @covers \local_latepenalty\recalculator
 * @covers \local_latepenalty\penalty_helper
 * @covers \local_latepenalty\local\deadline_resolver
 * @covers \local_latepenalty\local\submission_resolver
 * @covers \local_latepenalty\local\penalty_writer
 * @covers \local_latepenalty\local\deadline
 */
final class module_lesson_test extends latepenalty_testcase {
    /**
     * Finish the lesson as the student, as lesson::process_eol_page() does.
     *
     * Mirrors mod/lesson/locallib.php (end of lesson): insert a lesson_grades row
     * with the percentage grade and completion time, then lesson_update_grades().
     *
     * @param \stdClass $lesson Lesson record.
     * @param \stdClass $student Student.
     * @param float $percent Grade as a percentage (essays not graded yet count as 0).
     * @param int $completed Completion time.
     * @return int The lesson_grades row ID.
     */
    private function finish_lesson(\stdClass $lesson, \stdClass $student, float $percent, int $completed): int {
        global $DB;

        $id = $DB->insert_record('lesson_grades', (object) [
            'lessonid' => $lesson->id,
            'userid' => $student->id,
            'grade' => $percent,
            'completed' => $completed,
        ]);
        lesson_update_grades($lesson, $student->id);
        return $id;
    }

    /**
     * Grade the pending essay later, as mod/lesson/essay.php does.
     *
     * Mirrors essay.php (case 'update'): only the grade of the existing
     * lesson_grades row changes, then lesson_update_grades().
     *
     * @param \stdClass $lesson Lesson record.
     * @param \stdClass $student Student.
     * @param int $gradeid The lesson_grades row ID.
     * @param float $percent New grade as a percentage.
     * @return void
     */
    private function grade_essay(\stdClass $lesson, \stdClass $student, int $gradeid, float $percent): void {
        global $DB;

        $DB->update_record('lesson_grades', (object) ['id' => $gradeid, 'grade' => $percent]);
        lesson_update_grades($lesson, $student->id);
    }

    /**
     * A lesson finished on time with its essay graded after the deadline is not penalised (F10-01).
     *
     * @return void
     */
    public function test_essay_graded_after_deadline_does_not_penalise_on_time_lesson(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/lesson/lib.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $created = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id, 'grade' => 100]);
        $this->set_reminder($created->cmid, $deadline);
        $this->enable_rule($created->cmid);

        $lesson = $DB->get_record('lesson', ['id' => $created->id], '*', MUST_EXIST);
        $lesson->cmidnumber = $created->cmid;

        $gradeid = $this->finish_lesson($lesson, $student, 50.0, $deadline - DAYSECS);
        $this->assertSame(50.0, $this->final_grade('lesson', $lesson->id, $student->id));

        $this->grade_essay($lesson, $student, $gradeid, 100.0);
        $this->assertSame(100.0, $this->final_grade('lesson', $lesson->id, $student->id));

        recalculator::recalculate_for_student($created->cmid, $student->id, 10.0, 50.0);

        $this->assertSame(100.0, $this->final_grade('lesson', $lesson->id, $student->id));
    }

    /**
     * Create a lesson with a rule, the deadline 5 days ago.
     *
     * @param array $settings Lesson settings (retake, usemaxgrade).
     * @return array [lesson record with cmidnumber, cmid, student, deadline]
     */
    private function scenario(array $settings): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/lesson/lib.php');

        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $created = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id, 'grade' => 100] + $settings);
        $this->set_reminder($created->cmid, $deadline);
        $this->enable_rule($created->cmid);

        $lesson = $DB->get_record('lesson', ['id' => $created->id], '*', MUST_EXIST);
        $lesson->cmidnumber = $created->cmid;
        return [$lesson, $created, $student, $deadline];
    }

    /**
     * Without retakes only the first attempt counts, also for its timing (F4-10).
     *
     * @return void
     */
    public function test_no_retakes_uses_first_attempt(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$lesson, $created, $student, $deadline] = $this->scenario(['retake' => 0]);

        $this->finish_lesson($lesson, $student, 80.0, $deadline - DAYSECS);
        $this->finish_lesson($lesson, $student, 100.0, $deadline + 2 * DAYSECS - 60);

        $this->assertSame(80.0, $this->final_grade('lesson', $lesson->id, $student->id));
        $this->assert_recalculations_keep($created, 'lesson', $student->id, 80.0);
    }

    /**
     * Retakes with the highest grade: the best attempt counts (F4-10).
     *
     * @return void
     */
    public function test_retakes_highest_grade(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$lesson, $created, $student, $deadline] = $this->scenario(['retake' => 1, 'usemaxgrade' => 1]);

        $this->finish_lesson($lesson, $student, 90.0, $deadline - DAYSECS);
        $this->finish_lesson($lesson, $student, 60.0, $deadline + 2 * DAYSECS - 60);

        $this->assertSame(90.0, $this->final_grade('lesson', $lesson->id, $student->id));
        $this->assert_recalculations_keep($created, 'lesson', $student->id, 90.0);
    }

    /**
     * Retakes with the mean: the last attempt completes the grade (F4-10).
     *
     * @return void
     */
    public function test_retakes_mean(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$lesson, $created, $student, $deadline] = $this->scenario(['retake' => 1, 'usemaxgrade' => 0]);

        $this->finish_lesson($lesson, $student, 80.0, $deadline - DAYSECS);
        $this->finish_lesson($lesson, $student, 60.0, $deadline + 2 * DAYSECS - 60);

        $this->assertSame(56.0, $this->final_grade('lesson', $lesson->id, $student->id));
        $this->assert_recalculations_keep($created, 'lesson', $student->id, 56.0);
    }

    /**
     * A lesson finished late is penalised from the finish time (F10-02).
     *
     * @return void
     */
    public function test_late_finish_is_penalised(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$lesson, $created, $student, $deadline] = $this->scenario(['retake' => 0]);

        $this->finish_lesson($lesson, $student, 100.0, $deadline + 2 * DAYSECS - 60);

        $this->assertSame(80.0, $this->final_grade('lesson', $lesson->id, $student->id));
        $this->assert_recalculations_keep($created, 'lesson', $student->id, 80.0);
    }
}
