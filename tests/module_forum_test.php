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
 * Late penalties on rated forums, driven through the forum and rating APIs.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\observer
 * @covers \local_latepenalty\recalculator
 * @covers \local_latepenalty\penalty_helper
 */
final class module_forum_test extends latepenalty_testcase {
    /**
     * Start a discussion as the student at a given time, through the forum generator.
     *
     * @param \stdClass $forum Forum record.
     * @param \stdClass $student Author.
     * @param int $time Post creation time.
     * @return int The first post ID.
     */
    private function post(\stdClass $forum, \stdClass $student, int $time): int {
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $forum->course,
            'forum' => $forum->id,
            'userid' => $student->id,
            'timemodified' => $time,
        ]);
        return (int) $discussion->firstpost;
    }

    /**
     * Grade the whole forum as the teacher, through the forum grading API.
     *
     * @param \stdClass $forum Forum record.
     * @param \stdClass $student Graded student.
     * @param \stdClass $teacher Grader.
     * @param float $grade Grade.
     * @return void
     */
    private function grade_whole_forum(\stdClass $forum, \stdClass $student, \stdClass $teacher, float $grade): void {
        $gradeitem = \core_grades\component_gradeitem::instance('mod_forum', \context_module::instance($forum->cmid), 'forum');
        $this->setUser($teacher);
        $gradeitem->store_grade_from_formdata($student, $teacher, (object) ['grade' => $grade]);
        $this->setAdminUser();
    }

    /**
     * Ratings and whole-forum grading: both grade items are penalised (F13-03).
     *
     * @return void
     */
    public function test_ratings_and_whole_forum_grade_are_both_penalised(): void {
        global $CFG;
        require_once($CFG->dirroot . '/rating/lib.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        $deadline = time() - 5 * DAYSECS;
        [$course, $student, $teacher] = $this->create_course_with_users();
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id,
            'assessed' => RATING_AGGREGATE_AVERAGE,
            'scale' => 100,
            'grade_forum' => 100,
            'duedate' => $deadline,
        ]);
        $this->enable_rule($forum->cmid);

        $post = $this->post($forum, $student, $deadline + DAYSECS - 60);
        $this->rate_item($forum->cmid, 'post', $post, 80, $student->id, $teacher);
        $this->grade_whole_forum($forum, $student, $teacher, 80);

        $this->assertSame(72.0, $this->final_grade('forum', $forum->id, $student->id, 0));
        $this->assertSame(72.0, $this->final_grade('forum', $forum->id, $student->id, 1));

        recalculator::recalculate($forum->cmid, $deadline, 20.0, 50.0);

        $this->assertSame(64.0, $this->final_grade('forum', $forum->id, $student->id, 0));
        $this->assertSame(64.0, $this->final_grade('forum', $forum->id, $student->id, 1));
    }

    /**
     * Whole-forum grading only: the single item (itemnumber 1) is penalised (F13-04).
     *
     * @return void
     */
    public function test_whole_forum_grade_alone_is_penalised(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $deadline = time() - 5 * DAYSECS;
        [$course, $student, $teacher] = $this->create_course_with_users();
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id,
            'grade_forum' => 100,
            'duedate' => $deadline,
        ]);
        $this->enable_rule($forum->cmid);

        $this->post($forum, $student, $deadline + DAYSECS - 60);
        $this->grade_whole_forum($forum, $student, $teacher, 80);

        $this->assertSame(72.0, $this->final_grade('forum', $forum->id, $student->id, 1));

        recalculator::recalculate_for_student($forum->cmid, $student->id, 20.0, 50.0);

        $this->assertSame(64.0, $this->final_grade('forum', $forum->id, $student->id, 1));
    }

    /**
     * Maximum aggregation: the on-time post rated 90 decides the grade, not the later post (F4-11).
     *
     * @return void
     */
    public function test_maximum_aggregation_uses_post_that_produced_grade(): void {
        global $CFG;
        require_once($CFG->dirroot . '/rating/lib.php');

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
        $this->enable_rule($forum->cmid);

        $ontime = $this->post($forum, $student, $deadline - DAYSECS);
        $late = $this->post($forum, $student, $deadline + 2 * DAYSECS - 60);
        $this->rate_item($forum->cmid, 'post', $ontime, 90, $student->id, $teacher);
        $this->rate_item($forum->cmid, 'post', $late, 60, $student->id, $teacher);
        $this->assertSame(90.0, $this->final_grade('forum', $forum->id, $student->id));

        recalculator::recalculate_for_student($forum->cmid, $student->id, 10.0, 50.0);

        $this->assertSame(90.0, $this->final_grade('forum', $forum->id, $student->id));
    }

    /**
     * Create a rated forum with a rule and the due date 5 days ago.
     *
     * @param int $aggregation Rating aggregation (RATING_AGGREGATE_*).
     * @return array [forum, student, teacher, deadline]
     */
    private function rated_forum(int $aggregation): array {
        global $CFG;
        require_once($CFG->dirroot . '/rating/lib.php');

        $deadline = time() - 5 * DAYSECS;
        [$course, $student, $teacher] = $this->create_course_with_users();
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id,
            'assessed' => $aggregation,
            'scale' => 100,
            'duedate' => $deadline,
        ]);
        $this->enable_rule($forum->cmid);
        return [$forum, $student, $teacher, $deadline];
    }

    /**
     * Minimum aggregation: the post with the lowest rating decides (F4-12).
     *
     * @return void
     */
    public function test_minimum_aggregation_uses_lowest_rated_post(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$forum, $student, $teacher, $deadline] = $this->rated_forum(RATING_AGGREGATE_MINIMUM);

        $this->rate_item($forum->cmid, 'post', $this->post($forum, $student, $deadline - DAYSECS), 90, $student->id, $teacher);
        $late = $this->post($forum, $student, $deadline + 2 * DAYSECS - 60);
        $this->rate_item($forum->cmid, 'post', $late, 60, $student->id, $teacher);

        $this->assertSame(48.0, $this->final_grade('forum', $forum->id, $student->id));
        $this->assert_recalculations_keep($forum, 'forum', $student->id, 48.0);
    }

    /**
     * Count aggregation: the latest rated post completes the grade (F4-13).
     *
     * @return void
     */
    public function test_count_aggregation_uses_latest_rated_post(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$forum, $student, $teacher, $deadline] = $this->rated_forum(RATING_AGGREGATE_COUNT);

        $this->rate_item($forum->cmid, 'post', $this->post($forum, $student, $deadline - DAYSECS), 90, $student->id, $teacher);
        $late = $this->post($forum, $student, $deadline + 2 * DAYSECS - 60);
        $this->rate_item($forum->cmid, 'post', $late, 60, $student->id, $teacher);

        // Two ratings counted, the last one on a post two days late: 2 - 20%.
        $this->assertSame(1.6, $this->final_grade('forum', $forum->id, $student->id));
        $this->assert_recalculations_keep($forum, 'forum', $student->id, 1.6);
    }

    /**
     * Two raters on the same post: the core aggregate over all ratings decides (F4-15).
     *
     * @return void
     */
    public function test_two_raters_follow_core_aggregation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$forum, $student, $teacher, $deadline] = $this->rated_forum(RATING_AGGREGATE_MAXIMUM);
        $second = $this->getDataGenerator()->create_and_enrol(get_course($forum->course), 'editingteacher');

        $ontime = $this->post($forum, $student, $deadline - DAYSECS);
        $late = $this->post($forum, $student, $deadline + 2 * DAYSECS - 60);
        $this->rate_item($forum->cmid, 'post', $ontime, 50, $student->id, $teacher);
        $this->rate_item($forum->cmid, 'post', $ontime, 80, $student->id, $second);
        $this->rate_item($forum->cmid, 'post', $late, 70, $student->id, $teacher);

        // Highest rating overall is 80, given to the on-time post by the second rater.
        $this->assertSame(80.0, $this->final_grade('forum', $forum->id, $student->id));
        $this->assert_recalculations_keep($forum, 'forum', $student->id, 80.0);
    }
}
