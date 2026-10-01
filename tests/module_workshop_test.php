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
 * Late penalties on workshops, which always have two grade items.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\observer
 * @covers \local_latepenalty\recalculator
 * @covers \local_latepenalty\penalty_helper
 */
final class module_workshop_test extends latepenalty_testcase {
    /**
     * Build a closed workshop where the author submitted one day late and was graded 80 by a peer.
     *
     * The author also assessed the peer's work and earned a grading grade of 90%.
     * Submissions and assessments come from the workshop generator; the grading
     * grade is the value the grading evaluation subplugin (workshopeval_best)
     * stores in workshop_assessments.gradinggrade. Grades reach the gradebook
     * through workshop::aggregate_*() and switch_phase(PHASE_CLOSED), exactly
     * like the "Close workshop" action.
     *
     * @param int $deadline Reminder date used as the penalty deadline.
     * @return array [workshop record, author]
     */
    private function create_graded_workshop(int $deadline): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/workshop/locallib.php');

        [$course, $author] = $this->create_course_with_users();
        $peer = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($peer->id, $course->id, 'student');

        $record = $this->getDataGenerator()->create_module('workshop', [
            'course' => $course->id,
            'grade' => 100,
            'gradinggrade' => 20,
        ]);
        $this->set_reminder($record->cmid, $deadline);
        $this->enable_rule($record->cmid);

        /** @var \mod_workshop_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_workshop');
        $late = $deadline + DAYSECS - 60;
        $authorsubmission = $generator->create_submission($record->id, $author->id, [
            'timecreated' => $late,
            'timemodified' => $late,
        ]);
        $peersubmission = $generator->create_submission($record->id, $peer->id, [
            'timecreated' => $deadline - DAYSECS,
            'timemodified' => $deadline - DAYSECS,
        ]);
        $generator->create_assessment($authorsubmission, $peer->id, ['grade' => 80]);
        $generator->create_assessment($peersubmission, $author->id, ['grade' => 70, 'gradinggrade' => 90]);

        [$course, $cm] = get_course_and_cm_from_cmid($record->cmid, 'workshop');
        $workshop = new \workshop($record, $cm, $course);
        $workshop->aggregate_submission_grades();
        $workshop->aggregate_grading_grades();
        $workshop->switch_phase(\workshop::PHASE_CLOSED);

        return [$record, $author];
    }

    /**
     * The late submission grade is penalised and the grading grade is left alone (F13-01).
     *
     * @return void
     */
    public function test_late_submission_grade_is_penalised_and_grading_grade_is_not(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$workshop, $author] = $this->create_graded_workshop(time() - 5 * DAYSECS);

        $this->assertSame(72.0, $this->final_grade('workshop', $workshop->id, $author->id, 0));
        $this->assertSame(18.0, $this->final_grade('workshop', $workshop->id, $author->id, 1));
    }

    /**
     * Recalculating a workshop works for one student and for the whole activity (F13-02).
     *
     * @return void
     */
    public function test_recalculation_handles_both_grade_items(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $deadline = time() - 5 * DAYSECS;
        [$workshop, $author] = $this->create_graded_workshop($deadline);

        recalculator::recalculate_for_student($workshop->cmid, $author->id, 10.0, 50.0);
        $this->assertSame(72.0, $this->final_grade('workshop', $workshop->id, $author->id, 0));

        recalculator::recalculate($workshop->cmid, $deadline, 20.0, 50.0);
        $this->assertSame(64.0, $this->final_grade('workshop', $workshop->id, $author->id, 0));
        $this->assertSame(18.0, $this->final_grade('workshop', $workshop->id, $author->id, 1));
    }
}
