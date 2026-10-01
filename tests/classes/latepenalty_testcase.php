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

namespace local_latepenalty\tests;

/**
 * Base test case for scenarios that drive other modules through their real API.
 *
 * Every helper here produces module data the way the module itself does
 * (data generators, module APIs, <modname>_update_grades()). Where a module
 * only writes through its own UI code, the helper replicates exactly what that
 * code writes and cites it. Seeding rows straight into a module's tables would
 * encode this plugin's own assumptions about the data and let a wrong
 * assumption pass unnoticed.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class latepenalty_testcase extends \advanced_testcase {
    #[\Override]
    protected function tearDown(): void {
        \local_latepenalty\local\penalty_writer::reset_for_tests();
        \local_latepenalty\local\deadline_resolver::reset_caches();
        parent::tearDown();
    }

    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/rating/lib.php');
        parent::setUpBeforeClass();
    }

    /**
     * The plugin data generator.
     *
     * @return \local_latepenalty_generator
     */
    protected function lp_generator(): \local_latepenalty_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_latepenalty');
    }

    /**
     * Enable a penalty rule on a course module.
     *
     * @param int $cmid Course module ID.
     * @param float $daily Daily penalty percentage.
     * @param float $max Maximum penalty percentage.
     * @return void
     */
    protected function enable_rule(int $cmid, float $daily = 10.0, float $max = 50.0): void {
        $this->lp_generator()->create_rule([
            'cmid' => $cmid,
            'enabled' => 1,
            'daily_penalty' => $daily,
            'max_penalty' => $max,
        ]);
    }

    /**
     * Set the "Set reminder in Timeline" date of a course module.
     *
     * This is a core course_modules column, not a module table, so it is set
     * directly and the course cache rebuilt, as the existing tests already do.
     *
     * @param int $cmid Course module ID.
     * @param int $time Reminder timestamp (0 clears it).
     * @return void
     */
    protected function set_reminder(int $cmid, int $time): void {
        global $DB;

        $courseid = (int) $DB->get_field('course_modules', 'course', ['id' => $cmid], MUST_EXIST);
        $DB->set_field('course_modules', 'completionexpected', $time, ['id' => $cmid]);
        rebuild_course_cache($courseid, true);
    }

    /**
     * Create a course with one student and one editing teacher.
     *
     * @return array [course, student, teacher]
     */
    protected function create_course_with_users(): array {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        return [$course, $student, $teacher];
    }

    /**
     * Read one grade row of an activity grade item.
     *
     * @param string $modname Module name.
     * @param int $instance Module instance ID.
     * @param int $userid User ID.
     * @param int $itemnumber Grade item number.
     * @return \stdClass|null The grade_grades row, or null when there is none.
     */
    protected function grade_row(string $modname, int $instance, int $userid, int $itemnumber = 0): ?\stdClass {
        global $DB;

        $itemid = $DB->get_field('grade_items', 'id', [
            'itemtype' => 'mod',
            'itemmodule' => $modname,
            'iteminstance' => $instance,
            'itemnumber' => $itemnumber,
        ]);
        if (!$itemid) {
            return null;
        }
        return $DB->get_record('grade_grades', ['itemid' => $itemid, 'userid' => $userid]) ?: null;
    }

    /**
     * Final grade of an activity grade item, rounded to two decimals.
     *
     * @param string $modname Module name.
     * @param int $instance Module instance ID.
     * @param int $userid User ID.
     * @param int $itemnumber Grade item number.
     * @return float|null The final grade, or null when there is no grade.
     */
    protected function final_grade(string $modname, int $instance, int $userid, int $itemnumber = 0): ?float {
        $row = $this->grade_row($modname, $instance, $userid, $itemnumber);
        if ($row === null || $row->finalgrade === null) {
            return null;
        }
        return round((float) $row->finalgrade, 2);
    }

    /**
     * Run whatever the plugin uses to pick up grades that changed under an existing penalty.
     *
     * Where core stores penalties in grade_grades.deductedmark this is a no-op
     * (the new grade reaches the plugin immediately); elsewhere the plugin relies
     * on a scheduled task, run here when it exists.
     *
     * @return void
     */
    protected function process_pending_penalties(): void {
        if (class_exists(\local_latepenalty\task\reprocess_grades::class)) {
            (new \local_latepenalty\task\reprocess_grades())->execute();
        }
    }

    /**
     * Rate an item as a teacher through the rating API, as the rating widget does.
     *
     * rating_manager::add_rating() stores the rating and calls the module's
     * <modname>_update_grades(), which aggregates and pushes the grade.
     *
     * @param int $cmid Course module ID of the rated activity.
     * @param string $ratingarea Rating area ('post', 'entry'...).
     * @param int $itemid Rated item ID.
     * @param int $rating Rating value (points, the scale is the item maximum).
     * @param int $rateduserid Author of the rated item.
     * @param \stdClass $teacher Rater.
     * @return void
     */
    protected function rate_item(
        int $cmid,
        string $ratingarea,
        int $itemid,
        int $rating,
        int $rateduserid,
        \stdClass $teacher
    ): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/rating/lib.php');

        $modname = $DB->get_field_sql(
            "SELECT m.name
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid",
            ['cmid' => $cmid],
            MUST_EXIST
        );
        [, $cm] = get_course_and_cm_from_cmid($cmid, $modname);
        $instance = $DB->get_record($modname, ['id' => $cm->instance], 'assessed, scale', MUST_EXIST);

        $this->setUser($teacher);
        $result = (new \rating_manager())->add_rating(
            $cm,
            \context_module::instance($cmid),
            'mod_' . $modname,
            $ratingarea,
            $itemid,
            (int) $instance->scale,
            $rating,
            $rateduserid,
            (int) $instance->assessed
        );
        $this->setAdminUser();

        $this->assertFalse(isset($result->error), 'Rating rejected: ' . ($result->error ?? ''));
    }

    /**
     * Create an online text assignment without drafts, so a save is a final submission.
     *
     * @param \stdClass $course Course.
     * @param array $params Extra assignment settings (duedate, grade...).
     * @return \stdClass The assignment record (with cmid).
     */
    protected function create_assign_activity(\stdClass $course, array $params = []): \stdClass {
        return $this->getDataGenerator()->create_module('assign', array_merge([
            'course' => $course->id,
            'grade' => 100,
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ], $params));
    }

    /**
     * Submit as the student through the assignment's own save_submission().
     *
     * @param \stdClass $assign Assignment record.
     * @param \stdClass $student Student.
     * @return void
     */
    protected function submit_assign(\stdClass $assign, \stdClass $student): void {
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission([
            'userid' => $student->id,
            'cmid' => $assign->cmid,
            'onlinetext' => 'Answer',
        ]);
    }

    /**
     * Grade as the teacher through assign::save_grade(), as the grading form does.
     *
     * @param \stdClass $assign Assignment record.
     * @param \stdClass $student Student.
     * @param \stdClass $teacher Teacher.
     * @param float $grade Grade (a scale item index on scale assignments).
     * @return void
     */
    protected function grade_assign(\stdClass $assign, \stdClass $student, \stdClass $teacher, float $grade): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        [$course, $cm] = get_course_and_cm_from_cmid($assign->cmid, 'assign');
        $instance = new \assign(\context_module::instance($cm->id), $cm, $course);

        $this->setUser($teacher);
        $instance->save_grade($student->id, (object) ['grade' => $grade, 'attemptnumber' => -1]);
        $this->setAdminUser();
    }

    /**
     * Save an activity's settings the way its edit form does (update_module()).
     *
     * Starts from the stored settings (get_moduleinfo_data(), as the form does)
     * and the stored Late Penalty rule; the given changes are applied on top.
     * update_module() runs local_latepenalty_coursemodule_edit_post_actions().
     *
     * @param int $cmid Course module ID.
     * @param array $changes Form fields to change (duedate, latepenalty_enabled...).
     * @return void
     */
    protected function save_activity_settings(int $cmid, array $changes): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        [, , , $data] = get_moduleinfo_data($cm, get_course($cm->course));
        $rule = $DB->get_record('local_latepenalty_rules', ['cmid' => $cmid]);
        if ($rule) {
            $data->latepenalty_enabled = $rule->enabled;
            $data->latepenalty_daily = $rule->daily_penalty;
            $data->latepenalty_max = $rule->max_penalty;
            $data->latepenalty_recalc_deadline = $rule->recalc_on_deadline;
            $data->latepenalty_recalc_rate = $rule->recalc_on_rate;
            $data->latepenalty_keepbest = $rule->keepbest ?? 0;
        }
        foreach ($changes as $field => $value) {
            $data->$field = $value;
        }
        update_module($data);
    }

    /**
     * Create a quiz with true/false questions worth one mark each.
     *
     * @param \stdClass $course Course.
     * @param int $questions Number of true/false questions (the maximum grade stays 100).
     * @param array $params Extra quiz settings (grademethod, duedate...).
     * @param int $essays Number of essay questions added after the true/false ones.
     * @return \stdClass The quiz record (with cmid).
     */
    protected function create_quiz_with_questions(
        \stdClass $course,
        int $questions = 10,
        array $params = [],
        int $essays = 0
    ): \stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $quiz = $this->getDataGenerator()->create_module('quiz', array_merge([
            'course' => $course->id,
            'grade' => 100,
            'questionsperpage' => 0,
            'timeclose' => 0,
        ], $params));

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category();
        for ($i = 0; $i < $questions; $i++) {
            $question = $questiongenerator->create_question('truefalse', null, ['category' => $category->id]);
            quiz_add_quiz_question($question->id, $quiz);
        }
        for ($i = 0; $i < $essays; $i++) {
            $question = $questiongenerator->create_question('essay', null, ['category' => $category->id]);
            quiz_add_quiz_question($question->id, $quiz);
        }
        \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();

        return $quiz;
    }

    /**
     * Make and finish a quiz attempt through the quiz's own API.
     *
     * The quiz generator starts the attempt, submits simulated responses and
     * finishes it at $timefinish (it already handles the 4.5 / 5.x difference
     * between process_finish() and process_submit() + process_grade_submission()).
     *
     * @param \stdClass $quiz Quiz record.
     * @param \stdClass $student Student.
     * @param int $correct How many questions to answer correctly (the rest wrong).
     * @param int $timefinish Attempt finish time.
     * @return void
     */
    protected function attempt_quiz(\stdClass $quiz, \stdClass $student, int $correct, int $timefinish): void {
        global $USER;

        $previous = $USER;
        $this->setUser($student);

        /** @var \mod_quiz_generator $quizgenerator */
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $attempt = $quizgenerator->create_attempt($quiz->id, $student->id);

        $slots = \mod_quiz\quiz_attempt::create($attempt->id)->get_slots();
        $responses = [];
        foreach (array_values($slots) as $index => $slot) {
            $responses[$slot] = $index < $correct ? 'True' : 'False';
        }
        $quizgenerator->submit_responses($attempt->id, $responses, false, true, $timefinish);

        $this->setUser($previous);
    }

    /**
     * Start a quiz attempt and leave it in progress.
     *
     * @param \stdClass $quiz Quiz record.
     * @param \stdClass $student Student.
     * @return int Attempt ID.
     */
    protected function start_quiz_attempt(\stdClass $quiz, \stdClass $student): int {
        global $USER;

        $previous = $USER;
        $this->setUser($student);
        $attempt = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_attempt($quiz->id, $student->id);
        $this->setUser($previous);

        return (int) $attempt->id;
    }

    /**
     * Make and finish a quiz attempt with free responses (essay questions need them).
     *
     * @param \stdClass $quiz Quiz record.
     * @param \stdClass $student Student.
     * @param array $responses Response summaries keyed by slot.
     * @param int $timefinish Attempt finish time.
     * @return int Attempt ID.
     */
    protected function attempt_quiz_with_responses(\stdClass $quiz, \stdClass $student, array $responses, int $timefinish): int {
        global $USER;

        $previous = $USER;
        $this->setUser($student);
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $attempt = $quizgenerator->create_attempt($quiz->id, $student->id);
        $quizgenerator->submit_responses($attempt->id, $responses, false, true, $timefinish);
        $this->setUser($previous);

        return (int) $attempt->id;
    }

    /**
     * Manually grade one question of a finished attempt, as mod/quiz/comment.php does.
     *
     * comment.php posts the behaviour fields of the slot and calls
     * quiz_attempt::process_submitted_actions(), which saves the mark, updates
     * the attempt total and recomputes the quiz grade.
     *
     * @param int $attemptid Attempt ID.
     * @param int $slot Slot of the question.
     * @param float $mark Mark given.
     * @param int $timestamp When the teacher grades.
     * @return void
     */
    protected function manually_grade_quiz_question(int $attemptid, int $slot, float $mark, int $timestamp): void {
        $attemptobj = \mod_quiz\quiz_attempt::create($attemptid);
        $qa = $attemptobj->get_question_attempt($slot);
        $post = [
            'slots' => (string) $slot,
            $qa->get_behaviour_field_name('comment') => '',
            // The comment is an editor field: the form always posts its draft area too.
            $qa->get_behaviour_field_name('comment') . ':itemid' => file_get_unused_draft_itemid(),
            $qa->get_behaviour_field_name('commentformat') => FORMAT_HTML,
            $qa->get_behaviour_field_name('mark') => $mark,
            $qa->get_behaviour_field_name('maxmark') => $qa->get_max_mark(),
            $qa->get_behaviour_field_name('minfraction') => $qa->get_min_fraction(),
            $qa->get_behaviour_field_name('maxfraction') => $qa->get_max_fraction(),
            $qa->get_control_field_name('sequencecheck') => $qa->get_sequence_check_count(),
        ];
        $attemptobj->process_submitted_actions($timestamp, false, $post);
    }

    /**
     * Assert that every recalculation path leaves the observer's result unchanged (F4-16).
     *
     * Runs the student, group and whole-activity recalculations with the rule's
     * own rates and the activity's own deadline, checking the grade after each.
     *
     * @param \stdClass $module Module record from create_module() (id, cmid, course).
     * @param string $modname Module name.
     * @param int $userid Student ID.
     * @param float $expected Expected final grade.
     * @param int $itemnumber Grade item number.
     * @return void
     */
    protected function assert_recalculations_keep(
        \stdClass $module,
        string $modname,
        int $userid,
        float $expected,
        int $itemnumber = 0
    ): void {
        global $DB;

        $rule = $DB->get_record('local_latepenalty_rules', ['cmid' => $module->cmid], '*', MUST_EXIST);
        $daily = (float) $rule->daily_penalty;
        $max = (float) $rule->max_penalty;
        $cm = get_coursemodule_from_id($modname, $module->cmid, 0, false, MUST_EXIST);
        $group = $this->getDataGenerator()->create_group(['courseid' => $cm->course]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $userid]);

        \local_latepenalty\recalculator::recalculate_for_student($module->cmid, $userid, $daily, $max);
        $this->assertSame($expected, $this->final_grade($modname, $module->id, $userid, $itemnumber), 'Student recalculation');

        \local_latepenalty\recalculator::recalculate_for_group($module->cmid, (int) $group->id, $daily, $max);
        $this->assertSame($expected, $this->final_grade($modname, $module->id, $userid, $itemnumber), 'Group recalculation');

        $deadline = \local_latepenalty\local\deadline_resolver::activity_deadline($cm)->time;
        \local_latepenalty\recalculator::recalculate($module->cmid, $deadline, $daily, $max);
        $this->assertSame($expected, $this->final_grade($modname, $module->id, $userid, $itemnumber), 'Activity recalculation');
    }
}
