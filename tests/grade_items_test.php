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

use local_latepenalty\tests\latepenalty_testcase;

/**
 * Which grade items of an activity the penalty applies to (F13).
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\penalty_helper
 * @covers \local_latepenalty\observer
 * @covers \local_latepenalty\recalculator
 * @covers \local_latepenalty\local\deadline_resolver
 * @covers \local_latepenalty\local\submission_resolver
 * @covers \local_latepenalty\local\penalty_writer
 * @covers \local_latepenalty\local\deadline
 */
final class grade_items_test extends latepenalty_testcase {
    /**
     * Item numbers returned by get_penalisable_items() for a course module.
     *
     * @param int $cmid Course module ID.
     * @return int[]
     */
    private function penalisable_itemnumbers(int $cmid): array {
        [, $cm] = get_course_and_cm_from_cmid($cmid);
        return array_map(
            fn(\grade_item $item): int => (int) $item->itemnumber,
            penalty_helper::get_penalisable_items($cm->get_course_module_record(true))
        );
    }

    /**
     * Forum with ratings, whole-forum grading and an outcome: both grade items, no outcome.
     *
     * @return void
     */
    public function test_forum_items_without_outcome(): void {
        global $CFG;
        require_once($CFG->dirroot . '/rating/lib.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id,
            'assessed' => RATING_AGGREGATE_AVERAGE,
            'scale' => 100,
            'grade_forum' => 100,
        ]);
        $scale = $this->getDataGenerator()->create_scale(['courseid' => $course->id]);
        $outcome = $this->getDataGenerator()->create_grade_outcome([
            'courseid' => $course->id,
            'shortname' => 'outcome',
            'scaleid' => $scale->id,
        ]);
        $this->getDataGenerator()->create_grade_item([
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'forum',
            'iteminstance' => $forum->id,
            'itemnumber' => 1000,
            'outcomeid' => $outcome->id,
            'gradetype' => GRADE_TYPE_SCALE,
            'scaleid' => $outcome->scaleid,
        ]);

        $this->assertSame([0, 1], $this->penalisable_itemnumbers($forum->cmid));
    }

    /**
     * Workshop: only the submission item, never the assessment item.
     *
     * @return void
     */
    public function test_workshop_assessment_item_is_excluded(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $workshop = $this->getDataGenerator()->create_module('workshop', ['course' => $course->id]);

        $this->assertSame([0], $this->penalisable_itemnumbers($workshop->cmid));
    }

    /**
     * Single-item activity: unchanged (F13-06).
     *
     * @return void
     */
    public function test_single_item_activity(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 100]);

        $this->assertSame([0], $this->penalisable_itemnumbers($assign->cmid));
    }

    /**
     * Activity without grade items: nothing to penalise.
     *
     * @return void
     */
    public function test_activity_without_grade_items(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $this->assertSame([], $this->penalisable_itemnumbers($page->cmid));
    }
}
