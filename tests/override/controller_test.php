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
 * PHPUnit tests for the override controller.
 *
 * Covers:
 *  - render(): notification when no overrides exist (list mode)
 *  - render(): table rows contain student name, penalty values, and
 *              "inherit" placeholder for null fields (list mode)
 *  - render(): add button always present (list mode)
 *  - render(): "no students available" notice when all enrolled students
 *              already have an override (add mode)
 *  - process(): delete removes the correct record on confirm + POST
 *  - process(): delete leaves the record intact when confirm = false
 *  - process(): delete does not remove an override belonging to a different CM
 *
 * @package    local_latepenalty
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty\override;

use advanced_testcase;
use context_module;
use context_system;
use moodle_url;
use stdClass;

/**
 * Tests for local_latepenalty\override\controller.
 *
 * @covers \local_latepenalty\override\controller
 */
final class controller_test extends advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $PAGE;

        parent::setUp();
        $this->resetAfterTest();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];

        $PAGE->set_context(context_system::instance());
    }

    // Helpers.

    /**
     * Create a course with one enrolled student and an assign with an active penalty rule.
     *
     * @return array{course: stdClass, student: stdClass, cm: stdClass,
     *               ctx: context_module, rule: stdClass}
     */
    private function make_scenario(): array {
        global $DB;

        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'grade'  => 100,
        ]);

        $cm  = get_coursemodule_from_id('', $assign->cmid, 0, false, MUST_EXIST);
        $ctx = context_module::instance($assign->cmid);

        $existing = $DB->get_record('local_latepenalty_rules', ['cmid' => $assign->cmid]);
        if ($existing) {
            $existing->enabled       = 1;
            $existing->daily_penalty = 10.0;
            $existing->max_penalty   = 50.0;
            $DB->update_record('local_latepenalty_rules', $existing);
            $rule = $DB->get_record('local_latepenalty_rules', ['cmid' => $assign->cmid]);
        } else {
            $ruleid = $DB->insert_record('local_latepenalty_rules', (object) [
                'cmid'               => $assign->cmid,
                'enabled'            => 1,
                'daily_penalty'      => 10.0,
                'max_penalty'        => 50.0,
                'recalc_on_deadline' => 1,
                'recalc_on_rate'     => 1,
                'last_deadline'      => 0,
            ]);
            $rule = $DB->get_record('local_latepenalty_rules', ['id' => $ruleid]);
        }

        return [
            'course'  => $course,
            'student' => $student,
            'cm'      => $cm,
            'ctx'     => $ctx,
            'rule'    => $rule,
        ];
    }

    /**
     * Insert an override record directly into the DB.
     *
     * @param int        $cmid
     * @param int        $userid
     * @param int|null   $deadline
     * @param float|null $daily
     * @param float|null $max
     * @return stdClass
     */
    private function insert_override(
        int $cmid,
        int $userid,
        ?int $deadline,
        ?float $daily,
        ?float $max
    ): stdClass {
        global $DB;

        $id = $DB->insert_record('local_latepenalty_overrides', (object) [
            'cmid'          => $cmid,
            'userid'        => $userid,
            'deadline'      => $deadline,
            'daily_penalty' => $daily,
            'max_penalty'   => $max,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);
        return $DB->get_record('local_latepenalty_overrides', ['id' => $id]);
    }

    /**
     * Build a controller instance for the given scenario and action.
     *
     * @param array  $s          Scenario array from make_scenario().
     * @param string $action
     * @param int    $overrideid
     * @param bool   $confirm
     * @return controller
     */
    private function make_controller(
        array $s,
        string $action,
        int $overrideid = 0,
        bool $confirm = false
    ): controller {
        return new controller(
            (int) $s['cm']->id,
            $s['course'],
            $s['cm'],
            $s['ctx'],
            $s['rule'],
            $action,
            $overrideid,
            $confirm
        );
    }

    /**
     * Invoke the private save_override() method with controlled form data.
     *
     * @param controller $ctrl Controller instance.
     * @param stdClass $formdata Form data to save.
     * @return void
     */
    private function invoke_save_override(controller $ctrl, stdClass $formdata): void {
        $method = new \ReflectionMethod($ctrl, 'save_override');
        $method->setAccessible(true);
        $method->invoke($ctrl, $formdata);
    }

    // Tests: render() in list mode.

    /**
     * When no overrides exist, render() returns the empty-state notification.
     */
    public function test_render_list_shows_notification_when_empty(): void {
        global $PAGE;

        $this->setAdminUser();
        $s    = $this->make_scenario();
        $ctrl = $this->make_controller($s, 'list');
        $ctrl->process();

        $html = $ctrl->render($PAGE->get_renderer('core'));

        self::assertStringContainsString(
            get_string('override_empty', 'local_latepenalty'),
            $html
        );
    }

    /**
     * When overrides exist, render() includes the student name and penalty values.
     * Null penalty fields are shown as the "inherit" placeholder string.
     */
    public function test_render_list_shows_student_name_and_penalties(): void {
        global $PAGE;

        $this->setAdminUser();
        $s = $this->make_scenario();
        $this->insert_override((int) $s['cm']->id, (int) $s['student']->id, null, 5.5, null);

        $ctrl = $this->make_controller($s, 'list');
        $ctrl->process();
        $html = $ctrl->render($PAGE->get_renderer('core'));

        self::assertStringContainsString(fullname($s['student']), $html);
        self::assertStringContainsString('5.5', $html);
        self::assertStringContainsString(
            get_string('override_inherit', 'local_latepenalty'),
            $html
        );
    }

    /**
     * render() always includes an "add override" button whether the list is empty or not.
     */
    public function test_render_list_always_includes_add_button(): void {
        global $PAGE;

        $this->setAdminUser();
        $s    = $this->make_scenario();
        $ctrl = $this->make_controller($s, 'list');
        $ctrl->process();

        $html = $ctrl->render($PAGE->get_renderer('core'));

        self::assertStringContainsString(get_string('override_add', 'local_latepenalty'), $html);
    }

    // Tests: render() in add mode.

    /**
     * When every enrolled student already has an override, render() shows the
     * "no students available" notice instead of the add form.
     */
    public function test_render_add_shows_no_students_when_all_enrolled_covered(): void {
        global $OUTPUT;

        $this->setAdminUser();

        $s = $this->make_scenario();
        $this->insert_override((int) $s['cm']->id, (int) $s['student']->id, null, null, null);

        $ctrl = $this->make_controller($s, 'add');
        $ctrl->process();
        $html = $ctrl->render($OUTPUT);

        self::assertStringContainsString(
            get_string('override_no_students', 'local_latepenalty'),
            $html
        );
    }

    /**
     * save_override() rejects an add request for a user who is not enrolled in the course.
     */
    public function test_save_add_rejects_unenrolled_user(): void {
        global $DB;

        $this->setAdminUser();

        $s        = $this->make_scenario();
        $intruder = $this->getDataGenerator()->create_user();
        $ctrl     = $this->make_controller($s, 'add');

        $caught = null;
        try {
            $this->invoke_save_override($ctrl, (object) ['userid' => $intruder->id]);
        } catch (\moodle_exception $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'moodle_exception must be thrown for an unenrolled user.');
        self::assertSame('invaliduser', $caught->errorcode);
        self::assertFalse(
            $DB->record_exists('local_latepenalty_overrides', ['cmid' => $s['cm']->id, 'userid' => $intruder->id]),
            'No override record must be created for an unenrolled user.'
        );
    }

    /**
     * save_override() keeps the original user when an edit POST tampers with the hidden userid.
     */
    public function test_save_edit_preserves_original_userid(): void {
        global $DB;

        $this->setAdminUser();

        $s = $this->make_scenario();
        $otherstudent = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($otherstudent->id, $s['course']->id);
        $override = $this->insert_override((int) $s['cm']->id, (int) $s['student']->id, null, 5.0, 30.0);

        $ctrl = $this->make_controller($s, 'edit', (int) $override->id);
        $this->invoke_save_override($ctrl, (object) [
            'userid' => $otherstudent->id,
        ]);

        $saved = $DB->get_record('local_latepenalty_overrides', ['id' => $override->id], '*', MUST_EXIST);

        self::assertSame((int) $s['student']->id, (int) $saved->userid);
        self::assertFalse(
            $DB->record_exists(
                'local_latepenalty_overrides',
                ['cmid' => $s['cm']->id, 'userid' => $otherstudent->id]
            )
        );
    }

    // Tests: process() in delete mode.

    /**
     * process() removes the override when confirm = true and a valid POST + sesskey are present.
     */
    public function test_process_delete_removes_record_on_confirm(): void {
        global $DB;

        $this->setAdminUser();

        $s        = $this->make_scenario();
        $override = $this->insert_override(
            (int) $s['cm']->id,
            (int) $s['student']->id,
            null,
            5.0,
            30.0
        );

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST                     = ['sesskey' => sesskey()];

        $ctrl = $this->make_controller($s, 'delete', (int) $override->id, true);

        try {
            $ctrl->process();
            self::fail('Expected moodle_exception from redirect().');
        } catch (\moodle_exception $e) {
            self::assertSame('redirecterrordetected', $e->errorcode);
        }

        self::assertFalse(
            $DB->record_exists('local_latepenalty_overrides', ['id' => $override->id]),
            'Override must be removed after a confirmed delete.'
        );
    }

    /**
     * process() leaves the override intact when confirm = false.
     */
    public function test_process_delete_leaves_record_without_confirm(): void {
        global $DB;

        $this->setAdminUser();

        $s        = $this->make_scenario();
        $override = $this->insert_override(
            (int) $s['cm']->id,
            (int) $s['student']->id,
            null,
            5.0,
            30.0
        );

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST                     = ['sesskey' => sesskey()];

        $ctrl = $this->make_controller($s, 'delete', (int) $override->id, false);
        $ctrl->process();

        self::assertTrue(
            $DB->record_exists('local_latepenalty_overrides', ['id' => $override->id]),
            'Override must remain when confirm = false.'
        );
    }

    /**
     * process() does not remove an override that belongs to a different CM.
     *
     * The cmid guard in the DELETE WHERE clause prevents cross-CM deletions.
     */
    public function test_process_delete_does_not_affect_foreign_override(): void {
        global $DB;

        $this->setAdminUser();

        $s1       = $this->make_scenario();
        $s2       = $this->make_scenario();
        $override = $this->insert_override(
            (int) $s2['cm']->id,
            (int) $s2['student']->id,
            null,
            5.0,
            30.0
        );

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST                     = ['sesskey' => sesskey()];

        $ctrl = new controller(
            (int) $s1['cm']->id,
            $s1['course'],
            $s1['cm'],
            $s1['ctx'],
            $s1['rule'],
            'delete',
            (int) $override->id,
            true
        );

        try {
            $ctrl->process();
        } catch (\moodle_exception $e) {
            self::assertSame('redirecterrordetected', $e->errorcode);
        }

        self::assertTrue(
            $DB->record_exists('local_latepenalty_overrides', ['id' => $override->id]),
            'Override from a different CM must not be deleted.'
        );
    }
}
