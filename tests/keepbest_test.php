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
 * A late attempt never lowers a "highest grade" result (F15).
 *
 * Running example: 10% a day, attempts of 90 on time and 100 two days late
 * (100 - 20% = 80): the best penalised grade, 90, stays.
 *
 * Where a module stamps its attempts with the current time (H5P, SCORM,
 * database records), the time is moved back to when the student would have
 * acted, and the test says so.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\local\submission_resolver
 * @covers \local_latepenalty\recalculator
 * @covers ::local_latepenalty_offers_keepbest
 * @covers ::local_latepenalty_coursemodule_edit_post_actions
 * @covers \local_latepenalty\observer
 * @covers \local_latepenalty\penalty_helper
 * @covers \local_latepenalty\local\deadline_resolver
 * @covers \local_latepenalty\local\penalty_writer
 * @covers \local_latepenalty\local\deadline
 */
final class keepbest_test extends latepenalty_testcase {
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
     * Quiz graded by highest attempt (F15-01).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_quiz_highest(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);

        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $quiz = $this->create_quiz_with_questions($course, 10, ['grademethod' => QUIZ_GRADEHIGHEST]);
        $this->set_reminder($quiz->cmid, $deadline);
        $this->enable_rule($quiz->cmid);

        $this->attempt_quiz($quiz, $student, 9, $deadline - DAYSECS);
        $this->attempt_quiz($quiz, $student, 10, $deadline + 2 * DAYSECS - 60);
        $this->process_pending_penalties();

