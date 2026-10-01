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
 * Activity form section and what saving it does (F9, F16, F17, F18).
 *
 * Settings are saved through update_module(), which runs the plugin's
 * coursemodule_edit_post_actions callback exactly as the edit form does.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::local_latepenalty_coursemodule_standard_elements
 * @covers ::local_latepenalty_coursemodule_edit_post_actions
 * @covers ::local_latepenalty_graded_without_numbers
 * @covers \local_latepenalty\recalculator
 */
final class lib_test extends latepenalty_testcase {
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
     * Assignment graded 100 two days after its due date, rule 10%/day saved through the form.
     *
     * @return array [assign, student, teacher, course, duedate]
     */
    private function late_assign(): array {
        [$course, $student, $teacher] = $this->create_course_with_users();
        $duedate = time() - 2 * DAYSECS + HOURSECS;
        $assign = $this->create_assign_activity($course, ['duedate' => $duedate]);
        $this->save($assign->cmid, [
            'latepenalty_enabled' => 1,
            'latepenalty_daily' => 10,
            'latepenalty_max' => 50,
            'latepenalty_recalc_deadline' => 1,
            'latepenalty_recalc_rate' => 1,
        ]);
        $this->submit_assign($assign, $student);
        $this->grade_assign($assign, $student, $teacher, 100);
        $this->assertSame(80.0, $this->final_grade('assign', $assign->id, $student->id));
        return [$assign, $student, $teacher, $course, $duedate];
    }

    /**
     * Save assignment settings as its form does, which always posts the submission plugin settings too.
     *
     * @param int $cmid Course module ID.
     * @param array $changes Fields to change.
     * @return void
     */
    private function save(int $cmid, array $changes): void {
        $this->save_activity_settings($cmid, $changes + ['assignsubmission_onlinetext_enabled' => 1]);
    }

    /**
     * The activity edit form of a course module, built the way course/modedit.php builds it.
     *
     * @param int $cmid Course module ID.
     * @return \MoodleQuickForm
     */
    private function activity_form(int $cmid): \MoodleQuickForm {
        global $CFG, $COURSE;
        require_once($CFG->dirroot . '/course/modlib.php');

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        // The form reads the global course, which modedit.php sets through require_login().
        $COURSE = $course;
        [$cm, , , $data, $cw] = get_moduleinfo_data($cm, $course);
        require_once($CFG->dirroot . '/mod/' . $cm->modname . '/mod_form.php');
        $class = 'mod_' . $cm->modname . '_mod_form';
        $form = new $class($data, $cw->section, $cm, $course);
        return (new \ReflectionProperty(\moodleform::class, '_form'))->getValue($form);
    }

    /**
     * The Late Penalty section the plugin adds to an activity form, on its own.
     *
     * Runs local_latepenalty_coursemodule_standard_elements() on an empty form
     * with the wrapper data the activity form passes to it.
     *
     * @param int $cmid Course module ID.
     * @param string $modname Module name.
     * @param bool $withanchor Whether the form has the Tags header the section is moved before.
     * @return \MoodleQuickForm
     */
    private function plugin_section(int $cmid, string $modname, bool $withanchor = false): \MoodleQuickForm {
        global $CFG;
        require_once($CFG->libdir . '/formslib.php');

        $mform = new \MoodleQuickForm('latepenaltytest', 'post', '');
        if ($withanchor) {
            // Activity forms always have the Tags section, which the plugin moves its section before.
            $mform->addElement('header', 'tagshdr', 'Tags');
        }
        $wrapper = new class ($cmid, $modname) {
            /**
             * Constructor.
             *
             * @param int $cmid Course module ID.
             * @param string $modname Module name.
             */
            public function __construct(
                /** @var int Course module ID. */
                private int $cmid,
                /** @var string Module name. */
                private string $modname
            ) {
            }

            /**
             * The current activity data, as moodleform_mod::get_current() returns it.
             *
             * @return \stdClass
             */
            public function get_current(): \stdClass {
                return (object) ['modulename' => $this->modname, 'coursemodule' => $this->cmid];
            }
        };
        local_latepenalty_coursemodule_standard_elements($wrapper, $mform);
        return $mform;
    }

