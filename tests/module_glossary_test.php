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
 * Late penalties on rated glossaries, driven through the glossary and rating APIs.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\observer
 * @covers \local_latepenalty\recalculator
 * @covers \local_latepenalty\penalty_helper
 */
final class module_glossary_test extends latepenalty_testcase {
    /**
     * An entry created on time and rated after the deadline is not penalised (F10-03).
     *
     * @return void
     */
    public function test_entry_created_on_time_and_rated_late_is_not_penalised(): void {
        global $CFG;
        require_once($CFG->dirroot . '/rating/lib.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        $deadline = time() - 5 * DAYSECS;
        [$course, $student, $teacher] = $this->create_course_with_users();
        $glossary = $this->getDataGenerator()->create_module('glossary', [
            'course' => $course->id,
            'assessed' => RATING_AGGREGATE_AVERAGE,
            'scale' => 100,
        ]);
        $this->set_reminder($glossary->cmid, $deadline);
        $this->enable_rule($glossary->cmid);

        $entry = $this->getDataGenerator()->get_plugin_generator('mod_glossary')->create_content($glossary, [
            'userid' => $student->id,
            'approved' => 1,
            'timecreated' => $deadline - DAYSECS,
            'timemodified' => $deadline - DAYSECS,
        ]);
        $this->rate_item($glossary->cmid, 'entry', $entry->id, 100, $student->id, $teacher);
        $this->assertSame(100.0, $this->final_grade('glossary', $glossary->id, $student->id));

        recalculator::recalculate_for_student($glossary->cmid, $student->id, 10.0, 50.0);

        $this->assertSame(100.0, $this->final_grade('glossary', $glossary->id, $student->id));
    }

    /**
     * Create a rated glossary with a rule, the reminder 5 days ago.
     *
     * @param int $aggregation Rating aggregation (RATING_AGGREGATE_*).
     * @param array $settings Extra glossary settings.
     * @return array [glossary, student, teacher, deadline]
     */
    private function scenario(int $aggregation, array $settings = []): array {
        global $CFG;
        require_once($CFG->dirroot . '/rating/lib.php');

        $deadline = time() - 5 * DAYSECS;
        [$course, $student, $teacher] = $this->create_course_with_users();
        $glossary = $this->getDataGenerator()->create_module('glossary', [
            'course' => $course->id,
            'assessed' => $aggregation,
            'scale' => 100,
        ] + $settings);
        $this->set_reminder($glossary->cmid, $deadline);
        $this->enable_rule($glossary->cmid);
        return [$glossary, $student, $teacher, $deadline];
    }

    /**
     * Add an entry by the student, created at a given time, through the glossary generator.
     *
     * @param \stdClass $glossary Glossary record.
     * @param \stdClass $student Author.
     * @param int $time Creation time.
     * @param int $approved Whether the entry is approved.
     * @return int Entry ID.
     */
    private function entry(\stdClass $glossary, \stdClass $student, int $time, int $approved = 1): int {
        return (int) $this->getDataGenerator()->get_plugin_generator('mod_glossary')->create_content($glossary, [
            'userid' => $student->id,
            'approved' => $approved,
            'timecreated' => $time,
            'timemodified' => $time,
        ])->id;
    }

    /**
     * Minimum aggregation: the entry with the lowest rating decides, in both orders (F4-12).
     *
     * @return void
     */
    public function test_minimum_aggregation_uses_lowest_rated_entry(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$glossary, $student, $teacher, $deadline] = $this->scenario(RATING_AGGREGATE_MINIMUM);
        $ontime = $this->entry($glossary, $student, $deadline - DAYSECS);
        $this->rate_item($glossary->cmid, 'entry', $ontime, 60, $student->id, $teacher);
        $late = $this->entry($glossary, $student, $deadline + 2 * DAYSECS - 60);
        $this->rate_item($glossary->cmid, 'entry', $late, 90, $student->id, $teacher);
        $this->assertSame(60.0, $this->final_grade('glossary', $glossary->id, $student->id));
        $this->assert_recalculations_keep($glossary, 'glossary', $student->id, 60.0);

        [$glossary, $student, $teacher, $deadline] = $this->scenario(RATING_AGGREGATE_MINIMUM);
        $ontime = $this->entry($glossary, $student, $deadline - DAYSECS);
        $this->rate_item($glossary->cmid, 'entry', $ontime, 90, $student->id, $teacher);
        $late = $this->entry($glossary, $student, $deadline + 2 * DAYSECS - 60);
        $this->rate_item($glossary->cmid, 'entry', $late, 60, $student->id, $teacher);
        $this->assertSame(48.0, $this->final_grade('glossary', $glossary->id, $student->id));
        $this->assert_recalculations_keep($glossary, 'glossary', $student->id, 48.0);
    }

    /**
     * Sum aggregation: the latest rated entry completes the grade (F4-13).
     *
     * @return void
     */
    public function test_sum_aggregation_uses_latest_rated_entry(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$glossary, $student, $teacher, $deadline] = $this->scenario(RATING_AGGREGATE_SUM);

        $ontime = $this->entry($glossary, $student, $deadline - DAYSECS);
        $this->rate_item($glossary->cmid, 'entry', $ontime, 30, $student->id, $teacher);
        $late = $this->entry($glossary, $student, $deadline + 2 * DAYSECS - 60);
        $this->rate_item($glossary->cmid, 'entry', $late, 40, $student->id, $teacher);

