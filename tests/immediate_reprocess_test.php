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
use local_latepenalty\tests\latepenalty_testcase;

/**
 * A better grade sent after a penalty reaches the gradebook at once, on both storage paths.
 *
 * Where the penalty is an overridden final grade the core fires no event for a new raw
 * grade, so the plugin listens to the quiz events that follow the grade update.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\observer
 */
final class immediate_reprocess_test extends latepenalty_testcase {
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
     * Create a course with a student and a quiz graded by highest attempt, with a rule (12.5% a day, up to 100%).
     *
     * @param int $essays Essay questions in the quiz.
     * @return array [quiz, student, deadline]
     */
    private function scenario(int $essays = 0): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/lib.php');

        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $quiz = $this->create_quiz_with_questions($course, 10 - $essays, ['grademethod' => QUIZ_GRADEHIGHEST], $essays);
        $this->set_reminder($quiz->cmid, $deadline);
        $this->enable_rule($quiz->cmid, 12.5, 100.0);
        return [$quiz, $student, $deadline];
    }

    /**
     * Two late attempts on the first day: the better one shows without waiting for the scheduled task (issue 7).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_better_late_attempt_shows_at_once(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$quiz, $student, $deadline] = $this->scenario();

        $late = $deadline + DAYSECS - 600;
        $this->attempt_quiz($quiz, $student, 8, $late);
        $this->assertSame(70.0, $this->final_grade('quiz', $quiz->id, $student->id));

        $this->attempt_quiz($quiz, $student, 10, $late + 60);

        $this->assertSame(87.5, $this->final_grade('quiz', $quiz->id, $student->id));
    }

    /**
     * A worse late attempt after a better one changes nothing.
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_worse_late_attempt_keeps_the_grade(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$quiz, $student, $deadline] = $this->scenario();

        $late = $deadline + DAYSECS - 600;
        $this->attempt_quiz($quiz, $student, 10, $late);
        $this->attempt_quiz($quiz, $student, 5, $late + 60);

        $this->assertSame(87.5, $this->final_grade('quiz', $quiz->id, $student->id));
    }

    /**
     * An essay regraded by the teacher after a penalty shows at once, as comment.php does it.
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_manual_grading_shows_at_once(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$quiz, $student, $deadline] = $this->scenario(1);

        $responses = array_fill(1, 9, 'True') + [10 => 'Answer'];
        $attempt = $this->attempt_quiz_with_responses($quiz, $student, $responses, $deadline + DAYSECS - 600);
        $this->manually_grade_quiz_question($attempt, 10, 0.0, time());
        $this->assertSame(78.75, $this->final_grade('quiz', $quiz->id, $student->id));

        $this->manually_grade_quiz_question($attempt, 10, 1.0, time());

        $this->assertSame(87.5, $this->final_grade('quiz', $quiz->id, $student->id));
    }

    /**
     * A grade the teacher edited in the gradebook is not overwritten by a later attempt.
     *
     * @return void
     */
    public function test_teacher_edit_is_left_alone(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path(false);
        [$quiz, $student, $deadline] = $this->scenario();

        $late = $deadline + DAYSECS - 600;
        $this->attempt_quiz($quiz, $student, 8, $late);
        \grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id, 'itemnumber' => 0,
            'courseid' => $quiz->course])->update_final_grade($student->id, 55.0, 'gradebook');

        $this->attempt_quiz($quiz, $student, 10, $late + 60);

        $this->assertSame(55.0, $this->final_grade('quiz', $quiz->id, $student->id));
    }

    /**
     * A quiz without an enabled rule is never touched.
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_quiz_without_rule_is_untouched(bool $deductedmark): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        [$course, $student] = $this->create_course_with_users();
        $quiz = $this->create_quiz_with_questions($course, 10, ['grademethod' => QUIZ_GRADEHIGHEST]);
        $this->set_reminder($quiz->cmid, time() - 5 * DAYSECS);

        $this->attempt_quiz($quiz, $student, 8, time() - 3 * DAYSECS);
        $this->attempt_quiz($quiz, $student, 10, time() - 3 * DAYSECS + 60);

        $this->assertSame(100.0, $this->final_grade('quiz', $quiz->id, $student->id));
    }
}
