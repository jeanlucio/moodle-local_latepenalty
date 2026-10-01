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

use local_latepenalty\tests\latepenalty_testcase;

/**
 * Who has handed an activity in, for the badges and notices: work done, graded or not.
 *
 * Every module is driven through its own API (or, for the lesson, the exact row
 * its end-of-lesson page writes). Activity completion never counts as handing in.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\local\submission_resolver
 */
final class handed_in_test extends latepenalty_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Student who hands in. */
    private \stdClass $student;

    /** @var \stdClass Student who does not. */
    private \stdClass $other;

    /** @var \stdClass Teacher. */
    private \stdClass $teacher;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        [$this->course, $this->student, $this->teacher] = $this->create_course_with_users();
        $this->other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Course module record of a created activity.
     *
     * @param \stdClass $module Module record from the generator.
     * @param string $modname Module name.
     * @return \stdClass
     */
    private function cm(\stdClass $module, string $modname): \stdClass {
        return (object) ['id' => (int) $module->cmid, 'modname' => $modname, 'instance' => (int) $module->id];
    }

    /**
     * Assert who has handed one activity in, among the two students.
     *
     * @param \stdClass $cm Course module record.
     * @param bool $student Whether the first student has.
     * @param string $message Failure message.
     * @return void
     */
    private function assert_handed_in(\stdClass $cm, bool $student, string $message = ''): void {
        $expected = $student ? [(int) $this->student->id => true] : [];
        $this->assertSame($expected, submission_resolver::handed_in([$cm], null)[$cm->id], $message);
        $both = [$this->student->id, $this->other->id];
        $this->assertSame($expected, submission_resolver::handed_in([$cm], $both)[$cm->id], $message);
    }

    /**
     * Assignment: a submitted submission counts, a draft does not, and completing the activity does not.
     *
     * @return void
     */
    public function test_assignment(): void {
        global $DB;

        $DB->set_field('course', 'enablecompletion', 1, ['id' => $this->course->id]);
        $this->course->enablecompletion = 1;
        $assign = $this->create_assign_activity($this->course, ['completion' => COMPLETION_TRACKING_MANUAL]);
        $cm = $this->cm($assign, 'assign');
        $this->assert_handed_in($cm, false);

        [, $cminfo] = get_course_and_cm_from_cmid($assign->cmid, 'assign');
        (new \completion_info($this->course))->update_state($cminfo, COMPLETION_COMPLETE, $this->student->id);
        $this->assert_handed_in($cm, false, 'Completed without a submission');

        $this->submit_assign($assign, $this->student);
        $this->assert_handed_in($cm, true);

        $drafts = $this->create_assign_activity($this->course, ['submissiondrafts' => 1]);
        $this->submit_assign($drafts, $this->student);
        $this->assert_handed_in($this->cm($drafts, 'assign'), false, 'Draft');
    }

    /**
     * Team assignment: the team's submission counts for every member.
     *
     * @return void
     */
    public function test_team_assignment(): void {
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $this->student->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $this->other->id]);
        $assign = $this->create_assign_activity($this->course, ['teamsubmission' => 1]);
        $cm = $this->cm($assign, 'assign');

        $this->submit_assign($assign, $this->student);

        $both = [(int) $this->student->id => true, (int) $this->other->id => true];
        $this->assertEquals($both, submission_resolver::handed_in([$cm], null)[$cm->id]);
        $other = submission_resolver::handed_in([$cm], [$this->other->id]);
        $this->assertEquals([(int) $this->other->id => true], $other[$cm->id]);
    }

    /**
     * Quiz: an attempt in progress does not count, a finished one does.
     *
     * @return void
     */
    public function test_quiz(): void {
        $quiz = $this->create_quiz_with_questions($this->course, 2);
        $cm = $this->cm($quiz, 'quiz');

        $this->start_quiz_attempt($quiz, $this->other);
        $this->assert_handed_in($cm, false, 'In progress');

        $this->attempt_quiz($quiz, $this->student, 1, time());
        $this->assert_handed_in($cm, true);
    }

    /**
     * Lesson: a finished lesson counts.
     *
     * The row is the one lesson::process_eol_page() writes at the end of the lesson.
     *
     * @return void
     */
    public function test_lesson(): void {
        global $DB;

        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $this->course->id]);
        $cm = $this->cm($lesson, 'lesson');
        $this->assert_handed_in($cm, false);

        $DB->insert_record('lesson_grades', (object) [
            'lessonid' => $lesson->id,
            'userid' => $this->student->id,
            'grade' => 80,
            'completed' => time(),
        ]);
        $this->assert_handed_in($cm, true);
    }

    /**
     * Forum, glossary and database: a post, entry or record counts before anyone rates it.
     *
     * @return void
     */
    public function test_rated_activities_before_rating(): void {
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id,
            'assessed' => RATING_AGGREGATE_AVERAGE, 'scale' => 100]);
        $this->assert_handed_in($this->cm($forum, 'forum'), false);
        $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $this->course->id,
            'forum' => $forum->id,
            'userid' => $this->student->id,
        ]);
        $this->assert_handed_in($this->cm($forum, 'forum'), true, 'Forum');

        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $this->course->id,
            'assessed' => RATING_AGGREGATE_AVERAGE, 'scale' => 100]);
        $this->assert_handed_in($this->cm($glossary, 'glossary'), false);
        $this->getDataGenerator()->get_plugin_generator('mod_glossary')->create_content($glossary, [
            'userid' => $this->student->id,
            'approved' => 0,
        ]);
        $this->assert_handed_in($this->cm($glossary, 'glossary'), true, 'Glossary entry awaiting approval');

        $data = $this->getDataGenerator()->create_module('data', ['course' => $this->course->id,
            'assessed' => RATING_AGGREGATE_AVERAGE, 'scale' => 100]);
        $this->assert_handed_in($this->cm($data, 'data'), false);
        $datagenerator = $this->getDataGenerator()->get_plugin_generator('mod_data');
        $field = $datagenerator->create_field((object) ['type' => 'text', 'name' => 'answer'], $data);
        $datagenerator->create_entry($data, [$field->field->id => 'Answer'], 0, [], null, $this->student->id);
        $this->assert_handed_in($this->cm($data, 'data'), true, 'Database');
    }

    /**
     * Workshop: a submission counts; example submissions by the teacher do not.
     *
     * @return void
     */
    public function test_workshop(): void {
        $workshop = $this->getDataGenerator()->create_module('workshop', ['course' => $this->course->id]);
        $cm = $this->cm($workshop, 'workshop');
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_workshop');

        $generator->create_submission($workshop->id, $this->other->id, ['example' => 1]);
        $this->assert_handed_in($cm, false, 'Example submission');

        $generator->create_submission($workshop->id, $this->student->id);
        $this->assert_handed_in($cm, true);
    }

    /**
     * Any other module: a grade it sent counts (an external tool here).
     *
     * @return void
     */
    public function test_other_module(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $this->course->id, 'grade' => 100]);
        $cm = $this->cm($lti, 'lti');
        $this->assert_handed_in($cm, false);

        grade_update('mod/lti', $this->course->id, 'mod', 'lti', $lti->id, 0, ['userid' => $this->student->id,
            'rawgrade' => 70]);
        $this->assert_handed_in($cm, true);
    }

    /**
     * Several activities at once: every one has an entry, and an empty user list returns empty sets.
     *
     * @return void
     */
    public function test_several_activities_and_empty_users(): void {
        $first = $this->create_assign_activity($this->course);
        $second = $this->create_assign_activity($this->course);
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);
        $this->submit_assign($second, $this->student);
        $cms = [$this->cm($first, 'assign'), $this->cm($second, 'assign'), $this->cm($forum, 'forum')];

        $result = submission_resolver::handed_in($cms, null);

        $this->assertSame([$first->cmid, $second->cmid, $forum->cmid], array_keys($result));
        $this->assertSame([], $result[$first->cmid]);
        $this->assertSame([(int) $this->student->id => true], $result[$second->cmid]);
        $this->assertSame([], $result[$forum->cmid]);
        $this->assertSame(array_fill_keys(array_keys($result), []), submission_resolver::handed_in($cms, []));
        $this->assertSame([], submission_resolver::handed_in([], null));
    }

    /**
     * The number of queries depends on the module types, not on the number of activities.
     *
     * @return void
     */
    public function test_queries_do_not_grow_with_activities(): void {
        global $DB;

        $count = function (int $assigns): int {
            global $DB;
            $cms = [];
            for ($i = 0; $i < $assigns; $i++) {
                $assign = $this->create_assign_activity($this->course);
                $this->submit_assign($assign, $this->student);
                $cms[] = $this->cm($assign, 'assign');
            }
            $quiz = $this->create_quiz_with_questions($this->course, 1);
            $cms[] = $this->cm($quiz, 'quiz');
            submission_resolver::handed_in($cms, null);
            $before = $DB->perf_get_reads();
            submission_resolver::handed_in($cms, [$this->student->id]);
            return $DB->perf_get_reads() - $before;
        };

        $this->assertSame($count(2), $count(8));
        $this->assertGreaterThan(0, $DB->perf_get_reads());
    }
}
