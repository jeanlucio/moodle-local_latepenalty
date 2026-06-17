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
 * Backup and restore tests for local_latepenalty.
 *
 * @package    local_latepenalty
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty\backup;

use advanced_testcase;
use backup;
use backup_controller;
use backup_setting;
use restore_controller;
use restore_dbops;
use stdClass;

/**
 * Tests for the backup and restore cycle of local_latepenalty.
 *
 * Covers the rule (content) and the per-user / per-group overrides (user data),
 * attached to a host activity through the generic module plugin backup hook.
 *
 * @covers \backup_local_latepenalty_plugin
 * @covers \restore_local_latepenalty_plugin
 */
final class restore_test extends advanced_testcase {
    /**
     * Load backup/restore API before any test in this class.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
    }

    /**
     * Restoring a course whose module carries an enabled rule must not throw and
     * must recreate the rule against the new course module ID with its fields
     * preserved. This is the regression guard for the restore step resolving the
     * course module before the activity instance is linked.
     */
    public function test_backup_restore_rule(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $assign, ] = $this->create_fixture(withusers: false);

        $newcourseid = $this->backup_and_restore($course, false);

        $newcmid = $this->get_restored_cmid($newcourseid);

        // The rule travelled with the activity and points at the new course module.
        $this->assertSame(1, $DB->count_records('local_latepenalty_rules', ['cmid' => $newcmid]));

        $rule = $DB->get_record('local_latepenalty_rules', ['cmid' => $newcmid], '*', MUST_EXIST);

        // Configuration fields preserved.
        $this->assertSame(1, (int) $rule->enabled);
        $this->assertEqualsWithDelta(10.00, (float) $rule->daily_penalty, 0.001);
        $this->assertEqualsWithDelta(50.00, (float) $rule->max_penalty, 0.001);
        $this->assertSame(1, (int) $rule->recalc_on_deadline);
        $this->assertSame(1, (int) $rule->recalc_on_rate);

        // The last_deadline seed degrades to 0 because the activity instance is not
        // yet linked to the course module while the rule node is processed; the
        // first teacher save after restore recomputes it.
        $this->assertSame(0, (int) $rule->last_deadline);

