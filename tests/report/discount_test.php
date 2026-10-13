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

use local_latepenalty\local\penalty_writer;
use local_latepenalty\penalty_helper;
use local_latepenalty\recalculator;
use local_latepenalty\tests\latepenalty_testcase;

/**
 * Report discount and points discounted, on both penalty bases (F20, P13).
 *
 * Grades come from real assignments graded two days late (10% a day, 50% cap):
 * 20% of the grade obtained or of the maximum grade.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\report\controller
 * @covers \local_latepenalty\penalty_helper
 */
final class discount_test extends latepenalty_testcase {
    /**
     * Assignment graded two days late, with a rule on the given base.
     *
     * @param \stdClass $course Course.
     * @param string $name Activity name.
     * @param int $base Penalty base.
     * @param array $params Extra assignment settings.
     * @return \stdClass Assignment record.
     */
    private function late_assign(\stdClass $course, string $name, int $base, array $params = []): \stdClass {
        $assign = $this->create_assign_activity($course, array_merge([
            'name' => $name,
            'duedate' => time() - 2 * DAYSECS + HOURSECS,
        ], $params));
        $this->enable_rule($assign->cmid, 10.0, 50.0, $base);
        return $assign;
    }

    /**
     * Submit and grade a student.
     *
     * @param \stdClass $assign Assignment record.
     * @param \stdClass $student Student.
     * @param \stdClass $teacher Teacher.
     * @param float $grade Grade.
     * @return void
     */
    private function hand_in(\stdClass $assign, \stdClass $student, \stdClass $teacher, float $grade): void {
        $this->submit_assign($assign, $student);
        $this->grade_assign($assign, $student, $teacher, $grade);
    }

    /**
     * Report rows as the admin sees them, keyed by "activity / student".
     *
     * @param \stdClass $course Course.
     * @return array Template rows.
     */
    private function rows(\stdClass $course): array {
        $context = \context_course::instance($course->id);
        $scope = controller::resolve_group_restriction($course, $context);
        $controller = new controller((int) $course->id, $context, 0, 0, $scope['groupids'], $scope['restrictedcmids']);
        $rows = [];
        foreach ($controller->get_template_context()['penalties'] as $row) {
            $rows[$row['activity'] . ' / ' . $row['fullname']] = $row;
        }
        return $rows;
    }

    /**
     * Discount and points of a report row.
     *
     * @param array $row Template row.
     * @return array [discount, points]
     */
    private function figures(array $row): array {
        return [$row['discount'] ?? null, $row['points'] ?? null];
    }

    /**
     * Both bases show 20% and the points each one discounted; a grade stopped at 0 shows what it lost (F20-25, F20-26, F20-31).
     *
     * @return void
     */
    public function test_discount_and_points(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $student, $teacher] = $this->create_course_with_users();
        $low = $this->getDataGenerator()->create_and_enrol($course, 'student', ['firstname' => 'Low', 'lastname' => 'Grade']);
        $obtained = $this->late_assign($course, 'Obtained', penalty_helper::BASE_GRADE);
        $maximum = $this->late_assign($course, 'Maximum', penalty_helper::BASE_MAX);
        $this->hand_in($obtained, $student, $teacher, 60);
        $this->hand_in($maximum, $student, $teacher, 60);
        $this->hand_in($maximum, $low, $teacher, 15);

        $rows = $this->rows($course);
        $name = fullname($student);

