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
 * PHPUnit tests for penalty_helper group override methods.
 *
 * Covers:
 *  - get_group_override(): null when user has no applicable group override
 *  - get_group_override(): returns values for a single group override
 *  - get_group_override(): returns most-lenient merged values across multiple groups
 *    (MAX deadline, MIN daily_penalty, MIN max_penalty — per-field, independently)
 *  - get_group_override(): null when user is in a group but that group has no override for the CM
 *  - get_group_overrides_bulk(): empty input returns empty array
 *  - get_group_overrides_bulk(): returns merged overrides keyed by userid
 *  - get_group_overrides_bulk(): users without applicable group overrides are absent
 *
 * @package    local_latepenalty
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty;

use advanced_testcase;

/**
 * Tests for penalty_helper::get_group_override() and get_group_overrides_bulk().
 *
 * @covers \local_latepenalty\penalty_helper::get_group_override
 * @covers \local_latepenalty\penalty_helper::get_group_overrides_bulk
 */
final class penalty_helper_group_test extends advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    // Helpers.

    /**
     * Create a course, an assign module and return cmid, course and a
     * created-but-not-enrolled user ready to be placed in groups.
     *
     * @return array{cmid: int, courseid: int}
     */
    private function make_course_with_assign(): array {
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 100]);
        return ['cmid' => (int) $assign->cmid, 'courseid' => (int) $course->id];
    }

    /**
     * Insert a group override record directly.
     *
     * @param int        $cmid
     * @param int        $groupid
     * @param int|null   $deadline
     * @param float|null $daily
     * @param float|null $max
     * @return void
     */
    private function insert_group_override(
        int $cmid,
        int $groupid,
        ?int $deadline,
        ?float $daily,
        ?float $max
    ): void {
        global $DB;

        $DB->insert_record('local_latepenalty_group_overrides', (object) [
            'cmid'          => $cmid,
            'groupid'       => $groupid,
            'deadline'      => $deadline,
            'daily_penalty' => $daily,
            'max_penalty'   => $max,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);
    }

    // Tests: get_group_override().

    /**
     * Returns null when the user belongs to no group with an override for the CM.
     */
    public function test_get_group_override_returns_null_when_no_applicable_override(): void {
        $this->setAdminUser();
        $s = $this->make_course_with_assign();

        $user  = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $s['courseid']);
        $group = $this->getDataGenerator()->create_group(['courseid' => $s['courseid']]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $user->id]);

        // No override recorded for this group.
        $result = penalty_helper::get_group_override($s['cmid'], (int) $user->id);

        self::assertNull($result);
    }

    /**
     * Returns null when no override exists for the CM at all (user not in any group).
     */
    public function test_get_group_override_returns_null_when_user_not_in_any_group(): void {
        $this->setAdminUser();
        $s    = $this->make_course_with_assign();
        $user = $this->getDataGenerator()->create_user();

        self::assertNull(penalty_helper::get_group_override($s['cmid'], (int) $user->id));
    }

    /**
     * Returns the override values when the user is in exactly one group that has an override.
     */
    public function test_get_group_override_returns_values_for_single_group(): void {
        $this->setAdminUser();
        $s = $this->make_course_with_assign();

        $user     = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $s['courseid']);
        $group    = $this->getDataGenerator()->create_group(['courseid' => $s['courseid']]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $user->id]);

        $deadline = time() + DAYSECS;
        $this->insert_group_override($s['cmid'], (int) $group->id, $deadline, 5.0, 40.0);

        $result = penalty_helper::get_group_override($s['cmid'], (int) $user->id);

        self::assertNotNull($result);
        self::assertEquals($deadline, (int) $result->deadline);
        self::assertEquals(5.0, (float) $result->daily_penalty);
        self::assertEquals(40.0, (float) $result->max_penalty);
    }

    /**
     * When the user is in two groups, the most-lenient value per field is returned:
     * MAX for deadline (latest), MIN for daily_penalty and max_penalty (lowest).
     */
    public function test_get_group_override_returns_most_lenient_across_multiple_groups(): void {
        $this->setAdminUser();
        $s = $this->make_course_with_assign();

        $user   = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $s['courseid']);
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $s['courseid']]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $s['courseid']]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $user->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $user->id]);

        $laterdeadline   = time() + 2 * DAYSECS;
        $earlierdeadline = time() + DAYSECS;

        // Group A: later deadline, higher daily, lower max.
        $this->insert_group_override($s['cmid'], (int) $groupa->id, $laterdeadline, 8.0, 30.0);
        // Group B: earlier deadline, lower daily, higher max.
        $this->insert_group_override($s['cmid'], (int) $groupb->id, $earlierdeadline, 3.0, 60.0);

        $result = penalty_helper::get_group_override($s['cmid'], (int) $user->id);

        self::assertNotNull($result);
        // Most lenient deadline = MAX = later deadline.
        self::assertEquals($laterdeadline, (int) $result->deadline);
        // Most lenient daily = MIN = 3.0.
        self::assertEquals(3.0, (float) $result->daily_penalty);
        // Most lenient max = MIN = 30.0.
        self::assertEquals(30.0, (float) $result->max_penalty);
    }

    /**
     * Null fields in group overrides are handled correctly: a field set to null in all
     * applicable overrides returns null (meaning "inherit from rule"), while non-null
     * fields in other overrides for the same user are still resolved.
     */
    public function test_get_group_override_handles_partial_null_fields(): void {
        $this->setAdminUser();
        $s = $this->make_course_with_assign();

        $user   = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $s['courseid']);
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $s['courseid']]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $s['courseid']]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $user->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $user->id]);

        // Group A: deadline set, daily null, max set.
        $deadline = time() + DAYSECS;
        $this->insert_group_override($s['cmid'], (int) $groupa->id, $deadline, null, 50.0);
        // Group B: deadline null, daily set, max null.
        $this->insert_group_override($s['cmid'], (int) $groupb->id, null, 4.0, null);

        $result = penalty_helper::get_group_override($s['cmid'], (int) $user->id);

        self::assertNotNull($result);
        self::assertEquals($deadline, (int) $result->deadline);
        self::assertEquals(4.0, (float) $result->daily_penalty);
        self::assertEquals(50.0, (float) $result->max_penalty);
    }

    // Tests: get_group_overrides_bulk().

    /**
     * Returns an empty array when the input user ID list is empty.
     */
    public function test_get_group_overrides_bulk_empty_input(): void {
        $this->setAdminUser();
        $s = $this->make_course_with_assign();

        self::assertSame([], penalty_helper::get_group_overrides_bulk($s['cmid'], []));
    }

    /**
     * Returns a merged override for each user who has an applicable group override,
     * keyed by userid. Users without group overrides are absent from the result.
     */
    public function test_get_group_overrides_bulk_returns_per_user_merged_values(): void {
        $this->setAdminUser();
        $s = $this->make_course_with_assign();

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user1->id, $s['courseid']);
        $this->getDataGenerator()->enrol_user($user2->id, $s['courseid']);

        $group = $this->getDataGenerator()->create_group(['courseid' => $s['courseid']]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $user1->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $user2->id]);

        $deadline = time() + DAYSECS;
        $this->insert_group_override($s['cmid'], (int) $group->id, $deadline, 6.0, 45.0);

        $result = penalty_helper::get_group_overrides_bulk(
            $s['cmid'],
            [(int) $user1->id, (int) $user2->id, (int) $user3->id]
        );

        // User1 and user2 are in the group, user3 is not.
        self::assertArrayHasKey((int) $user1->id, $result);
        self::assertArrayHasKey((int) $user2->id, $result);
        self::assertArrayNotHasKey((int) $user3->id, $result);

        self::assertEquals($deadline, (int) $result[(int) $user1->id]->deadline);
        self::assertEquals(6.0, (float) $result[(int) $user1->id]->daily_penalty);
        self::assertEquals(45.0, (float) $result[(int) $user1->id]->max_penalty);
    }

    /**
     * Bulk resolution applies the same most-lenient logic (MAX/MIN) as the single-user method.
     */
    public function test_get_group_overrides_bulk_applies_most_lenient_per_user(): void {
        $this->setAdminUser();
        $s = $this->make_course_with_assign();

        $user   = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $s['courseid']);
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $s['courseid']]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $s['courseid']]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $user->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $user->id]);

        $laterdeadline   = time() + 3 * DAYSECS;
        $earlierdeadline = time() + DAYSECS;
        $this->insert_group_override($s['cmid'], (int) $groupa->id, $laterdeadline, 10.0, 20.0);
        $this->insert_group_override($s['cmid'], (int) $groupb->id, $earlierdeadline, 2.0, 80.0);

        $result = penalty_helper::get_group_overrides_bulk($s['cmid'], [(int) $user->id]);

        self::assertArrayHasKey((int) $user->id, $result);
        $merged = $result[(int) $user->id];
        self::assertEquals($laterdeadline, (int) $merged->deadline);
        self::assertEquals(2.0, (float) $merged->daily_penalty);
        self::assertEquals(20.0, (float) $merged->max_penalty);
    }
}
