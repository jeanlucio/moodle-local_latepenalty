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
 * PHPUnit tests for the group override controller.
 *
 * Covers:
 *  - render(): notification when no group overrides exist (list mode)
 *  - render(): table rows contain group name and penalty values (list mode)
 *  - render(): add button always present (list mode)
 *  - render(): "no groups" notice when all course groups already have an override (add mode)
 *  - process(): delete removes the correct record on confirm + POST
 *  - process(): delete leaves the record intact when confirm = false
 *  - process(): delete does not remove a group override belonging to a different CM
 *
 * @package    local_latepenalty
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty\group_override;

use advanced_testcase;
use context_module;
use context_system;
use stdClass;

/**
 * Tests for local_latepenalty\group_override\controller.
 *
 * @covers \local_latepenalty\group_override\controller
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
     * Create a course, a group, an assign, and an active penalty rule.
     *
     * @return array{course: stdClass, group: stdClass, cm: stdClass,
     *               ctx: context_module, rule: stdClass}
     */
    private function make_scenario(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $group  = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

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
            'course' => $course,
            'group'  => $group,
            'cm'     => $cm,
            'ctx'    => $ctx,
            'rule'   => $rule,
        ];
    }

    /**
     * Insert a group override record directly into the DB.
     *
     * @param int        $cmid
     * @param int        $groupid
     * @param int|null   $deadline
     * @param float|null $daily
     * @param float|null $max
     * @return stdClass
     */
    private function insert_group_override(
        int $cmid,
        int $groupid,
        ?int $deadline,
        ?float $daily,
        ?float $max
    ): stdClass {
        global $DB;

        $id = $DB->insert_record('local_latepenalty_group_overrides', (object) [
            'cmid'          => $cmid,
            'groupid'       => $groupid,
            'deadline'      => $deadline,
            'daily_penalty' => $daily,
            'max_penalty'   => $max,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);
        return $DB->get_record('local_latepenalty_group_overrides', ['id' => $id]);
    }

    /**
     * Build a controller instance for the given scenario and action.
     *
     * @param array      $s                Scenario array from make_scenario().
     * @param string     $action
     * @param int        $overrideid
     * @param bool       $confirm
     * @param int[]|null $restrictgroupids Group IDs to confine the caller to, or null.
     * @return controller
     */
    private function make_controller(
        array $s,
        string $action,
        int $overrideid = 0,
        bool $confirm = false,
        ?array $restrictgroupids = null
    ): controller {
        return new controller(
            (int) $s['cm']->id,
            $s['course'],
            $s['cm'],
            $s['ctx'],
            $s['rule'],
            $action,
            $overrideid,
            $confirm,
            $restrictgroupids
        );
    }

    /**
     * Create a SEPARATEGROUPS-forced course with two groups, plus an assign
     * with an active penalty rule.
     *
     * @return array{course: stdClass, group1: stdClass, group2: stdClass, cm: stdClass,
     *               ctx: context_module, rule: stdClass}
     */
    private function make_group_restriction_scenario(): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');

        $course = $this->getDataGenerator()->create_course([
            'groupmode'      => SEPARATEGROUPS,
            'groupmodeforce' => 1,
        ]);

        $group1 = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $group2 = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'grade'  => 100,
        ]);
        $cm  = get_coursemodule_from_id('', $assign->cmid, 0, false, MUST_EXIST);
        $ctx = context_module::instance($assign->cmid);

        $rule = $DB->get_record('local_latepenalty_rules', ['cmid' => $assign->cmid], '*', MUST_EXIST);
        $rule->enabled       = 1;
        $rule->daily_penalty = 10.0;
        $rule->max_penalty   = 50.0;
        $DB->update_record('local_latepenalty_rules', $rule);

        return [
            'course' => $course,
            'group1' => $group1,
            'group2' => $group2,
            'cm'     => $cm,
            'ctx'    => $ctx,
            'rule'   => $rule,
        ];
    }

    // Tests: render() in list mode.

    /**
     * When no group overrides exist, render() returns the empty-state notification.
     */
    public function test_render_list_shows_notification_when_empty(): void {
        global $PAGE;

        $this->setAdminUser();
        $s    = $this->make_scenario();
        $ctrl = $this->make_controller($s, 'list');
        $ctrl->process();

        $html = $ctrl->render($PAGE->get_renderer('core'));

        self::assertStringContainsString(
            get_string('group_override_empty', 'local_latepenalty'),
            $html
        );
    }

    /**
     * When a group override exists, render() includes the group name and penalty values.
     * Null penalty fields are shown as the "inherit" placeholder string.
     */
    public function test_render_list_shows_group_name_and_penalties(): void {
        global $PAGE;

        $this->setAdminUser();
        $s = $this->make_scenario();
        $this->insert_group_override((int) $s['cm']->id, (int) $s['group']->id, null, 5.5, null);

        $ctrl = $this->make_controller($s, 'list');
        $ctrl->process();
        $html = $ctrl->render($PAGE->get_renderer('core'));

        self::assertStringContainsString($s['group']->name, $html);
        self::assertStringContainsString('5.5', $html);
        self::assertStringContainsString(
            get_string('override_inherit', 'local_latepenalty'),
            $html
        );
    }

    /**
     * render() always includes an "add group override" button whether the list is empty or not.
     */
    public function test_render_list_always_includes_add_button(): void {
        global $PAGE;

        $this->setAdminUser();
        $s    = $this->make_scenario();
        $ctrl = $this->make_controller($s, 'list');
        $ctrl->process();

        $html = $ctrl->render($PAGE->get_renderer('core'));

        self::assertStringContainsString(get_string('group_override_add', 'local_latepenalty'), $html);
    }

    // Tests: render() in add mode.

    /**
     * When every course group already has an override, render() shows the
     * "no groups available" notice instead of the add form.
     */
    public function test_render_add_shows_no_groups_when_all_covered(): void {
        global $OUTPUT;

        $this->setAdminUser();

        $s = $this->make_scenario();
        $this->insert_group_override((int) $s['cm']->id, (int) $s['group']->id, null, null, null);

        $ctrl = $this->make_controller($s, 'add');
        $ctrl->process();
        $html = $ctrl->render($OUTPUT);

        self::assertStringContainsString(
            get_string('group_override_no_groups', 'local_latepenalty'),
            $html
        );
    }

    // Tests: group restriction (separate groups).

    /**
     * build_group_options(), exercised via render() in add mode, must not offer
     * a group the caller is not confined to.
     */
    public function test_render_add_excludes_group_outside_restriction(): void {
        global $OUTPUT;

        $this->setAdminUser();
        $s = $this->make_group_restriction_scenario();

        $ctrl = $this->make_controller($s, 'add', 0, false, [(int) $s['group1']->id]);
        $ctrl->process();
        $html = $ctrl->render($OUTPUT);

        self::assertStringContainsString($s['group1']->name, $html);
        self::assertStringNotContainsString($s['group2']->name, $html);
    }

    /**
     * resolve_override_groupid(), exercised via the form data path, rejects a
     * group outside the caller's restriction even though it genuinely belongs
     * to the course.
     */
    public function test_save_add_rejects_group_outside_restriction(): void {
        global $DB;

        $this->setAdminUser();
        $s = $this->make_group_restriction_scenario();

        $ctrl = $this->make_controller($s, 'add', 0, false, [(int) $s['group1']->id]);
        $method = new \ReflectionMethod($ctrl, 'save_override');
        $method->setAccessible(true);

        $caught = null;
        try {
            $method->invoke($ctrl, (object) ['groupid' => $s['group2']->id]);
        } catch (\moodle_exception $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'moodle_exception must be thrown for a group outside the restriction.');
        self::assertSame('invalidrecord', $caught->errorcode);
        self::assertFalse(
            $DB->record_exists('local_latepenalty_group_overrides', ['cmid' => $s['cm']->id, 'groupid' => $s['group2']->id])
        );
    }

    /**
     * render_list() must not reveal a group override outside the caller's restriction.
     */
    public function test_render_list_excludes_override_outside_restriction(): void {
        global $PAGE;

        $this->setAdminUser();
        $s = $this->make_group_restriction_scenario();
        $this->insert_group_override((int) $s['cm']->id, (int) $s['group1']->id, null, 5.0, null);
        $this->insert_group_override((int) $s['cm']->id, (int) $s['group2']->id, null, 7.0, null);

        $ctrl = $this->make_controller($s, 'list', 0, false, [(int) $s['group1']->id]);
        $ctrl->process();
        $html = $ctrl->render($PAGE->get_renderer('core'));

        self::assertStringContainsString($s['group1']->name, $html);
        self::assertStringNotContainsString($s['group2']->name, $html);
    }

    /**
     * Editing a group override by ID must still be blocked when its group is
     * outside the caller's restriction — build_group_options() and
     * render_list() hiding it is not enough, since a caller who otherwise
     * obtains the overrideid (sequential IDs, a stale link) could otherwise
     * load and resubmit the edit form for a group outside their scope.
     */
    public function test_edit_rejects_override_outside_restriction(): void {
        $this->setAdminUser();
        $s = $this->make_group_restriction_scenario();
        $override2 = $this->insert_group_override((int) $s['cm']->id, (int) $s['group2']->id, null, 7.0, null);

        $ctrl = $this->make_controller($s, 'edit', (int) $override2->id, false, [(int) $s['group1']->id]);

        $caught = null;
        try {
            $ctrl->process();
        } catch (\moodle_exception $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'moodle_exception must be thrown when editing an out-of-scope group override.');
        self::assertSame('invalidrecord', $caught->errorcode);
    }

    /**
     * Deleting a group override by ID must still be blocked when its group is
     * outside the caller's restriction, and the row must survive.
     */
    public function test_process_delete_rejects_override_outside_restriction(): void {
        global $DB;

        $this->setAdminUser();
        $s = $this->make_group_restriction_scenario();
        $override2 = $this->insert_group_override((int) $s['cm']->id, (int) $s['group2']->id, null, 7.0, null);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST                     = ['sesskey' => sesskey()];

        $ctrl = $this->make_controller($s, 'delete', (int) $override2->id, true, [(int) $s['group1']->id]);

        $caught = null;
        try {
            $ctrl->process();
        } catch (\moodle_exception $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'moodle_exception must be thrown when deleting an out-of-scope group override.');
        self::assertSame('invalidrecord', $caught->errorcode);
        self::assertTrue(
            $DB->record_exists('local_latepenalty_group_overrides', ['id' => $override2->id]),
            'An out-of-scope group override must survive a blocked delete attempt.'
        );
    }

    /**
     * The delete confirmation page must not reveal, or offer to delete, a
     * group override outside the caller's restriction.
     */
    public function test_render_delete_confirm_rejects_override_outside_restriction(): void {
        global $PAGE;

        $this->setAdminUser();
        $s = $this->make_group_restriction_scenario();
        $override2 = $this->insert_group_override((int) $s['cm']->id, (int) $s['group2']->id, null, 7.0, null);

        $ctrl = $this->make_controller($s, 'delete', (int) $override2->id, false, [(int) $s['group1']->id]);

        $caught = null;
        try {
            $ctrl->render($PAGE->get_renderer('core'));
        } catch (\moodle_exception $e) {
            $caught = $e;
        }

        self::assertNotNull(
            $caught,
            'moodle_exception must be thrown rendering an out-of-scope group delete confirmation.'
        );
        self::assertSame('invalidrecord', $caught->errorcode);
    }

    // Tests: process() in delete mode.

    /**
     * process() removes the group override when confirm = true and a valid POST + sesskey.
     */
    public function test_process_delete_removes_record_on_confirm(): void {
        global $DB;

        $this->setAdminUser();

        $s        = $this->make_scenario();
        $override = $this->insert_group_override(
            (int) $s['cm']->id,
            (int) $s['group']->id,
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
            $DB->record_exists('local_latepenalty_group_overrides', ['id' => $override->id]),
            'Group override must be removed after a confirmed delete.'
        );
    }

    /**
     * process() leaves the group override intact when confirm = false.
     */
    public function test_process_delete_leaves_record_without_confirm(): void {
        global $DB;

        $this->setAdminUser();

        $s        = $this->make_scenario();
        $override = $this->insert_group_override(
            (int) $s['cm']->id,
            (int) $s['group']->id,
            null,
            5.0,
            30.0
        );

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST                     = ['sesskey' => sesskey()];

        $ctrl = $this->make_controller($s, 'delete', (int) $override->id, false);
        $ctrl->process();

        self::assertTrue(
            $DB->record_exists('local_latepenalty_group_overrides', ['id' => $override->id]),
            'Group override must remain when confirm = false.'
        );
    }

    /**
     * process() does not remove a group override that belongs to a different CM.
     */
    public function test_process_delete_does_not_affect_foreign_override(): void {
        global $DB;

        $this->setAdminUser();

        $s1       = $this->make_scenario();
        $s2       = $this->make_scenario();
        $override = $this->insert_group_override(
            (int) $s2['cm']->id,
            (int) $s2['group']->id,
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
            // Fetch_scoped_override() now rejects a cmid mismatch outright
            // (invalidrecord) before process_delete() ever reaches redirect().
            self::assertSame('invalidrecord', $e->errorcode);
        }

        self::assertTrue(
            $DB->record_exists('local_latepenalty_group_overrides', ['id' => $override->id]),
            'Group override from a different CM must not be deleted.'
        );
    }
}
