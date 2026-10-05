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

/**
 * Tests for the course reset with a new start date.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty;

use local_latepenalty\tests\latepenalty_testcase;

/**
 * A reset that moves the course start date moves the plugin's deadlines with it.
 *
 * Core shifts the activities' own dates and overrides (shift_course_mod_dates(),
 * assign and quiz overrides); the Late Penalty overrides and the deadline the
 * rule last saw must move by the same amount.
 *
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\observer
 */
final class course_reset_test extends latepenalty_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Course with an assignment and rule, a student and a group with overrides.
     *
     * @return array [course, assign, student, group, other course's assignment]
     */
    private function course_with_overrides(): array {
        $generator = $this->getDataGenerator();
        $start = strtotime('1 Feb 2026 00:00 GMT');
        $course = $generator->create_course(['startdate' => $start]);
        $student = $generator->create_and_enrol($course, 'student');
        $ratesonly = $generator->create_and_enrol($course, 'student');
        $group = $generator->create_group(['courseid' => $course->id]);
        $assign = $this->create_assign_activity($course, ['duedate' => $start + 30 * DAYSECS]);
        $this->enable_rule($assign->cmid);
        $this->lp_generator()->create_override(
            ['cmid' => $assign->cmid, 'userid' => $student->id, 'deadline' => $start + 35 * DAYSECS]
        );
        $this->lp_generator()->create_override(['cmid' => $assign->cmid, 'userid' => $ratesonly->id, 'daily_penalty' => 5]);
        $this->lp_generator()->create_group_override(
            ['cmid' => $assign->cmid, 'groupid' => $group->id, 'deadline' => $start + 40 * DAYSECS]
        );

        $other = $generator->create_course(['startdate' => $start]);
        $otherassign = $this->create_assign_activity($other, ['duedate' => $start + 30 * DAYSECS]);
        $this->enable_rule($otherassign->cmid);
        $this->lp_generator()->create_override(
            ['cmid' => $otherassign->cmid, 'userid' => $student->id, 'deadline' => $start + 35 * DAYSECS]
        );
        return [$course, $assign, $student, $group, $otherassign, $ratesonly];
    }

    /**
     * A reset to a later start date moves every Late Penalty deadline of the course by the same amount.
     *
     * @return void
     */
    public function test_reset_shifts_deadlines(): void {
        global $DB;

        [$course, $assign, $student, $group, $otherassign, $ratesonly] = $this->course_with_overrides();
        $shift = 181 * DAYSECS;
        $before = [
            'user' => (int) $DB->get_field(
                'local_latepenalty_overrides',
                'deadline',
                ['cmid' => $assign->cmid, 'userid' => $student->id]
            ),
            'group' => (int) $DB->get_field('local_latepenalty_group_overrides', 'deadline', ['cmid' => $assign->cmid]),
            'rule' => (int) $DB->get_field('local_latepenalty_rules', 'last_deadline', ['cmid' => $assign->cmid]),
            'other' => (int) $DB->get_field('local_latepenalty_overrides', 'deadline', ['cmid' => $otherassign->cmid]),
        ];

        $DB->set_field('local_latepenalty_rules', 'timeenabled', $course->startdate + DAYSECS, ['cmid' => $assign->cmid]);

        reset_course_userdata((object) [
            'id' => $course->id,
            'reset_start_date' => $course->startdate + $shift,
            'reset_start_date_old' => $course->startdate,
            'reset_end_date_old' => $course->enddate,
        ]);

        $this->assertSame($before['rule'] + $shift, (int) $DB->get_field('assign', 'duedate', ['id' => $assign->id]), 'Core');
        $this->assertSame(
            $before['user'] + $shift,
            (int) $DB->get_field('local_latepenalty_overrides', 'deadline', ['cmid' => $assign->cmid, 'userid' => $student->id])
        );
        $this->assertNull(
            $DB->get_field('local_latepenalty_overrides', 'deadline', ['cmid' => $assign->cmid, 'userid' => $ratesonly->id])
        );
        $this->assertSame(
            $before['group'] + $shift,
            (int) $DB->get_field('local_latepenalty_group_overrides', 'deadline', ['groupid' => $group->id])
        );
        $this->assertSame(
            $before['rule'] + $shift,
            (int) $DB->get_field('local_latepenalty_rules', 'last_deadline', ['cmid' => $assign->cmid])
        );
        $this->assertSame(
            $course->startdate + DAYSECS,
            (int) $DB->get_field('local_latepenalty_rules', 'timeenabled', ['cmid' => $assign->cmid]),
            'First enabling not shifted (F19-17)'
        );
        $this->assertSame(
            $before['other'],
            (int) $DB->get_field('local_latepenalty_overrides', 'deadline', ['cmid' => $otherassign->cmid]),
            'Other course'
        );
    }

    /**
     * A reset that keeps the start date leaves every deadline where it was.
     *
     * @return void
     */
    public function test_reset_without_new_start_date_keeps_deadlines(): void {
        global $DB;

        [$course, $assign] = $this->course_with_overrides();
        $before = $DB->get_records_menu('local_latepenalty_overrides', ['cmid' => $assign->cmid], '', 'id, deadline');

        reset_course_userdata((object) ['id' => $course->id, 'reset_groups_members' => 1]);

        $after = $DB->get_records_menu('local_latepenalty_overrides', ['cmid' => $assign->cmid], '', 'id, deadline');
        $this->assertSame($before, $after);
    }

    /**
     * The first save of the activity after a reset that kept the grades changes none of them.
     *
     * Regression guard: the rule kept the deadline from before the reset, so the
     * save read the shifted due date as a teacher's change and recalculated the
     * grades of the term that ended against it.
     *
     * @return void
     */
    public function test_first_save_after_reset_keeps_grades(): void {
        [$course, $student, $teacher] = $this->create_course_with_users();
        $assign = $this->create_assign_activity($course, ['duedate' => time() - 2 * DAYSECS + HOURSECS]);
        $this->enable_rule($assign->cmid);
        $this->submit_assign($assign, $student);
        $this->grade_assign($assign, $student, $teacher, 100);
        $this->assertSame(80.0, $this->final_grade('assign', $assign->id, $student->id));

        reset_course_userdata((object) [
            'id' => $course->id,
            'reset_start_date' => $course->startdate + 181 * DAYSECS,
            'reset_start_date_old' => $course->startdate,
            'reset_end_date_old' => $course->enddate,
        ]);
        $this->save_activity_settings($assign->cmid, ['assignsubmission_onlinetext_enabled' => 1]);

        $this->assertSame(80.0, $this->final_grade('assign', $assign->id, $student->id));
    }
}