        $this->assertSame([format_float(20.0, 1), format_float(12.0, 2)], $this->figures($rows["Obtained / $name"]));
        $this->assertSame([format_float(20.0, 1), format_float(20.0, 2)], $this->figures($rows["Maximum / $name"]));
        $this->assertSame([format_float(15.0, 1), format_float(15.0, 2)], $this->figures($rows['Maximum / ' . fullname($low)]));
    }

    /**
     * The export carries the same figures, with its own points column (F20-27).
     *
     * @return void
     */
    public function test_export(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $student, $teacher] = $this->create_course_with_users();
        $maximum = $this->late_assign($course, 'Maximum', penalty_helper::BASE_MAX);
        $this->hand_in($maximum, $student, $teacher, 60);

        $context = \context_course::instance($course->id);
        $scope = controller::resolve_group_restriction($course, $context);
        [$headers, $rows] = (new controller((int) $course->id, $context, 0, 0, $scope['groupids'], $scope['restrictedcmids']))
            ->get_export_data();

        $discount = array_search(get_string('report_col_discount', 'local_latepenalty'), $headers);
        $points = array_search(get_string('report_col_points', 'local_latepenalty'), $headers);
        $this->assertNotFalse($points);
        $this->assertEqualsWithDelta(20.0, (float) $rows[0][$discount], 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $rows[0][$points], 0.001);
    }

    /**
     * After switching the base without recalculating, the points stay exact; the percentage follows the new base (F20-32).
     *
     * @return void
     */
    public function test_base_switched_without_recalculation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $student, $teacher] = $this->create_course_with_users();
        $assign = $this->late_assign($course, 'Switched', penalty_helper::BASE_GRADE);
        $this->hand_in($assign, $student, $teacher, 60);

        $this->save_activity_settings($assign->cmid, [
            'latepenalty_base' => penalty_helper::BASE_MAX,
            'latepenalty_recalc_rate' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);

        $this->assertSame(48.0, $this->final_grade('assign', $assign->id, $student->id));
        $row = $this->rows($course)['Switched / ' . fullname($student)];
        $this->assertSame([format_float(12.0, 1), format_float(12.0, 2)], $this->figures($row));
    }

    /**
     * Item multiplier and offset: the figures are the rule's, on the raw grade (F20-33).
     *
     * Maximum 25, raw 20, × 0.8 + 2: 20% of 20 is 4 points, 16 raw, 14.8 in the gradebook.
     *
     * @return void
     */
    public function test_item_factors(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $student, $teacher] = $this->create_course_with_users();
        $assign = $this->late_assign($course, 'Factors', penalty_helper::BASE_GRADE, ['grade' => 25]);
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id,
            'itemnumber' => 0, 'courseid' => $course->id]);
        $item->multfactor = 0.8;
        $item->plusfactor = 2;
        $item->update();
        $this->hand_in($assign, $student, $teacher, 20);
        $this->assertSame(14.8, $this->final_grade('assign', $assign->id, $student->id));

        $row = $this->rows($course)['Factors / ' . fullname($student)];
        $this->assertSame([format_float(20.0, 1), format_float(4.0, 2)], $this->figures($row));
    }

    /**
     * Item maximum changed in the gradebook, quiz: the figures compare grades on the same scale (F20-34).
     *
     * 60 of 100 reached the gradebook; the item now goes to 50, which the core maps
     * proportionally. A recalculation at 20% a day discounts 40%: 36 raw, 18 of 50.
     * Comparing the final grade (scale 50) with the raw grade (scale 100) read 70%.
     *
     * Deducted mark path only: on the overridden path the core stamps the item's new
     * maximum on the grade when the plugin writes it, and the raw grade no longer says
     * which scale it was sent on.
     *
     * @return void
     */
    public function test_item_maximum_changed_quiz(): void {
        if (!penalty_writer::core_supports_deducted_mark()) {
            $this->markTestSkipped('This core has no MDL-88407 fix.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();
        penalty_writer::force_path_for_tests(true);
        $deadline = time() - 5 * DAYSECS;
        [$course, $student] = $this->create_course_with_users();
        $quiz = $this->create_quiz_with_questions($course, 10, ['name' => 'Quiz']);
        $this->set_reminder($quiz->cmid, $deadline);
        $this->enable_rule($quiz->cmid);
        $this->attempt_quiz($quiz, $student, 6, $deadline + 2 * DAYSECS - 60);
        $this->change_item_maximum('quiz', $quiz, 50);

        recalculator::recalculate_for_student($quiz->cmid, $student->id, 20.0, 50.0);

        $this->assertSame(18.0, $this->final_grade('quiz', $quiz->id, $student->id));
        $row = $this->rows($course)['Quiz / ' . fullname($student)];
        $this->assertSame([format_float(40.0, 1), format_float(24.0, 2)], $this->figures($row));
    }

    /**
     * Item maximum changed in the gradebook, assignment: the core only caps grades the module rescales itself (F20-34).
     *
     * The same recalculation gives 36, on both storage paths.
     *
     * @return void
     */
    public function test_item_maximum_changed_assignment(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $student, $teacher] = $this->create_course_with_users();
        $assign = $this->late_assign($course, 'Assignment', penalty_helper::BASE_GRADE);
        $this->hand_in($assign, $student, $teacher, 60);
        $this->change_item_maximum('assign', $assign, 50);

        recalculator::recalculate_for_student($assign->cmid, $student->id, 20.0, 50.0);

        $this->assertSame(36.0, $this->final_grade('assign', $assign->id, $student->id));
        $row = $this->rows($course)['Assignment / ' . fullname($student)];
        $this->assertSame([format_float(40.0, 1), format_float(24.0, 2)], $this->figures($row));
    }

    /**
     * No division by zero: an item without range and a base of 0 give no figures (F20-37).
     *
     * @return void
     */
    public function test_figures_without_range_or_base(): void {
        $item = (object) ['itemmodule' => 'lti', 'grademin' => 0, 'grademax' => 0, 'multfactor' => 1, 'plusfactor' => 0];
        $this->assertSame([0.0, 0.0], penalty_helper::discount_figures($item, 0, 0, 0, 0, penalty_helper::BASE_MAX));

        $item->grademax = 100;
        $this->assertSame([0.0, 0.0], penalty_helper::discount_figures($item, 0, 0, 0, 0, penalty_helper::BASE_MAX));
        $this->assertSame([0.0, 0.0], penalty_helper::discount_figures($item, 0, 0, 0, 100, penalty_helper::BASE_GRADE));
        $this->assertSame([20.0, 20.0], penalty_helper::discount_figures($item, 60, 40, 0, 100, penalty_helper::BASE_MAX));
    }

    /**
     * Change the maximum of a module's grade item in the gradebook.
     *
     * @param string $modname Module name.
     * @param \stdClass $module Module record.
     * @param float $grademax New maximum.
     * @return void
     */
    private function change_item_maximum(string $modname, \stdClass $module, float $grademax): void {
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => $modname, 'iteminstance' => $module->id,
            'itemnumber' => 0, 'courseid' => $module->course]);
        $item->grademax = $grademax;
        $item->update();
    }
}
