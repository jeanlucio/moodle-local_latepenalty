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
 * Penalty base (F20): a percentage of the grade obtained or of the maximum grade.
 *
 * Rule of every example: 10% a day, 50% cap, maximum grade 100. Two days late
 * discounts 20%: a grade of 60 becomes 48 on the grade obtained and 40 on the
 * maximum grade.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\recalculator
 * @covers \local_latepenalty\penalty_helper
 * @covers \local_latepenalty\observer
 * @covers \local_latepenalty\local\penalty_writer
 * @covers \local_latepenalty\local\submission_resolver
 * @covers \local_latepenalty\local\deadline_resolver
 * @covers \local_latepenalty\local\deadline
 * @covers \local_latepenalty\task\reprocess_grades
 */
final class penalty_base_test extends latepenalty_testcase {
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
     * Assignment two days past its due date, with a rule on the given base.
     *
     * @param int $base Penalty base.
     * @param array $params Extra assignment settings.
     * @return array [assign, student, teacher, course, duedate]
     */
    private function late_assign(int $base, array $params = []): array {
        [$course, $student, $teacher] = $this->create_course_with_users();
        $duedate = time() - 2 * DAYSECS + HOURSECS;
        $assign = $this->create_assign_activity($course, array_merge(['duedate' => $duedate], $params));
        $this->enable_rule($assign->cmid, 10.0, 50.0, $base);
        $this->submit_assign($assign, $student);
        return [$assign, $student, $teacher, $course, $duedate];
    }