    /**
     * Removing the activity deadline gives the grades back, with the deadline box ticked (F16).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_removing_deadline_restores(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);

        // Ticked: back to 100.
        [$assign, $student] = $this->late_assign();
        $this->save($assign->cmid, ['duedate' => 0]);
        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id));
        $this->assertEmpty($this->grade_row('assign', $assign->id, $student->id)->overridden);

        // Unticked: nothing changes.
        [$assign, $student] = $this->late_assign();
        $this->save($assign->cmid, ['duedate' => 0, 'latepenalty_recalc_deadline' => 0]);
        $this->assertSame(80.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * Removing the deadline: a student with an override of their own follows it; a teacher edit stays (F16).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_removing_deadline_keeps_own_deadlines_and_teacher_edits(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);

        [$assign, $student, $teacher, $course, $duedate] = $this->late_assign();
        $this->lp_generator()->create_override([
            'cmid' => $assign->cmid,
            'userid' => $student->id,
            'deadline' => $duedate - DAYSECS,
        ]);
        $edited = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->submit_assign($assign, $edited);
        $this->grade_assign($assign, $edited, $teacher, 100);
        \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id,
            'courseid' => $course->id])->update_final_grade($edited->id, 95.0, 'gradebook');

        $this->save($assign->cmid, ['duedate' => 0]);

        // Three days after the student's own deadline: 30%.
        $this->assertSame(70.0, $this->final_grade('assign', $assign->id, $student->id));
        $this->assertSame(95.0, $this->final_grade('assign', $assign->id, $edited->id));
    }

    /**
     * Disabling gives the grades back; enabling again applies the current rule (F17, P2).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_disable_and_enable_again(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$assign, $student, , , $duedate] = $this->late_assign();

        $this->save($assign->cmid, ['latepenalty_enabled' => 0]);
        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id));
        $this->assertEmpty($this->grade_row('assign', $assign->id, $student->id)->overridden);

        // While disabled the due date moves one day earlier; enabling uses it.
        $this->save($assign->cmid, ['duedate' => $duedate - DAYSECS]);
        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id));
        $this->save($assign->cmid, ['latepenalty_enabled' => 1]);
        $this->assertSame(70.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * Disabling leaves teacher edits alone (F17).
     *
     * @dataProvider storage_paths
     * @param bool $deductedmark Storage path.
     * @return void
     */
    public function test_disable_keeps_teacher_edit(bool $deductedmark): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->use_path($deductedmark);
        [$assign, $student, , $course] = $this->late_assign();
        \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id,
            'courseid' => $course->id])->update_final_grade($student->id, 95.0, 'gradebook');

        $this->save($assign->cmid, ['latepenalty_enabled' => 0]);

        $this->assertSame(95.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * Enabling the rule for the first time leaves the grades already given as they are (F17, as in v1.1.2).
     *
     * @return void
     */
    public function test_first_enabling_keeps_existing_grades(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $student, $teacher] = $this->create_course_with_users();
        $assign = $this->create_assign_activity($course, ['duedate' => time() - 2 * DAYSECS + HOURSECS]);
        $this->submit_assign($assign, $student);
        $this->grade_assign($assign, $student, $teacher, 100);
        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id));

        $this->save($assign->cmid, [
            'latepenalty_enabled' => 1,
            'latepenalty_daily' => 10,
            'latepenalty_max' => 50,
            'latepenalty_recalc_deadline' => 1,
            'latepenalty_recalc_rate' => 1,
        ]);
        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id));

        // A grade arriving afterwards is penalised.
        $this->grade_assign($assign, $student, $teacher, 90);
        $this->assertSame(72.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * Form: the disable warning appears only on an active rule, hidden while the box is ticked (F17).
     *
     * @return void
     */
    public function test_form_disable_warning(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course] = $this->create_course_with_users();
        $assign = $this->create_assign_activity($course, ['duedate' => time()]);

        $this->assertFalse($this->plugin_section($assign->cmid, 'assign')->elementExists('latepenalty_disablewarning'));
        $this->enable_rule($assign->cmid);
        $this->assertTrue($this->plugin_section($assign->cmid, 'assign')->elementExists('latepenalty_disablewarning'));
    }

    /**
     * Form: scale-graded activities show the "numeric grades only" notice (F18).
     *
     * @return void
     */
    public function test_form_scale_warning(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course] = $this->create_course_with_users();
        $scale = $this->getDataGenerator()->create_scale(['courseid' => $course->id]);

        $points = $this->create_assign_activity($course);
        $this->assertFalse($this->plugin_section($points->cmid, 'assign')->elementExists('latepenalty_scalewarning'));
        $this->assertFalse(local_latepenalty_graded_without_numbers($points->cmid));

        $scaled = $this->create_assign_activity($course, ['grade' => -$scale->id]);
        $this->assertTrue($this->plugin_section($scaled->cmid, 'assign')->elementExists('latepenalty_scalewarning'));
        $this->assertTrue(local_latepenalty_graded_without_numbers($scaled->cmid));
    }

    /**
     * Native assignment penalty: form section disabled with a warning; saving keeps the rule and old grades (F9).
     *
     * @return void
     */
    public function test_native_penalty_form_and_save(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        if (!class_exists(\mod_assign\penalty\helper::class)) {
            $this->markTestSkipped('The core assignment penalty exists only from Moodle 5.0.');
        }
        [$assign, $student] = $this->late_assign();

        \core_grades\penalty_manager::enable_module('assign');
        $this->save($assign->cmid, ['gradepenalty' => 1]);

        $form = $this->activity_form($assign->cmid);
        $this->assertTrue($form->elementExists('latepenalty_nativewarning'));
        $rule = $this->get_rule($assign->cmid);
        $this->assertSame(1, (int) $rule->enabled);
        $this->assertSame(80.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * The stored rule of a course module.
     *
     * @param int $cmid Course module ID.
     * @return \stdClass
     */
    private function get_rule(int $cmid): \stdClass {
        global $DB;
        return $DB->get_record('local_latepenalty_rules', ['cmid' => $cmid], '*', MUST_EXIST);
    }

    /**
     * Help buttons on the switch and both recalculation boxes (F11-01).
     *
     * @return void
     */
    public function test_help_buttons(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course] = $this->create_course_with_users();
        $assign = $this->create_assign_activity($course);

        $form = $this->plugin_section($assign->cmid, 'assign');
        foreach (['latepenalty_enabled', 'latepenalty_recalc_deadline', 'latepenalty_recalc_rate'] as $name) {
            $this->assertNotEmpty($form->getElement($name)->_helpbutton, $name);
        }
    }

    /**
     * "Deadline used" line: due date, reminder, none; absent when creating (F11-02, F11-03).
     *
     * @return void
     */
    public function test_deadline_used_line(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course] = $this->create_course_with_users();
        $time = time() + DAYSECS;

        $withdue = $this->create_assign_activity($course, ['duedate' => $time]);
        $this->set_reminder($withdue->cmid, $time + DAYSECS);
        $line = $this->plugin_section($withdue->cmid, 'assign')->getElement('latepenalty_deadlineused')->toHtml();
        $this->assertSame(get_string('deadline_used', 'local_latepenalty', (object) [
            'date' => penalty_helper::format_deadline($time),
            'origin' => get_string('deadline_origin_duedate', 'local_latepenalty'),
        ]), $line);

        $reminder = $this->create_assign_activity($course, ['duedate' => 0]);
        $this->set_reminder($reminder->cmid, $time);
        $line = $this->plugin_section($reminder->cmid, 'assign')->getElement('latepenalty_deadlineused')->toHtml();
        $this->assertStringContainsString(get_string('deadline_origin_reminder', 'local_latepenalty'), $line);

        $none = $this->create_assign_activity($course, ['duedate' => 0]);
        $line = $this->plugin_section($none->cmid, 'assign')->getElement('latepenalty_deadlineused')->toHtml();
        $this->assertSame(get_string('deadline_none', 'local_latepenalty'), $line);

        $this->assertFalse($this->plugin_section(0, 'assign')->elementExists('latepenalty_deadlineused'));
    }

    /**
     * Scale grades or the core penalty replace the line with their warning (F11-04).
     *
     * @return void
     */
    public function test_deadline_line_replaced_by_warnings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course] = $this->create_course_with_users();
        $scale = $this->getDataGenerator()->create_scale(['courseid' => $course->id]);

        $scaled = $this->create_assign_activity($course, ['duedate' => time(), 'grade' => -$scale->id]);
        $this->assertFalse($this->plugin_section($scaled->cmid, 'assign')->elementExists('latepenalty_deadlineused'));

        if (class_exists(\mod_assign\penalty\helper::class)) {
            \core_grades\penalty_manager::enable_module('assign');
            $native = $this->create_assign_activity($course, ['duedate' => time(), 'gradepenalty' => 1]);
            $this->assertFalse($this->plugin_section($native->cmid, 'assign')->elementExists('latepenalty_deadlineused'));
        }
    }

    /**
     * The section is moved before the Tags header intact, in its own order.
     *
     * @return void
     */
    public function test_section_moved_before_tags(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course] = $this->create_course_with_users();
        $assign = $this->create_assign_activity($course, ['duedate' => time()]);
        $this->enable_rule($assign->cmid);

        $form = $this->plugin_section($assign->cmid, 'assign', true);

        $names = array_map(fn($element) => $element->getName(), $form->_elements);
        $this->assertSame([
            'latepenaltyheader',
            'latepenalty_enabled',
            'latepenalty_daily',
            'latepenalty_max',
            'latepenalty_recalc_deadline',
            'latepenalty_recalc_rate',
            'latepenalty_disablewarning',
            'latepenalty_deadlineused',
            'tagshdr',
        ], $names);
        $this->assertStringContainsString(get_string('latepenalty', 'local_latepenalty'), $form->toHtml());
    }
}
