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

namespace local_latepenalty\task;

use local_latepenalty\local\penalty_writer;
use local_latepenalty\recalculator;

/**
 * Picks up penalised grades whose raw grade changed later (override storage path only).
 *
 * Where penalties are stored as an overridden final grade, a better attempt
 * graded later only changes the raw grade: the core fires no event for it, so
 * the grade would stay stuck at the old penalised value. This task walks the
 * grade history since its last run (a cursor on grade_grades_history.id) and
 * recalculates the students of activities with an enabled rule. The engine
 * leaves alone every grade it must not touch (teacher edits, locks), and
 * recalculating an unchanged grade writes nothing, so a run is idempotent.
 *
 * Where penalties are stored as a deducted mark the core reports every new
 * raw grade, so the task only keeps its cursor current.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reprocess_grades extends \core\task\scheduled_task {
    /** @var string Config key of the cursor (last grade history ID seen). */
    public const CURSOR = 'reprocesscursor';

    /** @var int Grade history rows read per batch. */
    private const BATCH = 500;

    /** @var int Batches per run; the rest waits for the next run. */
    private const MAXBATCHES = 20;

    #[\Override]
    public function get_name(): string {
        return get_string('task_reprocess_grades', 'local_latepenalty');
    }

    #[\Override]
    public function execute(): void {
        global $DB;

        if (penalty_writer::uses_deducted_mark() && !self::has_frozen_courses()) {
            set_config(self::CURSOR, (int) $DB->get_field_sql('SELECT MAX(id) FROM {grade_grades_history}'), 'local_latepenalty');
            return;
        }

        $cursor = (int) get_config('local_latepenalty', self::CURSOR);
        for ($batch = 0; $batch < self::MAXBATCHES; $batch++) {
            $rows = $DB->get_records_sql(
                "SELECT h.id, h.itemid, h.userid
                   FROM {grade_grades_history} h
                  WHERE h.id > :cursor
                    AND (h.source IS NULL OR h.source <> :source)
               ORDER BY h.id",
                ['cursor' => $cursor, 'source' => penalty_writer::SOURCE],
                0,
                self::BATCH
            );
            if (empty($rows)) {
                break;
            }

            $usersbyitem = [];
            foreach ($rows as $row) {
                $usersbyitem[(int) $row->itemid][(int) $row->userid] = (int) $row->userid;
                $cursor = (int) $row->id;
            }
            $this->process($usersbyitem);
            set_config(self::CURSOR, $cursor, 'local_latepenalty');

            if (count($rows) < self::BATCH) {
                break;
            }
        }
    }

    /**
     * Whether any course has a gradebook frozen at a calculation version that ignores deducted marks.
     *
     * @return bool
     */
    private static function has_frozen_courses(): bool {
        global $DB;

        $names = $DB->get_records_select_menu(
            'config',
            $DB->sql_like('name', ':prefix'),
            ['prefix' => 'gradebook_calculations_freeze_%'],
            '',
            'id, name'
        );
        foreach ($names as $name) {
            $courseid = (int) substr($name, strlen('gradebook_calculations_freeze_'));
            if (!penalty_writer::uses_deducted_mark($courseid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Recalculate the students of the changed grade items that belong to activities with an enabled rule.
     *
     * @param array $usersbyitem User IDs keyed by grade item ID.
     * @return void
     */
    private function process(array $usersbyitem): void {
        global $DB;

        [$isql, $iparams] = $DB->get_in_or_equal(array_keys($usersbyitem), SQL_PARAMS_NAMED, 'item');
        $items = $DB->get_records_sql(
            "SELECT gi.id, gi.courseid, cm.id AS cmid, r.daily_penalty, r.max_penalty
               FROM {grade_items} gi
               JOIN {modules} m ON m.name = gi.itemmodule
               JOIN {course_modules} cm ON cm.instance = gi.iteminstance
                                       AND cm.module = m.id
                                       AND cm.course = gi.courseid
               JOIN {local_latepenalty_rules} r ON r.cmid = cm.id AND r.enabled = 1
              WHERE gi.id $isql
                AND gi.itemtype = 'mod'",
            $iparams
        );

        $users = [];
        $rates = [];
        foreach ($items as $item) {
            if (penalty_writer::uses_deducted_mark((int) $item->courseid)) {
                // The core reports every new raw grade in these courses.
                continue;
            }
            $cmid = (int) $item->cmid;
            $users[$cmid] = ($users[$cmid] ?? []) + $usersbyitem[(int) $item->id];
            $rates[$cmid] = [(float) $item->daily_penalty, (float) $item->max_penalty];
        }
        foreach ($users as $cmid => $userids) {
            [$daily, $max] = $rates[$cmid];
            recalculator::recalculate_users($cmid, array_values($userids), $daily, $max);
        }
    }
}
