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

namespace local_latepenalty\report;

use local_latepenalty\local\deadline_resolver;
use local_latepenalty\tests\latepenalty_testcase;

/**
 * The report's deadline column: each student's effective deadline and where it comes from (F8).
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\report\controller
 * @covers \local_latepenalty\penalty_helper
 * @covers \local_latepenalty\observer
 * @covers \local_latepenalty\recalculator
 * @covers \local_latepenalty\local\deadline_resolver
 * @covers \local_latepenalty\local\submission_resolver
 * @covers \local_latepenalty\local\penalty_writer
 * @covers \local_latepenalty\local\deadline
 */
final class deadline_column_test extends latepenalty_testcase {
    /**
     * Report of a course as a teacher with access to all groups sees it.
     *
     * @param \stdClass $course Course.
     * @return controller
     */
    private function report(\stdClass $course): controller {
        return new controller((int) $course->id, \context_course::instance($course->id));
    }

    /**
     * Penalties rows keyed by "student name|activity name".
     *
     * @param controller $report Report.
     * @return array
     */
    private function rows(controller $report): array {
        $rows = [];
        foreach ($report->get_template_context()['penalties'] as $row) {
            $rows[$row['fullname'] . '|' . $row['activity']] = $row;
        }
        return $rows;
    }

    /**
     * One row per deadline origin, on screen and in the export (F8-01, F8-02, F8-05).
     *
     * @return void
     */
    public function test_each_origin(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/lesson/lib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $students = [];
        foreach (['Duedate', 'Reminder', 'Extension', 'Activity', 'Plugin', 'Group', 'Lesson'] as $name) {
            $names = ['firstname' => $name, 'lastname' => 'Student'];
            $students[$name] = $this->getDataGenerator()->create_and_enrol($course, 'student', $names);
        }
        $duedate = time() - 3 * DAYSECS;
        $expected = [];

        // Assignment with a due date: due date, extension, activity override, Late Penalty overrides.
        $assign = $this->create_assign_activity($course, ['duedate' => $duedate, 'name' => 'Essay']);
        $this->enable_rule($assign->cmid);
        $assigngen = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $assigngen->create_extension(['cmid' => $assign->cmid, 'userid' => $students['Extension']->id,
            'extensionduedate' => $duedate + DAYSECS]);
        $assigngen->create_override(['assignid' => $assign->id, 'userid' => $students['Activity']->id,
            'duedate' => $duedate + HOURSECS]);
        $this->lp_generator()->create_override(['cmid' => $assign->cmid, 'userid' => $students['Plugin']->id,
            'deadline' => $duedate - DAYSECS]);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $students['Group']->id]);
        $this->lp_generator()->create_group_override(['cmid' => $assign->cmid, 'groupid' => $group->id,
            'deadline' => $duedate - HOURSECS]);
        foreach (['Duedate', 'Extension', 'Activity', 'Plugin', 'Group'] as $name) {
            $this->submit_assign($assign, $students[$name]);
            $this->grade_assign($assign, $students[$name], $teacher, 100);
        }
        $expected['Duedate'] = ['Essay', $duedate, deadline_resolver::ORIGIN_DUEDATE];
        $expected['Extension'] = ['Essay', $duedate + DAYSECS, deadline_resolver::ORIGIN_EXTENSION];
        $expected['Activity'] = ['Essay', $duedate + HOURSECS, deadline_resolver::ORIGIN_ACTIVITY_OVERRIDE];
        $expected['Plugin'] = ['Essay', $duedate - DAYSECS, deadline_resolver::ORIGIN_PLUGIN_USER];
        $expected['Group'] = ['Essay', $duedate - HOURSECS, deadline_resolver::ORIGIN_PLUGIN_GROUP];

        // Assignment without a due date: the reminder.
        $noduedate = $this->create_assign_activity($course, ['duedate' => 0, 'name' => 'Draft']);
        $this->set_reminder($noduedate->cmid, $duedate);
        $this->enable_rule($noduedate->cmid);
        $this->submit_assign($noduedate, $students['Reminder']);
        $this->grade_assign($noduedate, $students['Reminder'], $teacher, 100);
        $expected['Reminder'] = ['Draft', $duedate, deadline_resolver::ORIGIN_REMINDER];

        // Lesson with a closing date and a reminder: the reminder, never the closing date.
        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id, 'grade' => 100,
            'name' => 'Reading', 'deadline' => $duedate + 5 * DAYSECS]);
        $this->set_reminder($lesson->cmid, $duedate);
        $this->enable_rule($lesson->cmid);
        $record = $DB->get_record('lesson', ['id' => $lesson->id]);
        $record->cmidnumber = $lesson->cmid;
        // Mirrors the end of lesson in mod/lesson/locallib.php.
        $DB->insert_record('lesson_grades', (object) ['lessonid' => $lesson->id, 'userid' => $students['Lesson']->id,
            'grade' => 100, 'completed' => time()]);
        lesson_update_grades($record, $students['Lesson']->id);
        $expected['Lesson'] = ['Reading', $duedate, deadline_resolver::ORIGIN_REMINDER];

        $report = $this->report($course);
        $rows = $this->rows($report);
        [$columns, $export] = $report->get_export_data();
        $deadlinecolumn = array_search(get_string('report_col_deadline', 'local_latepenalty'), $columns, true);
        $origincolumn = array_search(get_string('report_col_deadline_origin', 'local_latepenalty'), $columns, true);
        $exportbykey = [];
        foreach ($export as $line) {
            $exportbykey[$line[0] . '|' . $line[1]] = $line;
        }

        foreach ($expected as $name => [$activity, $time, $origin]) {
            $key = "$name Student|$activity";
            $this->assertArrayHasKey($key, $rows);
            $label = get_string('deadline_origin_' . $origin, 'local_latepenalty');
            $this->assertSame(userdate($time), $rows[$key]['deadline'], $key);
            $this->assertSame($label, $rows[$key]['deadlineorigin'], $key);
            $this->assertSame(userdate($time), $exportbykey[$key][$deadlinecolumn], $key);
            $this->assertSame($label, $exportbykey[$key][$origincolumn], $key);
        }
    }

    /**
     * A student exempted by an override shows "no due date" with its origin.
     *
     * @return void
     */
    public function test_exempt_student(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $student, $teacher] = $this->create_course_with_users();
        $assign = $this->create_assign_activity($course, ['duedate' => time() - 3 * DAYSECS]);
        $this->enable_rule($assign->cmid);
        $this->submit_assign($assign, $student);
        $this->grade_assign($assign, $student, $teacher, 100);
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_override([
            'assignid' => $assign->id,
            'userid' => $student->id,
            'duedate' => 0,
        ]);

        $row = array_values($this->rows($this->report($course)))[0];

        $this->assertFalse($row['hasdeadline']);
        $override = get_string('deadline_origin_activity_override', 'local_latepenalty');
        $this->assertSame(get_string('deadline_origin_exempt', 'local_latepenalty', $override), $row['deadlineorigin']);
    }

    /**
     * The student and activity filters apply to the screen and to the export alike.
     *
     * @return void
     */
    public function test_filters(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $student, $teacher] = $this->create_course_with_users();
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assigns = [];
        foreach (['First', 'Second'] as $name) {
            $assign = $this->create_assign_activity($course, ['name' => $name, 'duedate' => time() - 2 * DAYSECS]);
            $this->enable_rule($assign->cmid);
            foreach ([$student, $other] as $user) {
                $this->submit_assign($assign, $user);
                $this->grade_assign($assign, $user, $teacher, 100);
            }
            $assigns[] = $assign;
        }
        $context = \context_course::instance($course->id);
        $count = function (controller $report): array {
            return [count($report->get_template_context()['penalties']), count($report->get_export_data()[1])];
        };

        $this->assertSame([4, 4], $count(new controller((int) $course->id, $context)));
        $this->assertSame([2, 2], $count(new controller((int) $course->id, $context, (int) $other->id)));
        $this->assertSame([2, 2], $count(new controller((int) $course->id, $context, 0, (int) $assigns[1]->cmid)));
        $report = new controller((int) $course->id, $context, (int) $other->id, (int) $assigns[1]->cmid);
        $this->assertSame([1, 1], $count($report));
        $this->assertSame(['Second'], array_column($report->get_template_context()['penalties'], 'activity'));
    }

    /**
     * The report costs the same queries with 5 and with 50 penalised students (F8-04).
     *
     * @return void
     */
    public function test_queries_do_not_grow(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $reads = [];
        foreach ([5, 50] as $size) {
            $course = $this->getDataGenerator()->create_course();
            $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
            $assign = $this->create_assign_activity($course, ['duedate' => time() - 3 * DAYSECS]);
            $this->enable_rule($assign->cmid);
            for ($i = 0; $i < $size; $i++) {
                $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
                $this->submit_assign($assign, $student);
                $this->grade_assign($assign, $student, $teacher, 100);
            }
            $report = $this->report($course);
            $report->get_template_context();

            $before = $DB->perf_get_reads();
            $context = $report->get_template_context();
            $reads[$size] = $DB->perf_get_reads() - $before;
            $this->assertCount($size, $context['penalties']);
        }

        $this->assertSame($reads[5], $reads[50]);
    }
}