    /**
     * The grade item of a module.
     *
     * @param string $modname Module name.
     * @param \stdClass $module Module record.
     * @param int $itemnumber Grade item number.
     * @return \grade_item
     */
    private function item(string $modname, \stdClass $module, int $itemnumber = 0): \grade_item {
        return \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => $modname,
            'iteminstance' => $module->id,
            'itemnumber' => $itemnumber,
            'courseid' => $module->course,
        ]);
    }

    /**
     * On the maximum grade, two days late cost 20 points whatever the grade (F20-01).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_maximum_grade_base(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$assign, $student, $teacher] = $this->late_assign(penalty_helper::BASE_MAX);

        $this->grade_assign($assign, $student, $teacher, 60);

        $this->assertSame(40.0, $this->final_grade('assign', $assign->id, $student->id));
        $this->assert_recalculations_keep($assign, 'assign', $student->id, 40.0);
    }

    /**
     * On the grade obtained, two days late cost 20% of the grade: the behaviour of every rule so far (F20-02).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_grade_obtained_base(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$assign, $student, $teacher] = $this->late_assign(penalty_helper::BASE_GRADE);

        $this->grade_assign($assign, $student, $teacher, 60);

        $this->assertSame(48.0, $this->final_grade('assign', $assign->id, $student->id));
        $this->assert_recalculations_keep($assign, 'assign', $student->id, 48.0);
    }

    /**
     * On the maximum grade, a low grade stops at the minimum grade (F20-03).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_maximum_grade_base_stops_at_minimum(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$assign, $student, $teacher] = $this->late_assign(penalty_helper::BASE_MAX);

        $this->grade_assign($assign, $student, $teacher, 15);

        $this->assertSame(0.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * With a minimum grade above 0 the deduction is a percentage of the maximum, not of the range (F20-04, P11).
     *
     * The grade item ranges from 5 to 25, so the grade the tool sends carries
     * that range: 20% of 25 is 5 points, 20 becomes 15.
     *
     * @return void
     */
    public function test_minimum_grade_above_zero(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id, 'grade' => 25]);
        $this->set_reminder($lti->cmid, $deadline);
        $this->enable_rule($lti->cmid, 10.0, 50.0, penalty_helper::BASE_MAX);
        $item = $this->item('lti', $lti);
        $item->grademin = 5;
        $item->update();

        grade_update('mod/lti', $course->id, 'mod', 'lti', $lti->id, 0, ['userid' => $student->id, 'rawgrade' => 20,
            'datesubmitted' => $deadline + 2 * DAYSECS - 60, 'dategraded' => $deadline + 2 * DAYSECS - 60]);

        $this->assertSame(15.0, $this->final_grade('lti', $lti->id, $student->id));
    }

    /**
     * Item multiplier and offset scale the result as the core does (F20-05, G13).
     *
     * Maximum 25, raw 20: 20% of 25 is 5 points, 15 raw, then 15 × 0.8 + 2 = 14.
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_item_factors(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$assign, $student, $teacher] = $this->late_assign(penalty_helper::BASE_MAX, ['grade' => 25]);
        $item = $this->item('assign', $assign);
        $item->multfactor = 0.8;
        $item->plusfactor = 2;
        $item->update();

        $this->grade_assign($assign, $student, $teacher, 20);

        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $student->id]);
        $expected = round((float) $item->adjust_raw_grade(15.0, $grade->rawgrademin, $grade->rawgrademax), 2);
        $this->assertSame(14.0, $expected);
        $this->assertSame($expected, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * Changing the maximum of a quiz item afterwards maps the penalised grade to the new range (F20-06, F5-04).
     *
     * The quiz does not rescale its own grades here: 40 of 100 becomes 20 of 50.
     *
     * @return void
     */
    public function test_item_maximum_changed_afterwards(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path(true);
        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $quiz = $this->create_quiz_with_questions($course, 10);
        $this->set_reminder($quiz->cmid, $deadline);
        $this->enable_rule($quiz->cmid, 10.0, 50.0, penalty_helper::BASE_MAX);

        $this->attempt_quiz($quiz, $student, 6, $deadline + 2 * DAYSECS - 60);
        $this->assertSame(40.0, $this->final_grade('quiz', $quiz->id, $student->id));

        $item = $this->item('quiz', $quiz);
        $item->grademax = 50;
        $item->update();
        grade_regrade_final_grades($course->id);

        $this->assertSame(20.0, $this->final_grade('quiz', $quiz->id, $student->id));
    }

    /**
     * The assignment rescaling its grades to a new maximum: the deduction follows the new maximum (F20-07).
     *
     * 60 of 100 becomes 30 of 50; 20% of 50 is 10 points, so 20. The settings form
     * asks whether to rescale; update_moduleinfo() reads that answer from the form
     * only and then calls the module's rescale_activity_grades callback, which is
     * what this test does after saving the new maximum.
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_assignment_rescaled(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$assign, $student, $teacher] = $this->late_assign(penalty_helper::BASE_MAX);
        $this->grade_assign($assign, $student, $teacher, 60);
        $this->assertSame(40.0, $this->final_grade('assign', $assign->id, $student->id));

        $this->save_activity_settings($assign->cmid, ['grade' => 50, 'assignsubmission_onlinetext_enabled' => 1]);
        [$course, $cm] = get_course_and_cm_from_cmid($assign->cmid, 'assign');
        component_callback('mod_assign', 'rescale_activity_grades', [$course, $cm, 0, 100, 0, 50]);
        $this->process_pending_penalties();

        $this->assertSame(20.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * Quiz by highest grade on the maximum grade: each attempt loses the points of its own lateness (F20-08, F20-09).
     *
     * @return array
     */
    public static function quiz_highest_cases(): array {
        return [
            'on-time attempt stays best' => [18, 90.0],
            'late attempt wins after its penalty' => [14, 75.0],
        ];
    }

    /**
     * Quiz by highest grade on the maximum grade (F20-08, F20-09).
     *
     * Twenty one-mark questions: the late attempt (19 right, 95) loses 20
     * points, 75; the on-time attempt (90 or 70) is not penalised.
     *
     * @dataProvider quiz_highest_cases
     * @param int $ontime Questions right in the on-time attempt.
     * @param float $expected Expected grade.
     * @return void
     */
    public function test_quiz_highest(int $ontime, float $expected): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $quiz = $this->create_quiz_with_questions($course, 20, ['grademethod' => QUIZ_GRADEHIGHEST]);
        $this->set_reminder($quiz->cmid, $deadline);
        $this->enable_rule($quiz->cmid, 10.0, 50.0, penalty_helper::BASE_MAX);

        $this->attempt_quiz($quiz, $student, $ontime, $deadline - DAYSECS);
        $this->attempt_quiz($quiz, $student, 19, $deadline + 2 * DAYSECS - 60);
        $this->process_pending_penalties();

        $this->assertSame($expected, $this->final_grade('quiz', $quiz->id, $student->id));
        $this->assert_recalculations_keep($quiz, 'quiz', $student->id, $expected);
    }

    /**
     * Lesson by highest grade on the maximum grade: 70 on time, 95 two days late (75) (F20-10).
     *
     * @return void
     */
    public function test_lesson_highest(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/lesson/lib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $created = $this->getDataGenerator()->create_module('lesson', [
            'course' => $course->id,
            'grade' => 100,
            'retake' => 1,
            'usemaxgrade' => 1,
        ]);
        $this->set_reminder($created->cmid, $deadline);
        $this->enable_rule($created->cmid, 10.0, 50.0, penalty_helper::BASE_MAX);
        $lesson = $DB->get_record('lesson', ['id' => $created->id], '*', MUST_EXIST);
        $lesson->cmidnumber = $created->cmid;

        // Mirrors the end of lesson in mod/lesson/locallib.php: a lesson_grades row, then lesson_update_grades().
        foreach ([[70.0, $deadline - DAYSECS], [95.0, $deadline + 2 * DAYSECS - 60]] as [$grade, $completed]) {
            $DB->insert_record('lesson_grades', (object) [
                'lessonid' => $lesson->id,
                'userid' => $student->id,
                'grade' => $grade,
                'completed' => $completed,
            ]);
            lesson_update_grades($lesson, $student->id);
        }
        $this->process_pending_penalties();

        $this->assertSame(75.0, $this->final_grade('lesson', $lesson->id, $student->id));
        $this->assert_recalculations_keep($created, 'lesson', $student->id, 75.0);
    }

    /**
     * H5P by highest attempt on the maximum grade: 14/20 on time, 19/20 two days late (75) (F20-10).
     *
     * @return void
     */
    public function test_h5p_highest(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $h5p = $this->getDataGenerator()->create_module('h5pactivity', [
            'course' => $course->id,
            'enabletracking' => 1,
            'grademethod' => \mod_h5pactivity\local\manager::GRADEHIGHESTATTEMPT,
        ]);
        $this->set_reminder($h5p->cmid, $deadline);
        $this->enable_rule($h5p->cmid, 10.0, 50.0, penalty_helper::BASE_MAX);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_h5pactivity');

        // The generator stamps attempts with time(): each one is moved back to when the student made it.
        foreach ([1 => [14, $deadline - DAYSECS], 2 => [19, $deadline + 2 * DAYSECS - 60]] as $number => [$score, $time]) {
            $generator->create_attempt(['h5pactivityid' => $h5p->id, 'userid' => $student->id, 'attempt' => $number,
                'rawscore' => $score, 'maxscore' => 20]);
            $attempt = ['h5pactivityid' => $h5p->id, 'userid' => $student->id, 'attempt' => $number];
            $DB->set_field('h5pactivity_attempts', 'timemodified', $time, $attempt);
            $DB->set_field('h5pactivity_attempts', 'timecreated', $time, $attempt);
        }
        recalculator::recalculate_for_student($h5p->cmid, $student->id, 10.0, 50.0);

        $this->assertSame(75.0, $this->final_grade('h5pactivity', $h5p->id, $student->id));
    }

    /**
     * Forum rated by maximum on the maximum grade: 70 on time, 95 two days late (75) (F20-10).
     *
     * @return void
     */
    public function test_maximum_rating(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $deadline = time() - 5 * DAYSECS;
        [$course, $student, $teacher] = $this->create_course_with_users();
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id,
            'assessed' => RATING_AGGREGATE_MAXIMUM,
            'scale' => 100,
            'duedate' => $deadline,
        ]);
        $this->enable_rule($forum->cmid, 10.0, 50.0, penalty_helper::BASE_MAX);
        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        foreach ([[$deadline - DAYSECS, 70], [$deadline + 2 * DAYSECS - 60, 95]] as [$time, $rating]) {
            $discussion = $forumgenerator->create_discussion(['course' => $course->id, 'forum' => $forum->id,
                'userid' => $student->id, 'timemodified' => $time]);
            $this->rate_item($forum->cmid, 'post', (int) $discussion->firstpost, $rating, $student->id, $teacher);
        }
        $this->process_pending_penalties();

        $this->assertSame(75.0, $this->final_grade('forum', $forum->id, $student->id));
        $this->assert_recalculations_keep($forum, 'forum', $student->id, 75.0);
    }

    /**
     * Ratings (maximum 100) and whole-forum grade (maximum 50): each item uses its own maximum (F20-11).
     *
     * One day late is 10%: 10 points off the ratings, 5 off the whole-forum grade.
     *
     * @return void
     */
    public function test_two_grade_items_use_their_own_maximum(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $deadline = time() - 5 * DAYSECS;
        [$course, $student, $teacher] = $this->create_course_with_users();
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id,
            'assessed' => RATING_AGGREGATE_AVERAGE,
            'scale' => 100,
            'grade_forum' => 50,
            'duedate' => $deadline,
        ]);
        $this->enable_rule($forum->cmid, 10.0, 50.0, penalty_helper::BASE_MAX);

        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $student->id,
            'timemodified' => $deadline + DAYSECS - 60,
        ]);
        $this->rate_item($forum->cmid, 'post', (int) $discussion->firstpost, 80, $student->id, $teacher);
        $gradeitem = \core_grades\component_gradeitem::instance('mod_forum', \context_module::instance($forum->cmid), 'forum');
        $this->setUser($teacher);
        $gradeitem->store_grade_from_formdata($student, $teacher, (object) ['grade' => 40]);
        $this->setAdminUser();

        $this->assertSame(70.0, $this->final_grade('forum', $forum->id, $student->id, 0));
        $this->assertSame(35.0, $this->final_grade('forum', $forum->id, $student->id, 1));
    }

    /**
     * Workshop submission grade on the maximum grade; the assessment grade is never penalised (F20-11).
     *
     * Same flow as module_workshop_test: generator submissions and assessments,
     * then the "Close workshop" aggregation. The peer gives 75%, 60 of the
     * maximum 80; two days late cost 20% of 80, 16 points.
     *
     * @return void
     */
    public function test_workshop_submission_item(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/workshop/locallib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        $deadline = time() - 5 * DAYSECS;
        [$course, $author] = $this->create_course_with_users();
        $peer = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $record = $this->getDataGenerator()->create_module('workshop', [
            'course' => $course->id,
            'grade' => 80,
            'gradinggrade' => 20,
        ]);
        $this->set_reminder($record->cmid, $deadline);
        $this->enable_rule($record->cmid, 10.0, 50.0, penalty_helper::BASE_MAX);

        /** @var \mod_workshop_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_workshop');
        $late = $deadline + 2 * DAYSECS - 60;
        $authorsubmission = $generator->create_submission($record->id, $author->id, [
            'timecreated' => $late,
            'timemodified' => $late,
        ]);
        $peersubmission = $generator->create_submission($record->id, $peer->id, [
            'timecreated' => $deadline - DAYSECS,
            'timemodified' => $deadline - DAYSECS,
        ]);
        $generator->create_assessment($authorsubmission, $peer->id, ['grade' => 75]);
        $generator->create_assessment($peersubmission, $author->id, ['grade' => 70, 'gradinggrade' => 90]);

        [$course, $cm] = get_course_and_cm_from_cmid($record->cmid, 'workshop');
        $workshop = new \workshop($record, $cm, $course);
        $workshop->aggregate_submission_grades();
        $workshop->aggregate_grading_grades();
        $workshop->switch_phase(\workshop::PHASE_CLOSED);

        $this->assertSame(44.0, $this->final_grade('workshop', $record->id, $author->id, 0));
        $this->assertSame(18.0, $this->final_grade('workshop', $record->id, $author->id, 1));
    }

    /**
     * External tool keeping the best grade on the maximum grade: 70 on time, 95 two days late (75) (F20-12).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_external_tool_keepbest(bool $deductedmark): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id, 'grade' => 100]);
        $this->set_reminder($lti->cmid, $deadline);
        $this->lp_generator()->create_rule(['cmid' => $lti->cmid, 'keepbest' => 1,
            'penaltybase' => penalty_helper::BASE_MAX]);

        grade_update('mod/lti', $course->id, 'mod', 'lti', $lti->id, 0, ['userid' => $student->id, 'rawgrade' => 70,
            'datesubmitted' => $deadline - DAYSECS, 'dategraded' => $deadline - DAYSECS]);
        // The grade history keeps only the time each grade reached the gradebook: the 70 arrived before the deadline.
        $DB->set_field('grade_grades_history', 'timemodified', $deadline - DAYSECS, ['userid' => $student->id]);
        grade_update('mod/lti', $course->id, 'mod', 'lti', $lti->id, 0, ['userid' => $student->id, 'rawgrade' => 95,
            'datesubmitted' => $deadline + 2 * DAYSECS - 60, 'dategraded' => time()]);
        $this->process_pending_penalties();

        $this->assertSame(75.0, $this->final_grade('lti', $lti->id, $student->id));
    }

    /**
     * Student and group overrides change the rates, never the base (F20-13, P15).
     *
     * @return void
     */
    public function test_overrides_keep_the_activity_base(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$assign, $student, $teacher, $course] = $this->late_assign(penalty_helper::BASE_MAX);
        $member = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $member->id]);
        $this->submit_assign($assign, $member);
        $this->lp_generator()->create_override(['cmid' => $assign->cmid, 'userid' => $student->id, 'daily_penalty' => 5.0]);
        $this->lp_generator()->create_group_override(['cmid' => $assign->cmid, 'groupid' => $group->id,
            'daily_penalty' => 20.0]);

        $this->grade_assign($assign, $student, $teacher, 60);
        $this->grade_assign($assign, $member, $teacher, 60);

        // 2 × 5% and 2 × 20% of the maximum 100.
        $this->assertSame(50.0, $this->final_grade('assign', $assign->id, $student->id));
        $this->assertSame(20.0, $this->final_grade('assign', $assign->id, $member->id));
    }

    /**
     * Changing the base recalculates the penalised grades, as a rate change does (F20-14, P12).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_changing_base_recalculates(bool $deductedmark): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$assign, $student, $teacher] = $this->late_assign(penalty_helper::BASE_GRADE);
        $this->grade_assign($assign, $student, $teacher, 60);
        $this->assertSame(48.0, $this->final_grade('assign', $assign->id, $student->id));

        $this->save_activity_settings($assign->cmid, ['latepenalty_base' => penalty_helper::BASE_MAX]);
        $this->assertSame(1, (int) $DB->get_field('local_latepenalty_rules', 'penaltybase', ['cmid' => $assign->cmid]));
        $this->assertSame(40.0, $this->final_grade('assign', $assign->id, $student->id));

        $this->save_activity_settings($assign->cmid, ['latepenalty_base' => penalty_helper::BASE_GRADE]);
        $this->assertSame(48.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * Without the recalculation box, the penalised grades keep the old base; new grades use the new one (F20-15).
     *
     * @return void
     */
    public function test_changing_base_without_recalculation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$assign, $student, $teacher, $course] = $this->late_assign(penalty_helper::BASE_GRADE);
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->submit_assign($assign, $other);
        $this->grade_assign($assign, $student, $teacher, 60);

        $this->save_activity_settings($assign->cmid, [
            'latepenalty_base' => penalty_helper::BASE_MAX,
            'latepenalty_recalc_rate' => 0,
        ]);
        $this->grade_assign($assign, $other, $teacher, 60);

        $this->assertSame(48.0, $this->final_grade('assign', $assign->id, $student->id));
        $this->assertSame(40.0, $this->final_grade('assign', $assign->id, $other->id));
    }

    /**
     * Changing the base of a disabled rule changes nothing; enabling it applies the base saved (F20-16).
     *
     * @return void
     */
    public function test_changing_base_while_disabled(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$assign, $student, $teacher] = $this->late_assign(penalty_helper::BASE_GRADE);
        $this->grade_assign($assign, $student, $teacher, 60);

        $this->save_activity_settings($assign->cmid, ['latepenalty_enabled' => 0]);
        $this->assertSame(60.0, $this->final_grade('assign', $assign->id, $student->id));

        $this->save_activity_settings($assign->cmid, ['latepenalty_base' => penalty_helper::BASE_MAX]);
        $this->assertSame(60.0, $this->final_grade('assign', $assign->id, $student->id));

        $this->save_activity_settings($assign->cmid, ['latepenalty_enabled' => 1]);
        $this->assertSame(40.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * A deadline change recalculates on the base saved (F20-17).
     *
     * One day earlier: three days late, 30 points off.
     *
     * @return void
     */
    public function test_deadline_change_uses_saved_base(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$assign, $student, $teacher, , $duedate] = $this->late_assign(penalty_helper::BASE_MAX);
        $this->grade_assign($assign, $student, $teacher, 60);

        $this->save_activity_settings($assign->cmid, ['duedate' => $duedate - DAYSECS]);

        $this->assertSame(30.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * Saving through the course API without the plugin's section keeps the base (F20-19).
     *
     * @return void
     */
    public function test_update_without_section_keeps_base(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        [$assign, $student, $teacher] = $this->late_assign(penalty_helper::BASE_MAX);
        $this->grade_assign($assign, $student, $teacher, 60);

        $cm = get_coursemodule_from_id('', $assign->cmid, 0, false, MUST_EXIST);
        [, , , $data] = get_moduleinfo_data($cm, get_course($cm->course));
        $data->assignsubmission_onlinetext_enabled = 1;
        $data->name = 'Renamed by a script';
        update_module($data);

        $this->assertSame(1, (int) $DB->get_field('local_latepenalty_rules', 'penaltybase', ['cmid' => $assign->cmid]));
        $this->assertSame(40.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * A rule created by a script without the base discounts on the grade obtained (F20-20, P10).
     *
     * @return void
     */
    public function test_rule_created_without_base(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $student, $teacher] = $this->create_course_with_users();
        $assign = $this->create_assign_activity($course, [
            'duedate' => time() - 2 * DAYSECS + HOURSECS,
            'latepenalty_enabled' => 1,
            'latepenalty_daily' => 10,
            'latepenalty_max' => 50,
        ]);
        $this->submit_assign($assign, $student);
        $this->grade_assign($assign, $student, $teacher, 60);

        $rule = $DB->get_record('local_latepenalty_rules', ['cmid' => $assign->cmid], '*', MUST_EXIST);
        $this->assertSame(penalty_helper::BASE_GRADE, (int) $rule->penaltybase);
        $this->assertSame(48.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * A grade that predates the rule stays untouched on the maximum grade too (F20-29, F19).
     *
     * @return void
     */
    public function test_grade_before_the_rule_stays(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$assign, $student, $teacher] = $this->late_assign(penalty_helper::BASE_MAX);
        $DB->set_field('local_latepenalty_rules', 'enabled', 0, ['cmid' => $assign->cmid]);
        $this->grade_assign($assign, $student, $teacher, 60);
        $this->lp_generator()->create_rule(['cmid' => $assign->cmid, 'penaltybase' => penalty_helper::BASE_MAX,
            'timeenabled' => time() + 1]);

        recalculator::recalculate_for_student($assign->cmid, $student->id, 10.0, 50.0);

        $this->assertSame(60.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * A numeric item with maximum 0 gives no deduction and no error (F20-37).
     *
     * @return void
     */
    public function test_item_with_zero_maximum(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id, 'grade' => 100]);
        $this->set_reminder($lti->cmid, $deadline);
        $this->enable_rule($lti->cmid, 10.0, 50.0, penalty_helper::BASE_MAX);
        $item = $this->item('lti', $lti);
        $item->grademax = 0;
        $item->update();

        grade_update('mod/lti', $course->id, 'mod', 'lti', $lti->id, 0, ['userid' => $student->id, 'rawgrade' => 0,
            'datesubmitted' => $deadline + 2 * DAYSECS - 60, 'dategraded' => $deadline + 2 * DAYSECS - 60]);
        recalculator::recalculate_for_student($lti->cmid, $student->id, 10.0, 50.0);

        $this->assertSame(0.0, $this->final_grade('lti', $lti->id, $student->id));
    }

    /**
     * Bases and storage paths for raising a maximum grade afterwards.
     *
     * @return array
     */
    public static function raised_maximum_cases(): array {
        return [
            'grade obtained, deducted mark' => [penalty_helper::BASE_GRADE, true, 9.6, 38.0],
            'grade obtained, overridden final grade' => [penalty_helper::BASE_GRADE, false, 9.6, 38.0],
            'maximum grade, deducted mark' => [penalty_helper::BASE_MAX, true, 8.0, 37.5],
            'maximum grade, overridden final grade' => [penalty_helper::BASE_MAX, false, 8.0, 37.5],
        ];
    }

    /**
     * Raising the quiz maximum grade afterwards recalculates on the new maximum (F20-38).
     *
     * Ten one-mark questions, maximum 10, six right two days late (20%). The teacher
     * raises the maximum to 20 and the quiz sends 12 of 20: 9.6 on the grade obtained,
     * 12 - 4 = 8 on the maximum grade.
     *
     * Regression guard (deducted mark path, on the grade obtained too, since 1.2.0): the
     * gradebook regrades the stored 6 of 10 before the quiz sends 12 of 20. The attempts,
     * read on the new maximum, beat that 6, so the penalty was written as 0; the quiz's
     * own 12 then matched the plugin's write and was taken for its echo. The penalty was
     * lost.
     *
     * @dataProvider raised_maximum_cases
     * @param int $base Penalty base.
     * @param bool $deductedmark Storage path.
     * @param float $expected Expected quiz grade.
     * @return void
     */
    public function test_quiz_maximum_grade_raised(int $base, bool $deductedmark, float $expected): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $quiz = $this->create_quiz_with_questions($course, 10, ['grade' => 10, 'grademethod' => QUIZ_GRADEHIGHEST]);
        $this->set_reminder($quiz->cmid, $deadline);
        $this->enable_rule($quiz->cmid, 10.0, 50.0, $base);
        $this->attempt_quiz($quiz, $student, 6, $deadline + 2 * DAYSECS - 60);

        // What the "Maximum grade" box of the quiz editing page saves.
        \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->update_quiz_maximum_grade(20);
        $this->process_pending_penalties();

        $this->assertSame($expected, $this->final_grade('quiz', $quiz->id, $student->id));
    }

    /**
     * Lowering the lesson maximum grade afterwards recalculates on the new maximum (F20-38).
     *
     * Retakes by highest grade: 70% on time, 95% two days late. The maximum goes from
     * 100 to 50: 35 on time, 47.5 late. 47.5 × 0.8 = 38 on the grade obtained; 47.5 - 10
     * = 37.5 on the maximum grade. Unlike the quiz case, this one passed before the
     * attempts carried their own maximum: it covers the other module that scales its
     * attempts by its own maximum grade.
     *
     * @dataProvider raised_maximum_cases
     * @param int $base Penalty base.
     * @param bool $deductedmark Storage path.
     * @param float $quizgrade Unused (quiz case).
     * @param float $expected Expected lesson grade.
     * @return void
     */
    public function test_lesson_maximum_grade_changed(int $base, bool $deductedmark, float $quizgrade, float $expected): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/lesson/lib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $created = $this->getDataGenerator()->create_module('lesson', [
            'course' => $course->id,
            'grade' => 100,
            'retake' => 1,
            'usemaxgrade' => 1,
        ]);
        $this->set_reminder($created->cmid, $deadline);
        $this->enable_rule($created->cmid, 10.0, 50.0, $base);
        $lesson = $DB->get_record('lesson', ['id' => $created->id], '*', MUST_EXIST);
        $lesson->cmidnumber = $created->cmid;
        // Mirrors the end of lesson in mod/lesson/locallib.php: a lesson_grades row, then lesson_update_grades().
        foreach ([[70.0, $deadline - DAYSECS], [95.0, $deadline + 2 * DAYSECS - 60]] as [$grade, $completed]) {
            $DB->insert_record('lesson_grades', (object) [
                'lessonid' => $lesson->id,
                'userid' => $student->id,
                'grade' => $grade,
                'completed' => $completed,
            ]);
            lesson_update_grades($lesson, $student->id);
        }

        // The lesson form always posts its media file draft area with the settings.
        $this->save_activity_settings($created->cmid, ['grade' => 50, 'mediafile' => file_get_unused_draft_itemid()]);
        $this->process_pending_penalties();

        $this->assertSame($expected, $this->final_grade('lesson', $lesson->id, $student->id));
    }
}
