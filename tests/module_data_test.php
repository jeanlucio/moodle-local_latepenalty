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
 * Late penalties on rated databases, driven through the database and rating APIs.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\observer
 * @covers \local_latepenalty\recalculator
 * @covers \local_latepenalty\penalty_helper
 */
final class module_data_test extends latepenalty_testcase {
    /**
     * A record created on time and rated after the deadline is not penalised (F10-04).
     *
     * @return void
     */
    public function test_record_created_on_time_and_rated_late_is_not_penalised(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/rating/lib.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        $deadline = time() - 5 * DAYSECS;
        [$course, $student, $teacher] = $this->create_course_with_users();
        $data = $this->getDataGenerator()->create_module('data', [
            'course' => $course->id,
            'assessed' => RATING_AGGREGATE_AVERAGE,
            'scale' => 100,
        ]);
        $this->set_reminder($data->cmid, $deadline);
        $this->enable_rule($data->cmid);

        /** @var \mod_data_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_data');
        $field = $generator->create_field((object) ['type' => 'text', 'name' => 'answer'], $data);
        $recordid = $generator->create_entry($data, [$field->field->id => 'Answer'], 0, [], null, $student->id);

        // Data_add_record() always stamps time(), so the creation time is moved back to the
        // moment the student would have saved the record.
        $DB->set_field('data_records', 'timecreated', $deadline - DAYSECS, ['id' => $recordid]);
        $DB->set_field('data_records', 'timemodified', $deadline - DAYSECS, ['id' => $recordid]);

        $this->rate_item($data->cmid, 'entry', $recordid, 100, $student->id, $teacher);
        $this->assertSame(100.0, $this->final_grade('data', $data->id, $student->id));

        recalculator::recalculate_for_student($data->cmid, $student->id, 10.0, 50.0);

        $this->assertSame(100.0, $this->final_grade('data', $data->id, $student->id));
    }

    /**
     * Create a rated database with a text field and a rule, the reminder 5 days ago.
     *
     * @param int $aggregation Rating aggregation (RATING_AGGREGATE_*).
     * @return array [data, field ID, student, teacher, deadline]
     */
    private function scenario(int $aggregation): array {
        global $CFG;
        require_once($CFG->dirroot . '/rating/lib.php');

        $deadline = time() - 5 * DAYSECS;
        [$course, $student, $teacher] = $this->create_course_with_users();
        $data = $this->getDataGenerator()->create_module('data', [
            'course' => $course->id,
            'assessed' => $aggregation,
            'scale' => 100,
        ]);
        $this->set_reminder($data->cmid, $deadline);
        $this->enable_rule($data->cmid);
        $field = $this->getDataGenerator()->get_plugin_generator('mod_data')
            ->create_field((object) ['type' => 'text', 'name' => 'answer'], $data);
        return [$data, (int) $field->field->id, $student, $teacher, $deadline];
    }

    /**
     * Add a record by the student, created at a given time.
     *
     * Data_add_record() always stamps time(), so the creation time is moved back
     * to the moment the student would have saved the record.
     *
     * @param \stdClass $data Database record.
     * @param int $fieldid Text field ID.
     * @param \stdClass $student Author.
     * @param int $time Creation time.
     * @return int Record ID.
     */
    private function record(\stdClass $data, int $fieldid, \stdClass $student, int $time): int {
        global $DB;

        $recordid = $this->getDataGenerator()->get_plugin_generator('mod_data')
            ->create_entry($data, [$fieldid => 'Answer'], 0, [], null, $student->id);
        $DB->update_record('data_records', (object) ['id' => $recordid, 'timecreated' => $time, 'timemodified' => $time]);
        return $recordid;
    }

    /**
     * Average aggregation: the latest rated record completes the grade (F4-13).
     *
     * @return void
     */
    public function test_average_aggregation_uses_latest_rated_record(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$data, $fieldid, $student, $teacher, $deadline] = $this->scenario(RATING_AGGREGATE_AVERAGE);

        $ontime = $this->record($data, $fieldid, $student, $deadline - DAYSECS);
        $this->rate_item($data->cmid, 'entry', $ontime, 80, $student->id, $teacher);
        $late = $this->record($data, $fieldid, $student, $deadline + 2 * DAYSECS - 60);
        $this->rate_item($data->cmid, 'entry', $late, 60, $student->id, $teacher);

        $this->assertSame(56.0, $this->final_grade('data', $data->id, $student->id));
        $this->assert_recalculations_keep($data, 'data', $student->id, 56.0);
    }

    /**
     * A late record is penalised from its creation time (F10-05).
     *
     * @return void
     */
    public function test_late_record_is_penalised_from_creation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$data, $fieldid, $student, $teacher, $deadline] = $this->scenario(RATING_AGGREGATE_AVERAGE);

        $late = $this->record($data, $fieldid, $student, $deadline + 2 * DAYSECS - 60);
        $this->rate_item($data->cmid, 'entry', $late, 100, $student->id, $teacher);

        $this->assertSame(80.0, $this->final_grade('data', $data->id, $student->id));
        $this->assert_recalculations_keep($data, 'data', $student->id, 80.0);
    }

    /**
     * A record created on time and edited after the deadline is not late (F10-06, P5).
     *
     * @return void
     */
    public function test_record_edited_after_deadline_keeps_creation_time(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$data, $fieldid, $student, $teacher, $deadline] = $this->scenario(RATING_AGGREGATE_AVERAGE);

        $recordid = $this->record($data, $fieldid, $student, $deadline - DAYSECS);
        $this->setUser($student);
        \mod_data_external::update_entry($recordid, [['fieldid' => $fieldid, 'value' => json_encode('Edited answer')]]);
        $this->setAdminUser();
        $this->rate_item($data->cmid, 'entry', $recordid, 100, $student->id, $teacher);

        $this->assertSame(100.0, $this->final_grade('data', $data->id, $student->id));
        $this->assert_recalculations_keep($data, 'data', $student->id, 100.0);
    }

    /**
     * Deleting the record behind the grade leaves the remaining ones to decide (F10-08).
     *
     * @return void
     */
    public function test_deleted_record_leaves_remaining_records(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$data, $fieldid, $student, $teacher, $deadline] = $this->scenario(RATING_AGGREGATE_MAXIMUM);

        $ontime = $this->record($data, $fieldid, $student, $deadline - DAYSECS);
        $this->rate_item($data->cmid, 'entry', $ontime, 80, $student->id, $teacher);
        $late = $this->record($data, $fieldid, $student, $deadline + 2 * DAYSECS - 60);
        $this->rate_item($data->cmid, 'entry', $late, 60, $student->id, $teacher);
        $this->assertSame(80.0, $this->final_grade('data', $data->id, $student->id));

        $record = $DB->get_record('data', ['id' => $data->id]);
        data_delete_record($ontime, $record, $data->course, $data->cmid);
        // Deleting does not regrade; the module recomputes on its next grade update (e.g. the next rating).
        $record->cmidnumber = '';
        data_update_grades($record, $student->id);

        $this->assertSame(48.0, $this->final_grade('data', $data->id, $student->id));
        $this->assert_recalculations_keep($data, 'data', $student->id, 48.0);
    }
}
