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
 * Late penalties on modules without their own submission lookup (F12).
 *
 * External tools are driven through the paths they use: grade_update() with the
 * dates a module reports, LTI 1.3 scores (gradebookservices::save_score()) and
 * LTI 1.1 (lti_update_grade()); H5P through its generator, which grades with
 * h5pactivity_update_grades().
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\observer
 * @covers \local_latepenalty\recalculator
 * @covers \local_latepenalty\local\submission_resolver
 * @covers \local_latepenalty\penalty_helper
 * @covers \local_latepenalty\local\deadline_resolver
 * @covers \local_latepenalty\local\penalty_writer
 * @covers \local_latepenalty\local\deadline
 */
final class module_generic_test extends latepenalty_testcase {
    /**
     * Create an external tool with a rule, the reminder 5 days ago.
     *
     * @return array [lti, student, deadline]
     */
    private function scenario(): array {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id, 'grade' => 100]);
        $this->set_reminder($lti->cmid, $deadline);
        $this->enable_rule($lti->cmid);
        return [$lti, $student, $deadline];
    }

    /**
     * Push a grade the way a module does, with the dates it reports.
     *
     * @param \stdClass $lti Tool record.
     * @param int $userid Student ID.
     * @param float $grade Raw grade.
     * @param int|null $datesubmitted Submission date reported, if any.
     * @param int|null $dategraded Grading date reported, if any.
     * @return void
     */
    private function push(\stdClass $lti, int $userid, float $grade, ?int $datesubmitted, ?int $dategraded): void {
        $result = grade_update('mod/lti', $lti->course, 'mod', 'lti', $lti->id, 0, [
            'userid' => $userid,
            'rawgrade' => $grade,
            'datesubmitted' => $datesubmitted,
            'dategraded' => $dategraded,
        ]);
        $this->assertSame(GRADE_UPDATE_OK, $result);
    }

    /**
     * A reported on-time submission stays on time when the grade is redone later (F12-01).
     *
     * @return void
     */
    public function test_reported_submission_on_time_regraded_later(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$lti, $student, $deadline] = $this->scenario();

        $this->push($lti, $student->id, 90, $deadline - DAYSECS, $deadline - DAYSECS);
        $this->assertSame(90.0, $this->final_grade('lti', $lti->id, $student->id));

        $this->push($lti, $student->id, 100, $deadline - DAYSECS, time());
        $this->assertSame(100.0, $this->final_grade('lti', $lti->id, $student->id));
        $this->assert_recalculations_keep($lti, 'lti', $student->id, 100.0);
    }

    /**
     * A reported late submission is penalised from that date (F12-02).
     *
     * @return void
     */
    public function test_reported_late_submission(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$lti, $student, $deadline] = $this->scenario();

        $this->push($lti, $student->id, 90, $deadline + 2 * DAYSECS - 60, time());

        $this->assertSame(72.0, $this->final_grade('lti', $lti->id, $student->id));
        $this->assert_recalculations_keep($lti, 'lti', $student->id, 72.0);
    }

    /**
     * Without any reported date, the time the grade arrived is used (F12-03).
     *
     * @return void
     */
    public function test_no_reported_date_uses_arrival(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$lti, $student] = $this->scenario();

        $this->push($lti, $student->id, 90, null, null);

        $this->assertSame(45.0, $this->final_grade('lti', $lti->id, $student->id));
        $this->assert_recalculations_keep($lti, 'lti', $student->id, 45.0);
    }

    /**
     * LTI 1.3 scores carry the tool's timestamp as the grading date (F12-04).
     *
     * @return void
     */
    public function test_lti_advantage_score_timestamp(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$lti, $student, $deadline] = $this->scenario();
        $gradeitem = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'lti', 'iteminstance' => $lti->id,
            'itemnumber' => 0, 'courseid' => $lti->course]);

        $score = (object) ['scoreGiven' => 9, 'scoreMaximum' => 10, 'timestamp' => date('c', $deadline - DAYSECS)];
        \ltiservice_gradebookservices\local\service\gradebookservices::save_score($gradeitem, $score, $student->id);
        $this->assertSame(90.0, $this->final_grade('lti', $lti->id, $student->id));
        $this->assert_recalculations_keep($lti, 'lti', $student->id, 90.0);

        $other = $this->getDataGenerator()->create_and_enrol(get_course($lti->course), 'student');
        $score = (object) ['scoreGiven' => 9, 'scoreMaximum' => 10, 'timestamp' => date('c', $deadline + 2 * DAYSECS - 60)];
        \ltiservice_gradebookservices\local\service\gradebookservices::save_score($gradeitem, $score, $other->id);
        $this->assertSame(72.0, $this->final_grade('lti', $lti->id, $other->id));
    }

    /**
     * A module that stops reporting the submission date falls back to the arrival time (F12-05).
     *
     * @return void
     */
    public function test_reported_date_then_none(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$lti, $student, $deadline] = $this->scenario();

        $this->push($lti, $student->id, 90, $deadline - DAYSECS, $deadline - DAYSECS);
        $this->assertSame(90.0, $this->final_grade('lti', $lti->id, $student->id));

        $this->push($lti, $student->id, 80, null, null);

        $this->assertSame(40.0, $this->final_grade('lti', $lti->id, $student->id));
        $this->assert_recalculations_keep($lti, 'lti', $student->id, 40.0);
    }

    /**
     * LTI 1.1 sends no dates: the arrival time counts (F12-07, documented limitation).
     *
     * @return void
     */
    public function test_lti_basic_outcomes_arrival(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/lti/servicelib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        [$lti, $student] = $this->scenario();

        lti_update_grade($DB->get_record('lti', ['id' => $lti->id]), $student->id, 1, 0.9);

        $this->assertSame(45.0, $this->final_grade('lti', $lti->id, $student->id));
        $this->assert_recalculations_keep($lti, 'lti', $student->id, 45.0);
    }

    /**
     * H5P reports the attempt time as the submission date (F12-06).
     *
     * @return void
     */
    public function test_h5p_attempt_time(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $student] = $this->create_course_with_users();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_h5pactivity');
        foreach ([time() - 5 * DAYSECS => 45.0, time() + DAYSECS => 90.0] as $deadline => $expected) {
            $h5p = $this->getDataGenerator()->create_module('h5pactivity', [
                'course' => $course->id,
                'enabletracking' => 1,
                'grademethod' => 2,
            ]);
            $this->set_reminder($h5p->cmid, $deadline);
            $this->enable_rule($h5p->cmid);

            $generator->create_attempt([
                'h5pactivityid' => $h5p->id,
                'userid' => $student->id,
                'rawscore' => 9,
                'maxscore' => 10,
            ]);

            $this->assertSame($expected, $this->final_grade('h5pactivity', $h5p->id, $student->id));
            $this->assert_recalculations_keep($h5p, 'h5pactivity', $student->id, $expected);
        }
    }
}