        $this->assertSame(90.0, $this->final_grade('quiz', $quiz->id, $student->id));
        $this->assert_recalculations_keep($quiz, 'quiz', $student->id, 90.0);
    }

    /**
     * Lesson with retakes graded by highest grade (F15-01).
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
        $this->enable_rule($created->cmid);
        $lesson = $DB->get_record('lesson', ['id' => $created->id], '*', MUST_EXIST);
        $lesson->cmidnumber = $created->cmid;

        // Mirrors the end of lesson in mod/lesson/locallib.php: a lesson_grades row, then lesson_update_grades().
        foreach ([[90.0, $deadline - DAYSECS], [100.0, $deadline + 2 * DAYSECS - 60]] as [$grade, $completed]) {
            $DB->insert_record('lesson_grades', (object) [
                'lessonid' => $lesson->id,
                'userid' => $student->id,
                'grade' => $grade,
                'completed' => $completed,
            ]);
            lesson_update_grades($lesson, $student->id);
        }
        $this->process_pending_penalties();

        $this->assertSame(90.0, $this->final_grade('lesson', $lesson->id, $student->id));
        $this->assert_recalculations_keep($created, 'lesson', $student->id, 90.0);
    }

    /**
     * H5P graded by highest attempt (F15-01).
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
        $this->enable_rule($h5p->cmid);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_h5pactivity');

        $generator->create_attempt(['h5pactivityid' => $h5p->id, 'userid' => $student->id, 'attempt' => 1,
            'rawscore' => 9, 'maxscore' => 10]);
        // The generator stamps attempts with time(): move the first one back before the deadline.
        $first = ['h5pactivityid' => $h5p->id, 'userid' => $student->id, 'attempt' => 1];
        $DB->set_field('h5pactivity_attempts', 'timemodified', $deadline - DAYSECS, $first);
        $DB->set_field('h5pactivity_attempts', 'timecreated', $deadline - DAYSECS, $first);
        $generator->create_attempt(['h5pactivityid' => $h5p->id, 'userid' => $student->id, 'attempt' => 2,
            'rawscore' => 10, 'maxscore' => 10]);
        $this->process_pending_penalties();

        // The second attempt is 5 days late: 100 - 50% = 50, so 90 stays.
        $this->assertSame(90.0, $this->final_grade('h5pactivity', $h5p->id, $student->id));
        $this->assert_recalculations_keep($h5p, 'h5pactivity', $student->id, 90.0);
    }

    /**
     * SCORM graded by highest attempt (F15-01, F12-06).
     *
     * Tracks are written with scorm_insert_track(), as the SCORM player does,
     * then the grade is pushed with scorm_update_grades().
     *
     * @return void
     */
    public function test_scorm_highest(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $created = $this->getDataGenerator()->create_module('scorm', [
            'course' => $course->id,
            'maxgrade' => 100,
            'grademethod' => GRADEHIGHEST,
            'whatgrade' => HIGHESTATTEMPT,
        ]);
        $this->set_reminder($created->cmid, $deadline);
        $this->enable_rule($created->cmid);
        $scorm = $DB->get_record('scorm', ['id' => $created->id], '*', MUST_EXIST);
        $scorm->cmidnumber = '';
        $scoid = (int) $DB->get_field('scorm_scoes', 'id', ['scorm' => $scorm->id, 'scormtype' => 'sco'], IGNORE_MULTIPLE);

        foreach ([1 => [90, $deadline - DAYSECS], 2 => [100, null]] as $attempt => [$score, $time]) {
            scorm_insert_track($student->id, $scorm->id, $scoid, $attempt, 'cmi.core.score.raw', $score);
            scorm_insert_track($student->id, $scorm->id, $scoid, $attempt, 'cmi.core.lesson_status', 'completed');
            if ($time !== null) {
                // Tracks are stamped with time(): move the first attempt back before the deadline.
                $attemptid = $DB->get_field('scorm_attempt', 'id', ['scormid' => $scorm->id, 'userid' => $student->id,
                    'attempt' => $attempt]);
                $DB->set_field('scorm_scoes_value', 'timemodified', $time, ['attemptid' => $attemptid]);
            }
            scorm_update_grades($scorm, $student->id);
        }
        $this->process_pending_penalties();

        // The second attempt is 5 days late: 100 - 50% = 50, so 90 stays.
        $this->assertSame(90.0, $this->final_grade('scorm', $scorm->id, $student->id));
        $this->assert_recalculations_keep($created, 'scorm', $student->id, 90.0);
    }

    /**
     * Rated items by maximum: forum, glossary, database (F15-02).
     *
     * @return void
     */
    public function test_maximum_rating(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $deadline = time() - 5 * DAYSECS;
        [$course, $student, $teacher] = $this->create_course_with_users();

        // Forum.
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id,
            'assessed' => RATING_AGGREGATE_MAXIMUM,
            'scale' => 100,
            'duedate' => $deadline,
        ]);
        $this->enable_rule($forum->cmid);
        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        foreach ([[$deadline - DAYSECS, 90], [$deadline + 2 * DAYSECS - 60, 100]] as [$time, $rating]) {
            $discussion = $forumgenerator->create_discussion(['course' => $course->id, 'forum' => $forum->id,
                'userid' => $student->id, 'timemodified' => $time]);
            $this->rate_item($forum->cmid, 'post', (int) $discussion->firstpost, $rating, $student->id, $teacher);
        }
        $this->process_pending_penalties();
        $this->assertSame(90.0, $this->final_grade('forum', $forum->id, $student->id));
        $this->assert_recalculations_keep($forum, 'forum', $student->id, 90.0);

        // Glossary.
        $glossary = $this->getDataGenerator()->create_module('glossary', [
            'course' => $course->id,
            'assessed' => RATING_AGGREGATE_MAXIMUM,
            'scale' => 100,
        ]);
        $this->set_reminder($glossary->cmid, $deadline);
        $this->enable_rule($glossary->cmid);
        foreach ([[$deadline - DAYSECS, 90], [$deadline + 2 * DAYSECS - 60, 100]] as [$time, $rating]) {
            $entry = $this->getDataGenerator()->get_plugin_generator('mod_glossary')->create_content($glossary, [
                'userid' => $student->id, 'approved' => 1, 'timecreated' => $time, 'timemodified' => $time]);
            $this->rate_item($glossary->cmid, 'entry', (int) $entry->id, $rating, $student->id, $teacher);
        }
        $this->process_pending_penalties();
        $this->assertSame(90.0, $this->final_grade('glossary', $glossary->id, $student->id));

        // Database.
        $data = $this->getDataGenerator()->create_module('data', [
            'course' => $course->id,
            'assessed' => RATING_AGGREGATE_MAXIMUM,
            'scale' => 100,
        ]);
        $this->set_reminder($data->cmid, $deadline);
        $this->enable_rule($data->cmid);
        $datagenerator = $this->getDataGenerator()->get_plugin_generator('mod_data');
        $field = $datagenerator->create_field((object) ['type' => 'text', 'name' => 'answer'], $data);
        foreach ([[$deadline - DAYSECS, 90], [$deadline + 2 * DAYSECS - 60, 100]] as [$time, $rating]) {
            $recordid = $datagenerator->create_entry($data, [$field->field->id => 'Answer'], 0, [], null, $student->id);
            // Data_add_record() stamps time(): move the record back to when the student saved it.
            $DB->update_record('data_records', (object) ['id' => $recordid, 'timecreated' => $time, 'timemodified' => $time]);
            $this->rate_item($data->cmid, 'entry', $recordid, $rating, $student->id, $teacher);
        }
        $this->process_pending_penalties();
        $this->assertSame(90.0, $this->final_grade('data', $data->id, $student->id));
    }

    /**
     * Changing the quiz grading method to average: the next grade follows the average (F15-04).
     *
     * @return void
     */
    public function test_quiz_method_changed_to_average(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $quiz = $this->create_quiz_with_questions($course, 10, ['grademethod' => QUIZ_GRADEHIGHEST]);
        $this->set_reminder($quiz->cmid, $deadline);
        $this->enable_rule($quiz->cmid);
        $this->attempt_quiz($quiz, $student, 9, $deadline - DAYSECS);
        $this->attempt_quiz($quiz, $student, 10, $deadline + 2 * DAYSECS - 60);
        $this->process_pending_penalties();
        $this->assertSame(90.0, $this->final_grade('quiz', $quiz->id, $student->id));

        $this->save_activity_settings($quiz->cmid, ['grademethod' => QUIZ_GRADEAVERAGE, 'quizpassword' => '']);
        $this->process_pending_penalties();
        recalculator::recalculate_for_student($quiz->cmid, $student->id, 10.0, 50.0);

        // Average 95, completed by the late attempt: 95 - 20%.
        $this->assertSame(76.0, $this->final_grade('quiz', $quiz->id, $student->id));
    }

    /**
     * The option is offered for the external tool and non-core modules only (F15-05, F15-06).
     *
     * @return void
     */
    public function test_option_offered_only_where_method_unknown(): void {
        $this->resetAfterTest();

        $this->assertTrue(local_latepenalty_offers_keepbest('lti'));
        $this->assertTrue(local_latepenalty_offers_keepbest('notacoremodule'));
        $singlegrade = ['assign', 'workshop', 'bigbluebuttonbn'];
        $automatic = ['quiz', 'lesson', 'scorm', 'h5pactivity', 'forum', 'glossary', 'data'];
        $notoffered = array_merge($singlegrade, $automatic);
        foreach ($notoffered as $modname) {
            $this->assertFalse(local_latepenalty_offers_keepbest($modname), $modname);
        }
    }

    /**
     * External tool: penalised normally without the option, best grade kept with it (F15-07, F15-08).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_external_tool_option(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$lti, $student, $deadline] = $this->tool_with_two_grades(false);

        $this->assertSame(80.0, $this->final_grade('lti', $lti->id, $student->id));

        // Ticking the option recalculates like a rate change.
        $this->save_activity_settings($lti->cmid, ['latepenalty_keepbest' => 1]);
        $this->assertSame(90.0, $this->final_grade('lti', $lti->id, $student->id));

        // An override moving the deadline past the late grade: 100 is now on time (F15-09).
        $this->lp_generator()->create_override([
            'cmid' => $lti->cmid,
            'userid' => $student->id,
            'deadline' => $deadline + 3 * DAYSECS,
        ]);
        recalculator::recalculate_for_student($lti->cmid, $student->id, 10.0, 50.0);
        $this->assertSame(100.0, $this->final_grade('lti', $lti->id, $student->id));
    }

    /**
     * Option with a teacher edit and with disabling/enabling the rule (F15-10).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_external_tool_option_with_edits_and_toggling(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$lti, $student, , $other] = $this->tool_with_two_grades(true);
        $this->assertSame(90.0, $this->final_grade('lti', $lti->id, $student->id));

        \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'lti', 'iteminstance' => $lti->id,
            'courseid' => $lti->course])->update_final_grade($other->id, 33.0, 'gradebook');

        $this->save_activity_settings($lti->cmid, ['latepenalty_enabled' => 0]);
        $this->assertSame(100.0, $this->final_grade('lti', $lti->id, $student->id));
        $this->save_activity_settings($lti->cmid, ['latepenalty_enabled' => 1]);
        $this->assertSame(90.0, $this->final_grade('lti', $lti->id, $student->id));
        $this->assertSame(33.0, $this->final_grade('lti', $lti->id, $other->id));
    }

    /**
     * Without grade history the option has nothing to compare and acts as unticked (F15-11).
     *
     * @return void
     */
    public function test_external_tool_option_without_history(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->disablegradehistory = 1;
        [$lti, $student] = $this->tool_with_two_grades(true);

        $this->assertSame(80.0, $this->final_grade('lti', $lti->id, $student->id));
    }

    /**
     * The new column exists with 0 for rules that never set it (F15-13).
     *
     * @return void
     */
    public function test_column_defaults_to_zero(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$course] = $this->create_course_with_users();
        $assign = $this->create_assign_activity($course);

        $this->assertTrue($DB->get_manager()->field_exists('local_latepenalty_rules', 'keepbest'));
        $this->assertSame('0', (string) $DB->get_field('local_latepenalty_rules', 'keepbest', ['cmid' => $assign->cmid]));
    }

    /**
     * External tool with a rule (option as given) and two grades of a student: 90 on time, 100 two days late.
     *
     * A second student is enrolled for scenarios that need one.
     *
     * @param bool $keepbest Whether the rule keeps the best grade.
     * @return array [lti, student, deadline, other student]
     */
    private function tool_with_two_grades(bool $keepbest): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id, 'grade' => 100]);
        $this->set_reminder($lti->cmid, $deadline);
        $this->save_activity_settings($lti->cmid, [
            'latepenalty_enabled' => 1,
            'latepenalty_daily' => 10,
            'latepenalty_max' => 50,
            'latepenalty_recalc_deadline' => 1,
            'latepenalty_recalc_rate' => 1,
            'latepenalty_keepbest' => (int) $keepbest,
        ]);

        // The tool reports the submission dates with each grade, as grade_update() allows.
        foreach ([$student, $other] as $user) {
            grade_update('mod/lti', $course->id, 'mod', 'lti', $lti->id, 0, ['userid' => $user->id, 'rawgrade' => 90,
                'datesubmitted' => $deadline - DAYSECS, 'dategraded' => $deadline - DAYSECS]);
        }
        // The grade history keeps only the time each grade reached the gradebook: in a real
        // course the 90 arrived the day before the deadline, so its history row says so.
        $DB->set_field('grade_grades_history', 'timemodified', $deadline - DAYSECS, ['userid' => $student->id]);
        grade_update('mod/lti', $course->id, 'mod', 'lti', $lti->id, 0, ['userid' => $student->id, 'rawgrade' => 100,
            'datesubmitted' => $deadline + 2 * DAYSECS - 60, 'dategraded' => time()]);
        $this->process_pending_penalties();

        return [$lti, $student, $deadline, $other];
    }
}