        // The original assign duedate is irrelevant to the assertion above but is
        // referenced to keep the fixture meaningful.
        $this->assertGreaterThan(0, (int) $assign->duedate);
    }

    /**
     * With user data included, the per-user and per-group overrides are restored
     * with their cmid, userid and groupid remapped to the new course.
     */
    public function test_backup_restore_overrides_with_userinfo(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, , $user, $groupid] = $this->create_fixture(withusers: true);

        $newcourseid = $this->backup_and_restore($course, true);

        $newcmid = $this->get_restored_cmid($newcourseid);

        // User override restored and remapped.
        $this->assertSame(1, $DB->count_records('local_latepenalty_overrides', ['cmid' => $newcmid]));

        $override = $DB->get_record('local_latepenalty_overrides', ['cmid' => $newcmid], '*', MUST_EXIST);
        $this->assertSame((int) $user->id, (int) $override->userid);
        $this->assertEqualsWithDelta(5.00, (float) $override->daily_penalty, 0.001);

        // Group override restored, with the group remapped into the new course.
        $this->assertSame(1, $DB->count_records('local_latepenalty_group_overrides', ['cmid' => $newcmid]));

        $groupoverride = $DB->get_record(
            'local_latepenalty_group_overrides',
            ['cmid' => $newcmid],
            '*',
            MUST_EXIST
        );
        $this->assertGreaterThan(0, (int) $groupoverride->groupid);
        $this->assertNotSame($groupid, (int) $groupoverride->groupid);
        $this->assertTrue($DB->record_exists('groups', [
            'id'       => $groupoverride->groupid,
            'courseid' => $newcourseid,
        ]));
        $this->assertEqualsWithDelta(25.00, (float) $groupoverride->max_penalty, 0.001);
    }

    /**
     * Restoring into a new course leaves the source course's rule untouched.
     */
    public function test_original_course_unaffected(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $assign] = $this->create_fixture(withusers: false);

        $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id, false, MUST_EXIST);
        $originalcount = $DB->count_records('local_latepenalty_rules', ['cmid' => $cm->id]);

        $this->backup_and_restore($course, false);

        $this->assertSame(
            $originalcount,
            $DB->count_records('local_latepenalty_rules', ['cmid' => $cm->id])
        );
        $this->assertTrue($DB->record_exists('local_latepenalty_rules', ['cmid' => $cm->id]));
    }

    /**
     * Creates a course with one assign activity carrying an enabled penalty rule,
     * plus optional per-user and per-group overrides when users are requested.
     *
     * @param bool $withusers Whether to create an enrolled student, a group and overrides.
     * @return array{0: stdClass, 1: stdClass, 2: stdClass|null, 3: int}
     *   [course, assign, user, groupid]
     */
    private function create_fixture(bool $withusers): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course'  => $course->id,
            'name'    => 'Trabalho final',
            'duedate' => time() + DAYSECS,
        ]);
        $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id, false, MUST_EXIST);

        // Module creation already inserted a disabled rule through the
        // coursemodule_edit_post_actions hook; enable it with known values
        // (content — backed up regardless of userinfo).
        $rule = $DB->get_record('local_latepenalty_rules', ['cmid' => $cm->id], '*', MUST_EXIST);
        $rule->enabled            = 1;
        $rule->daily_penalty      = 10.00;
        $rule->max_penalty        = 50.00;
        $rule->recalc_on_deadline = 1;
        $rule->recalc_on_rate     = 1;
        $rule->last_deadline      = (int) $assign->duedate;
        $DB->update_record('local_latepenalty_rules', $rule);

        $user    = null;
        $groupid = 0;

        if ($withusers) {
            $user  = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
            groups_add_member($group->id, $user->id);
            $groupid = (int) $group->id;

            $DB->insert_record('local_latepenalty_overrides', (object) [
                'cmid'          => $cm->id,
                'userid'        => $user->id,
                'deadline'      => time() + (2 * DAYSECS),
                'daily_penalty' => 5.00,
                'max_penalty'   => 30.00,
                'timecreated'   => time(),
                'timemodified'  => time(),
            ]);

            $DB->insert_record('local_latepenalty_group_overrides', (object) [
                'cmid'          => $cm->id,
                'groupid'       => $groupid,
                'deadline'      => time() + (3 * DAYSECS),
                'daily_penalty' => 7.50,
                'max_penalty'   => 25.00,
                'timecreated'   => time(),
                'timemodified'  => time(),
            ]);
        }

        return [$course, $assign, $user, $groupid];
    }

    /**
     * Resolves the course module ID of the single assign restored into a course.
     *
     * @param int $courseid The restored course ID.
     * @return int The new course_modules.id.
     */
    private function get_restored_cmid(int $courseid): int {
        $modinfo = get_fast_modinfo($courseid);
        $instances = $modinfo->get_instances_of('assign');
        $cm = reset($instances);

        return (int) $cm->id;
    }

    /**
     * Backs up a course and restores it to a new course.
     *
     * Uses MODE_IMPORT (no ZIP) for speed.
     *
     * @param stdClass $srccourse Source course.
     * @param bool $userinfo Whether to include user data in the backup.
     * @return int ID of the newly restored course.
     */
    private function backup_and_restore(stdClass $srccourse, bool $userinfo): int {
        global $USER, $CFG;

        $CFG->backup_file_logger_level = backup::LOG_NONE;

        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $srccourse->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $USER->id
        );

        $bc->get_plan()->get_setting('users')->set_status(backup_setting::NOT_LOCKED);
        $bc->get_plan()->get_setting('users')->set_value($userinfo);

        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $newcourseid = restore_dbops::create_new_course(
            $srccourse->fullname,
            $srccourse->shortname . '_restore',
            $srccourse->category
        );

        $rc = new restore_controller(
            $backupid,
            $newcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
            backup::TARGET_NEW_COURSE
        );

        $rc->get_plan()->get_setting('users')->set_status(backup_setting::NOT_LOCKED);
        $rc->get_plan()->get_setting('users')->set_value($userinfo);

        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return $newcourseid;
    }
}
