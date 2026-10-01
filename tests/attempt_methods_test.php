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
use mod_h5pactivity\local\manager;

/**
 * First, last and average attempt on H5P and SCORM, the quiz rules applied to them (F4, F12).
 *
 * As with quizzes: the first and last methods use the attempt that produced
 * the grade, and the average follows the latest attempt. The rule is 10% a
 * day up to 50%, the deadline 5 days ago, so a late attempt made now loses 50%.
 *
 * H5P reports the time of the attempt behind the grade (the latest one for the
 * average). SCORM reports no submission date, so the time the grade reached
 * the gradebook counts; its first attempt is therefore made while the deadline
 * is still ahead, and every stored time is then moved back, as if those days
 * had passed.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\local\submission_resolver
 * @covers \local_latepenalty\observer
 * @covers \local_latepenalty\recalculator
 * @covers \local_latepenalty\penalty_helper
 * @covers \local_latepenalty\local\deadline_resolver
 * @covers \local_latepenalty\local\penalty_writer
 * @covers \local_latepenalty\local\deadline
 */
final class attempt_methods_test extends latepenalty_testcase {
    /**
     * H5P methods: [grading method, first score, second score, expected grade].
     *
     * @return array
     */
    public static function h5p_methods(): array {
        return [
            'first: the on-time first attempt counts' => [manager::GRADEFIRSTATTEMPT, 9, 10, 90.0],
            'last: the late last attempt counts' => [manager::GRADELASTATTEMPT, 10, 8, 40.0],
            'average: follows the late latest attempt' => [manager::GRADEAVERAGEATTEMPT, 8, 10, 45.0],
        ];
    }

    /**
     * H5P: an attempt one day before the deadline, then one now, 5 days late.
     *
     * @dataProvider h5p_methods
     * @param int $grademethod H5P grading method.
     * @param int $firstscore Score of the on-time attempt, out of 10.
     * @param int $secondscore Score of the late attempt, out of 10.
     * @param float $expected Expected final grade.
     * @return void
     */
    public function test_h5p(int $grademethod, int $firstscore, int $secondscore, float $expected): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $h5p = $this->getDataGenerator()->create_module('h5pactivity', [
            'course' => $course->id,
            'enabletracking' => 1,
            'grademethod' => $grademethod,
        ]);
        $this->set_reminder($h5p->cmid, $deadline);
        $this->enable_rule($h5p->cmid);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_h5pactivity');

        $generator->create_attempt(['h5pactivityid' => $h5p->id, 'userid' => $student->id, 'attempt' => 1,
            'rawscore' => $firstscore, 'maxscore' => 10]);
        // The generator stamps attempts with time(): move the first one back before the deadline.
        $first = ['h5pactivityid' => $h5p->id, 'userid' => $student->id, 'attempt' => 1];
        $DB->set_field('h5pactivity_attempts', 'timemodified', $deadline - DAYSECS, $first);
        $DB->set_field('h5pactivity_attempts', 'timecreated', $deadline - DAYSECS, $first);
        $generator->create_attempt(['h5pactivityid' => $h5p->id, 'userid' => $student->id, 'attempt' => 2,
            'rawscore' => $secondscore, 'maxscore' => 10]);
        $this->process_pending_penalties();

        $this->assertSame($expected, $this->final_grade('h5pactivity', $h5p->id, $student->id));
        $this->assert_recalculations_keep($h5p, 'h5pactivity', $student->id, $expected);
    }

    /**
     * SCORM methods: [what grade, first score, second score, expected grade].
     *
     * @return array
     */
    public static function scorm_methods(): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        return [
            'first: the on-time first attempt counts' => [(int) FIRSTATTEMPT, 90, 100, 90.0],
            'last: the late last attempt counts' => [(int) LASTATTEMPT, 100, 80, 40.0],
            'average: follows the late latest attempt' => [(int) AVERAGEATTEMPT, 80, 100, 45.0],
        ];
    }

    /**
     * SCORM: an attempt 6 days ago, before the deadline, then one now, 5 days late.
     *
     * Tracks are written with scorm_insert_track(), as the SCORM player does,
     * and grades are pushed with scorm_update_grades().
     *
     * @dataProvider scorm_methods
     * @param int $whatgrade SCORM attempt grading method.
     * @param int $firstscore Score of the on-time attempt.
     * @param int $secondscore Score of the late attempt.
     * @param float $expected Expected final grade.
     * @return void
     */
    public function test_scorm(int $whatgrade, int $firstscore, int $secondscore, float $expected): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        $shift = 6 * DAYSECS;
        $deadline = time() + DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $created = $this->getDataGenerator()->create_module('scorm', [
            'course' => $course->id,
            'maxgrade' => 100,
            'grademethod' => GRADEHIGHEST,
            'whatgrade' => $whatgrade,
            'maxattempt' => 0,
        ]);
        $this->set_reminder($created->cmid, $deadline);
        $this->enable_rule($created->cmid);
        $scorm = $DB->get_record('scorm', ['id' => $created->id], '*', MUST_EXIST);
        $scorm->cmidnumber = '';
        $scoid = (int) $DB->get_field('scorm_scoes', 'id', ['scorm' => $scorm->id, 'scormtype' => 'sco'], IGNORE_MULTIPLE);
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'scorm', 'iteminstance' => $scorm->id,
            'itemnumber' => 0, 'courseid' => $course->id]);

        $this->scorm_attempt($scorm, $scoid, $student->id, 1, $firstscore);
        $this->assertSame((float) $firstscore, $this->final_grade('scorm', $scorm->id, $student->id), 'On time');

        // Six days pass: the deadline is now 5 days ago, and so are the first attempt and its grade.
        $this->set_reminder($created->cmid, $deadline - $shift);
        $attemptid = $DB->get_field('scorm_attempt', 'id', ['scormid' => $scorm->id, 'userid' => $student->id,
            'attempt' => 1]);
        $moved = [
            ['scorm_scoes_value', 'attemptid = ?', [$attemptid]],
            ['grade_grades', 'itemid = ? AND userid = ?', [$item->id, $student->id]],
            ['grade_grades_history', 'itemid = ? AND userid = ?', [$item->id, $student->id]],
        ];
        foreach ($moved as [$table, $where, $params]) {
            $DB->execute("UPDATE {{$table}} SET timemodified = timemodified - ? WHERE $where", array_merge([$shift], $params));
        }

        $this->scorm_attempt($scorm, $scoid, $student->id, 2, $secondscore);
        $this->process_pending_penalties();

        $this->assertSame($expected, $this->final_grade('scorm', $scorm->id, $student->id));
        $this->assert_recalculations_keep($created, 'scorm', $student->id, $expected);
    }

    /**
     * One completed SCORM attempt, graded as the player does.
     *
     * @param \stdClass $scorm SCORM record with cmidnumber.
     * @param int $scoid SCO ID.
     * @param int $userid Student ID.
     * @param int $attempt Attempt number.
     * @param int $score Raw score.
     * @return void
     */
    private function scorm_attempt(\stdClass $scorm, int $scoid, int $userid, int $attempt, int $score): void {
        scorm_insert_track($userid, $scorm->id, $scoid, $attempt, 'cmi.core.score.raw', $score);
        scorm_insert_track($userid, $scorm->id, $scoid, $attempt, 'cmi.core.lesson_status', 'completed');
        scorm_update_grades($scorm, $userid);
    }
}
