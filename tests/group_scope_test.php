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
 * PHPUnit tests for local_latepenalty\group_scope.
 *
 * @package    local_latepenalty
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty;

use advanced_testcase;
use context_module;
use stdClass;

/**
 * Tests for local_latepenalty\group_scope::resolve_activity_restriction().
 *
 * @covers \local_latepenalty\group_scope
 */
final class group_scope_test extends advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Create a course and one assign activity, with the given course-level and
     * activity-level group mode settings.
     *
     * @param int $coursegroupmode Course-level group mode.
     * @param int $groupmodeforce  Whether the course forces its mode onto activities.
     * @param int $cmgroupmode     Activity-level group mode (ignored when forced).
     * @return array{course: stdClass, cm: stdClass, modcontext: context_module}
     */
    private function make_activity(int $coursegroupmode, int $groupmodeforce, int $cmgroupmode): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $course = $this->getDataGenerator()->create_course([
            'groupmode'      => $coursegroupmode,
            'groupmodeforce' => $groupmodeforce,
        ]);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $DB->set_field('course_modules', 'groupmode', $cmgroupmode, ['id' => $assign->cmid]);

        $cm = get_coursemodule_from_id('', $assign->cmid, 0, false, MUST_EXIST);

        return [
            'course'     => $course,
            'cm'         => $cm,
            'modcontext' => context_module::instance($assign->cmid),
        ];
    }

    /**
     * An activity not in separate groups (course-level NOGROUPS, unforced,
     * activity-level NOGROUPS) is never restricted.
     */
    public function test_returns_null_when_not_separate_groups(): void {
        $a = $this->make_activity(NOGROUPS, 0, NOGROUPS);
        $teacher = $this->getDataGenerator()->create_and_enrol($a['course'], 'teacher');
        $this->setUser($teacher);

        self::assertNull(group_scope::resolve_activity_restriction($a['cm'], $a['modcontext']));
    }

    /**
     * An activity in VISIBLEGROUPS mode is never restricted — visible groups
     * means everyone can see every group's data.
     */
    public function test_returns_null_for_visible_groups(): void {
        $a = $this->make_activity(NOGROUPS, 0, VISIBLEGROUPS);
        $teacher = $this->getDataGenerator()->create_and_enrol($a['course'], 'teacher');
        $this->setUser($teacher);

        self::assertNull(group_scope::resolve_activity_restriction($a['cm'], $a['modcontext']));
    }

    /**
     * A caller with moodle/site:accessallgroups is never restricted, even in a
     * separate-groups activity — editingteacher carries it by default.
     */
    public function test_returns_null_when_caller_has_accessallgroups(): void {
        $a = $this->make_activity(SEPARATEGROUPS, 1, NOGROUPS);
        $editingteacher = $this->getDataGenerator()->create_and_enrol($a['course'], 'editingteacher');
        $this->setUser($editingteacher);

        self::assertNull(group_scope::resolve_activity_restriction($a['cm'], $a['modcontext']));
    }

    /**
     * A caller without accessallgroups, in a separate-groups activity, is
     * restricted to exactly the group(s) they belong to.
     */
    public function test_returns_own_groups_when_restricted(): void {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $a = $this->make_activity(SEPARATEGROUPS, 1, NOGROUPS);
        $teacher = $this->getDataGenerator()->create_and_enrol($a['course'], 'teacher');
        $group = $this->getDataGenerator()->create_group(['courseid' => $a['course']->id]);
        groups_add_member($group->id, $teacher->id);
        $this->setUser($teacher);

        $result = group_scope::resolve_activity_restriction($a['cm'], $a['modcontext']);

        self::assertSame([(int) $group->id], $result);
    }

    /**
     * A caller without accessallgroups and belonging to no group at all, in a
     * separate-groups activity, is restricted to an empty set — must see
     * nothing, not everything (null would mean unrestricted).
     */
    public function test_returns_empty_array_when_caller_has_no_group(): void {
        $a = $this->make_activity(SEPARATEGROUPS, 1, NOGROUPS);
        $teacher = $this->getDataGenerator()->create_and_enrol($a['course'], 'teacher');
        $this->setUser($teacher);

        self::assertSame([], group_scope::resolve_activity_restriction($a['cm'], $a['modcontext']));
    }

    /**
     * Course-level groupmodeforce overrides the activity's own groupmode
     * setting: a course forced into SEPARATEGROUPS still restricts an
     * activity individually configured as NOGROUPS.
     */
    public function test_course_level_force_overrides_activity_groupmode(): void {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $a = $this->make_activity(SEPARATEGROUPS, 1, NOGROUPS);
        $teacher = $this->getDataGenerator()->create_and_enrol($a['course'], 'teacher');
        $group = $this->getDataGenerator()->create_group(['courseid' => $a['course']->id]);
        groups_add_member($group->id, $teacher->id);
        $this->setUser($teacher);

        self::assertSame([(int) $group->id], group_scope::resolve_activity_restriction($a['cm'], $a['modcontext']));
    }

    /**
     * Activity-level groupmode applies when the course does not force its
     * own mode: a NOGROUPS course with an activity individually set to
     * SEPARATEGROUPS still restricts that activity.
     */
    public function test_activity_level_groupmode_applies_when_course_does_not_force(): void {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $a = $this->make_activity(NOGROUPS, 0, SEPARATEGROUPS);
        $teacher = $this->getDataGenerator()->create_and_enrol($a['course'], 'teacher');
        $group = $this->getDataGenerator()->create_group(['courseid' => $a['course']->id]);
        groups_add_member($group->id, $teacher->id);
        $this->setUser($teacher);

        self::assertSame([(int) $group->id], group_scope::resolve_activity_restriction($a['cm'], $a['modcontext']));
    }
}
