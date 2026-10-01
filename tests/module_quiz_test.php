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
 * Late penalties on quizzes, driven through the quiz's real grading path.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\observer
 * @covers \local_latepenalty\recalculator
 * @covers \local_latepenalty\penalty_helper
 */
final class module_quiz_test extends latepenalty_testcase {
    /**
     * Highest grade: an on-time 90 must survive a later, worse late attempt, also after a recalculation (F4-01).
     *
     * @return void
     */
    public function test_highest_grade_recalculation_uses_attempt_that_produced_grade(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $quiz = $this->create_quiz_with_questions($course, 10, ['grademethod' => QUIZ_GRADEHIGHEST]);
        $this->set_reminder($quiz->cmid, $deadline);
        $this->enable_rule($quiz->cmid);

        $this->attempt_quiz($quiz, $student, 9, $deadline - DAYSECS);
        $this->attempt_quiz($quiz, $student, 6, $deadline + 2 * DAYSECS - 60);
        $this->assertSame(90.0, $this->final_grade('quiz', $quiz->id, $student->id));

        recalculator::recalculate_for_student($quiz->cmid, $student->id, 10.0, 50.0);

        $this->assertSame(90.0, $this->final_grade('quiz', $quiz->id, $student->id));
    }

    /**
     * A better late attempt after a first penalised one must reach the gradebook (F5-01).
     *
     * @return void
     */
    public function test_better_late_attempt_after_penalty_is_not_stuck(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $quiz = $this->create_quiz_with_questions($course, 10, ['grademethod' => QUIZ_GRADEHIGHEST]);
        $this->set_reminder($quiz->cmid, $deadline);
        $this->enable_rule($quiz->cmid);

        $late = $deadline + 2 * DAYSECS - 60;
        $this->attempt_quiz($quiz, $student, 6, $late);
        $this->assertSame(48.0, $this->final_grade('quiz', $quiz->id, $student->id));

        $this->attempt_quiz($quiz, $student, 9, $late + 60);
        $this->process_pending_penalties();

        $this->assertSame(72.0, $this->final_grade('quiz', $quiz->id, $student->id));
    }

    /**
     * Create a course, a student and a quiz with a rule, the deadline 5 days ago.
     *
     * @param int $grademethod Quiz grading method.
     * @param int $essays Number of essay questions.
     * @return array [quiz, student, deadline]
     */
    private function scenario(int $grademethod, int $essays = 0): array {
        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $quiz = $this->create_quiz_with_questions($course, 10 - $essays, ['grademethod' => $grademethod], $essays);
        $this->set_reminder($quiz->cmid, $deadline);
        $this->enable_rule($quiz->cmid);
        return [$quiz, $student, $deadline];
    }

    /**
     * Highest grade: the late attempt is the best one, so its lateness counts (F4-02).
     *
     * @return void
     */
    public function test_highest_grade_late_best_attempt(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$quiz, $student, $deadline] = $this->scenario(QUIZ_GRADEHIGHEST);

        $this->attempt_quiz($quiz, $student, 6, $deadline - DAYSECS);
        $this->attempt_quiz($quiz, $student, 9, $deadline + 2 * DAYSECS - 60);

