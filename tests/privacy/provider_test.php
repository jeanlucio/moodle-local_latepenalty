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
 * PHPUnit tests for the Late Penalty privacy provider.
 *
 * @package    local_latepenalty
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty\privacy;

use context_module;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use stdClass;

/**
 * Tests for local_latepenalty\privacy\provider.
 *
 * @covers \local_latepenalty\privacy\provider
 */
final class provider_test extends provider_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Create a course, an assign activity, and two students, one of whom has
     * an override recorded against the activity.
     *
     * @return array{cmid: int, context: context_module, student1: stdClass,
     *               student2: stdClass, override: stdClass}
     */
    private function make_scenario(): array {
        global $DB;

        $course   = $this->getDataGenerator()->create_course();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student1->id, $course->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course->id);

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cmid   = (int) $assign->cmid;
        $context = context_module::instance($cmid);

        $overrideid = $DB->insert_record('local_latepenalty_overrides', (object) [
            'cmid'          => $cmid,
            'userid'        => $student1->id,
            'deadline'      => time() + DAYSECS,
            'daily_penalty' => 5.0,
            'max_penalty'   => 40.0,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);
        $override = $DB->get_record('local_latepenalty_overrides', ['id' => $overrideid], '*', MUST_EXIST);

        return [
            'cmid'     => $cmid,
            'context'  => $context,
            'student1' => $student1,
            'student2' => $student2,
            'override' => $override,
        ];
    }

    /**
     * get_metadata() declares the overrides table and its personal-data fields.
     */
    public function test_get_metadata(): void {
        $collection = new \core_privacy\local\metadata\collection('local_latepenalty');
        $collection = provider::get_metadata($collection);

        $tables = $collection->get_collection();
        self::assertCount(1, $tables);

        $table = reset($tables);
        self::assertSame('local_latepenalty_overrides', $table->get_name());

        $fields = $table->get_privacy_fields();
        self::assertArrayHasKey('userid', $fields);
        self::assertArrayHasKey('cmid', $fields);
        self::assertArrayHasKey('deadline', $fields);
        self::assertArrayHasKey('daily_penalty', $fields);
        self::assertArrayHasKey('max_penalty', $fields);
        self::assertArrayHasKey('timecreated', $fields);
        self::assertArrayHasKey('timemodified', $fields);
    }

    /**
     * get_contexts_for_userid() returns the module context for a student with an
     * override, and no contexts for one without.
     */
    public function test_get_contexts_for_userid(): void {
        $this->setAdminUser();
        $s = $this->make_scenario();

        $withoverride = array_map('intval', provider::get_contexts_for_userid((int) $s['student1']->id)->get_contextids());
        self::assertContains((int) $s['context']->id, $withoverride);

        $withoutoverride = array_map(
            'intval',
            provider::get_contexts_for_userid((int) $s['student2']->id)->get_contextids()
        );
        self::assertNotContains((int) $s['context']->id, $withoutoverride);
    }

    /**
     * get_users_in_context() returns only the student who has an override for
     * that context, ignoring a non-context_module context entirely.
     */
    public function test_get_users_in_context(): void {
        $this->setAdminUser();
        $s = $this->make_scenario();

        $userlist = new userlist($s['context'], 'local_latepenalty');
        provider::get_users_in_context($userlist);

        self::assertSame([(int) $s['student1']->id], $userlist->get_userids());
    }

    /**
     * get_users_in_context() returns nothing for a context that is not a
     * context_module (the provider's own instanceof guard).
     */
    public function test_get_users_in_context_ignores_non_module_context(): void {
        $this->setAdminUser();
        $s = $this->make_scenario();

        $systemcontext = \context_system::instance();
        $userlist = new userlist($systemcontext, 'local_latepenalty');
        provider::get_users_in_context($userlist);

        self::assertSame([], $userlist->get_userids());
    }

    /**
     * export_user_data() writes the override's deadline and penalty fields
     * under the module context for the requesting user.
     */
    public function test_export_user_data(): void {
        $this->setAdminUser();
        $s = $this->make_scenario();

        $approvedlist = new approved_contextlist(
            \core_user::get_user((int) $s['student1']->id),
            'local_latepenalty',
            [(int) $s['context']->id]
        );
        provider::export_user_data($approvedlist);

        $data = writer::with_context($s['context'])->get_data([get_string('overrides', 'local_latepenalty')]);

        self::assertNotFalse($data);
        self::assertEqualsWithDelta(5.0, (float) $data->daily_penalty, 0.001);
        self::assertEqualsWithDelta(40.0, (float) $data->max_penalty, 0.001);
    }

    /**
     * delete_data_for_all_users_in_context() removes the override for that
     * context only, and does nothing for a non-context_module context.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        $this->setAdminUser();
        $s = $this->make_scenario();

        provider::delete_data_for_all_users_in_context(\context_system::instance());
        self::assertTrue($DB->record_exists('local_latepenalty_overrides', ['id' => $s['override']->id]));

        provider::delete_data_for_all_users_in_context($s['context']);
        self::assertFalse($DB->record_exists('local_latepenalty_overrides', ['id' => $s['override']->id]));
    }

    /**
     * delete_data_for_user() removes only the requesting user's override
     * within the approved contexts, leaving other students' rows intact.
     */
    public function test_delete_data_for_user(): void {
        global $DB;

        $this->setAdminUser();
        $s = $this->make_scenario();

        $otheroverrideid = $DB->insert_record('local_latepenalty_overrides', (object) [
            'cmid'          => $s['cmid'],
            'userid'        => $s['student2']->id,
            'deadline'      => null,
            'daily_penalty' => 7.0,
            'max_penalty'   => null,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);

        $approvedlist = new approved_contextlist(
            \core_user::get_user((int) $s['student1']->id),
            'local_latepenalty',
            [(int) $s['context']->id]
        );
        provider::delete_data_for_user($approvedlist);

        self::assertFalse($DB->record_exists('local_latepenalty_overrides', ['id' => $s['override']->id]));
        self::assertTrue($DB->record_exists('local_latepenalty_overrides', ['id' => $otheroverrideid]));
    }

    /**
     * delete_data_for_users() removes only the userids named in the approved
     * userlist, within the given context, leaving others intact.
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        $this->setAdminUser();
        $s = $this->make_scenario();

        $otheroverrideid = $DB->insert_record('local_latepenalty_overrides', (object) [
            'cmid'          => $s['cmid'],
            'userid'        => $s['student2']->id,
            'deadline'      => null,
            'daily_penalty' => 7.0,
            'max_penalty'   => null,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);

        $approvedlist = new approved_userlist($s['context'], 'local_latepenalty', [(int) $s['student1']->id]);
        provider::delete_data_for_users($approvedlist);

        self::assertFalse($DB->record_exists('local_latepenalty_overrides', ['id' => $s['override']->id]));
        self::assertTrue($DB->record_exists('local_latepenalty_overrides', ['id' => $otheroverrideid]));
    }
}