        $this->assertSame(56.0, $this->final_grade('glossary', $glossary->id, $student->id));
        $this->assert_recalculations_keep($glossary, 'glossary', $student->id, 56.0);
    }

    /**
     * A late entry is penalised from its creation time (F10-05).
     *
     * @return void
     */
    public function test_late_entry_is_penalised_from_creation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$glossary, $student, $teacher, $deadline] = $this->scenario(RATING_AGGREGATE_AVERAGE);

        $late = $this->entry($glossary, $student, $deadline + 2 * DAYSECS - 60);
        $this->rate_item($glossary->cmid, 'entry', $late, 100, $student->id, $teacher);

        $this->assertSame(80.0, $this->final_grade('glossary', $glossary->id, $student->id));
        $this->assert_recalculations_keep($glossary, 'glossary', $student->id, 80.0);
    }

    /**
     * An entry created on time and edited after the deadline is not late (F10-06, P5).
     *
     * @return void
     */
    public function test_entry_edited_after_deadline_keeps_creation_time(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        // The "Always allow editing" setting: otherwise only the first 30 minutes allow edits.
        [$glossary, $student, $teacher, $deadline] = $this->scenario(RATING_AGGREGATE_AVERAGE, ['editalways' => 1]);

        $entryid = $this->entry($glossary, $student, $deadline - DAYSECS);
        $this->setUser($student);
        \mod_glossary\external\update_entry::execute($entryid, 'Edited concept', 'Edited definition', FORMAT_HTML);
        $this->setAdminUser();
        $this->rate_item($glossary->cmid, 'entry', $entryid, 100, $student->id, $teacher);

        $this->assertSame(100.0, $this->final_grade('glossary', $glossary->id, $student->id));
        $this->assert_recalculations_keep($glossary, 'glossary', $student->id, 100.0);
    }

    /**
     * An entry approved after the deadline keeps its creation time (F10-07).
     *
     * @return void
     */
    public function test_entry_approved_after_deadline_keeps_creation_time(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$glossary, $student, $teacher, $deadline] = $this->scenario(RATING_AGGREGATE_AVERAGE);

        $entryid = $this->entry($glossary, $student, $deadline - DAYSECS, 0);
        // Mirrors mod/glossary/approve.php: only approved and timemodified change.
        $DB->update_record('glossary_entries', (object) ['id' => $entryid, 'approved' => 1, 'timemodified' => time()]);
        $this->rate_item($glossary->cmid, 'entry', $entryid, 100, $student->id, $teacher);

        $this->assertSame(100.0, $this->final_grade('glossary', $glossary->id, $student->id));
        $this->assert_recalculations_keep($glossary, 'glossary', $student->id, 100.0);
    }

    /**
     * Deleting the entry behind the grade leaves the remaining ones to decide (F10-08).
     *
     * @return void
     */
    public function test_deleted_entry_leaves_remaining_entries(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$glossary, $student, $teacher, $deadline] = $this->scenario(RATING_AGGREGATE_MAXIMUM);

        $ontime = $this->entry($glossary, $student, $deadline - DAYSECS);
        $this->rate_item($glossary->cmid, 'entry', $ontime, 80, $student->id, $teacher);
        $late = $this->entry($glossary, $student, $deadline + 2 * DAYSECS - 60);
        $this->rate_item($glossary->cmid, 'entry', $late, 60, $student->id, $teacher);
        $this->assertSame(80.0, $this->final_grade('glossary', $glossary->id, $student->id));

        \mod_glossary\external\delete_entry::execute($ontime);
        // Deleting does not regrade; the module recomputes on its next grade update (e.g. the next rating).
        $this->assertSame(80.0, $this->final_grade('glossary', $glossary->id, $student->id));
        $record = $DB->get_record('glossary', ['id' => $glossary->id]);
        $record->cmidnumber = '';
        glossary_update_grades($record, $student->id);

        $this->assertSame(48.0, $this->final_grade('glossary', $glossary->id, $student->id));
        $this->assert_recalculations_keep($glossary, 'glossary', $student->id, 48.0);
    }

    /**
     * No rating means no grade; removing the rating behind the grade lets the others decide (F4-14).
     *
     * @return void
     */
    public function test_ratings_missing_or_removed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$glossary, $student, $teacher, $deadline] = $this->scenario(RATING_AGGREGATE_MAXIMUM);

        $ontime = $this->entry($glossary, $student, $deadline - DAYSECS);
        $late = $this->entry($glossary, $student, $deadline + 2 * DAYSECS - 60);
        $this->assertNull($this->final_grade('glossary', $glossary->id, $student->id));

        // A second rater keeps a low rating on the on-time entry: core 4.5 emits a PHP
        // deprecation (round(null) in rating/lib.php) when an item loses its last rating.
        $second = $this->getDataGenerator()->create_and_enrol(get_course($glossary->course), 'editingteacher');
        $this->rate_item($glossary->cmid, 'entry', $ontime, 90, $student->id, $teacher);
        $this->rate_item($glossary->cmid, 'entry', $ontime, 20, $student->id, $second);
        $this->rate_item($glossary->cmid, 'entry', $late, 60, $student->id, $teacher);
        $this->assertSame(90.0, $this->final_grade('glossary', $glossary->id, $student->id));

        $this->rate_item($glossary->cmid, 'entry', $ontime, RATING_UNSET_RATING, $student->id, $teacher);

        $this->assertSame(48.0, $this->final_grade('glossary', $glossary->id, $student->id));
        $this->assert_recalculations_keep($glossary, 'glossary', $student->id, 48.0);
    }
}