        $this->assertSame(72.0, $this->final_grade('quiz', $quiz->id, $student->id));
        $this->assert_recalculations_keep($quiz, 'quiz', $student->id, 72.0);
    }

    /**
     * Highest grade, tie: the earliest attempt with that grade counts (F4-03).
     *
     * @return void
     */
    public function test_highest_grade_tie_uses_earliest(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$quiz, $student, $deadline] = $this->scenario(QUIZ_GRADEHIGHEST);

        $this->attempt_quiz($quiz, $student, 9, $deadline - DAYSECS);
        $this->attempt_quiz($quiz, $student, 9, $deadline + 2 * DAYSECS - 60);

        $this->assertSame(90.0, $this->final_grade('quiz', $quiz->id, $student->id));
        $this->assert_recalculations_keep($quiz, 'quiz', $student->id, 90.0);
    }

    /**
     * First attempt: an on-time first attempt counts; a late first attempt counts too (F4-04).
     *
     * @return void
     */
    public function test_first_attempt(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$quiz, $student, $deadline] = $this->scenario(QUIZ_ATTEMPTFIRST);
        $this->attempt_quiz($quiz, $student, 9, $deadline - DAYSECS);
        $this->attempt_quiz($quiz, $student, 6, $deadline + 2 * DAYSECS - 60);
        $this->assertSame(90.0, $this->final_grade('quiz', $quiz->id, $student->id));
        $this->assert_recalculations_keep($quiz, 'quiz', $student->id, 90.0);

        [$quiz, $student, $deadline] = $this->scenario(QUIZ_ATTEMPTFIRST);
        $this->attempt_quiz($quiz, $student, 6, $deadline + 2 * DAYSECS - 60);
        $this->attempt_quiz($quiz, $student, 9, $deadline + 2 * DAYSECS);
        $this->assertSame(48.0, $this->final_grade('quiz', $quiz->id, $student->id));
        $this->assert_recalculations_keep($quiz, 'quiz', $student->id, 48.0);
    }

    /**
     * Last attempt: the late last attempt counts (F4-05).
     *
     * @return void
     */
    public function test_last_attempt(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$quiz, $student, $deadline] = $this->scenario(QUIZ_ATTEMPTLAST);

        $this->attempt_quiz($quiz, $student, 9, $deadline - DAYSECS);
        $this->attempt_quiz($quiz, $student, 6, $deadline + 2 * DAYSECS - 60);

        $this->assertSame(48.0, $this->final_grade('quiz', $quiz->id, $student->id));
        $this->assert_recalculations_keep($quiz, 'quiz', $student->id, 48.0);
    }

    /**
     * Average: the discount follows the last attempt; two on-time attempts are not penalised (F4-06).
     *
     * @return void
     */
    public function test_average(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$quiz, $student, $deadline] = $this->scenario(QUIZ_GRADEAVERAGE);
        $this->attempt_quiz($quiz, $student, 8, $deadline - DAYSECS);
        $this->attempt_quiz($quiz, $student, 6, $deadline + 2 * DAYSECS - 60);
        $this->assertSame(56.0, $this->final_grade('quiz', $quiz->id, $student->id));
        $this->assert_recalculations_keep($quiz, 'quiz', $student->id, 56.0);

        [$quiz, $student, $deadline] = $this->scenario(QUIZ_GRADEAVERAGE);
        $this->attempt_quiz($quiz, $student, 8, $deadline - 2 * DAYSECS);
        $this->attempt_quiz($quiz, $student, 6, $deadline - DAYSECS);
        $this->assertSame(70.0, $this->final_grade('quiz', $quiz->id, $student->id));
        $this->assert_recalculations_keep($quiz, 'quiz', $student->id, 70.0);
    }

    /**
     * Attempts that do not count for the grade are ignored: in progress, awaiting grading (F4-07).
     *
     * @return void
     */
    public function test_attempts_that_do_not_count_are_ignored(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$quiz, $student, $deadline] = $this->scenario(QUIZ_GRADEHIGHEST, 1);

        // On time: nine true/false right and the essay graded later = 90 + 10.
        $ontime = $this->attempt_quiz_with_responses($quiz, $student, $this->responses(9, 'Answer'), $deadline - DAYSECS);
        // Late: all right but the essay is still waiting for grading (no total yet).
        $this->attempt_quiz_with_responses($quiz, $student, $this->responses(9, 'Answer'), $deadline + 2 * DAYSECS - 60);
        // Late and never submitted.
        $this->start_quiz_attempt($quiz, $student);

        $this->manually_grade_quiz_question($ontime, 10, 1.0, time());

        $this->assertSame(100.0, $this->final_grade('quiz', $quiz->id, $student->id));
        $this->assert_recalculations_keep($quiz, 'quiz', $student->id, 100.0);
    }

    /**
     * Regrading all attempts after the deadline creates no new lateness (F4-08).
     *
     * @return void
     */
    public function test_regrade_after_deadline_adds_no_lateness(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/lib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        [$quiz, $student, $deadline] = $this->scenario(QUIZ_GRADEHIGHEST);

        $this->attempt_quiz($quiz, $student, 9, $deadline - DAYSECS);
        $this->attempt_quiz($quiz, $student, 6, $deadline + 2 * DAYSECS - 60);

        \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_all_final_grades();
        quiz_update_grades($quiz);

        $this->assertSame(90.0, $this->final_grade('quiz', $quiz->id, $student->id));
    }

    /**
     * An essay of an on-time attempt graded after the deadline: lateness comes from the attempt (F4-09).
     *
     * @return void
     */
    public function test_essay_graded_after_deadline(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$quiz, $student, $deadline] = $this->scenario(QUIZ_GRADEHIGHEST, 1);

        $attempt = $this->attempt_quiz_with_responses($quiz, $student, $this->responses(9, 'Answer'), $deadline - DAYSECS);
        $this->manually_grade_quiz_question($attempt, 10, 1.0, time());

        $this->assertSame(100.0, $this->final_grade('quiz', $quiz->id, $student->id));
        $this->assert_recalculations_keep($quiz, 'quiz', $student->id, 100.0);
    }

    /**
     * Response summaries: the given number of true/false answers right, then the essay text.
     *
     * @param int $correct True/false questions answered right (out of nine).
     * @param string $essay Essay answer.
     * @return array Summaries keyed by slot.
     */
    private function responses(int $correct, string $essay): array {
        $responses = [];
        for ($slot = 1; $slot <= 9; $slot++) {
            $responses[$slot] = $slot <= $correct ? 'True' : 'False';
        }
        $responses[10] = $essay;
        return $responses;
    }
}
