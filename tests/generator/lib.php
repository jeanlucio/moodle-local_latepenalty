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
 * Data generator for local_latepenalty (shared by PHPUnit and Behat).
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Creates penalty rules and per-user / per-group overrides.
 */
class local_latepenalty_generator extends component_generator_base {
    /**
     * Create or replace the penalty rule of a course module.
     *
     * Creating an activity already inserts a disabled rule through
     * local_latepenalty_coursemodule_edit_post_actions(), so this updates that
     * row when it exists instead of inserting a duplicate.
     *
     * @param array $record Must contain cmid; optional enabled, daily_penalty, max_penalty,
     *                      recalc_on_deadline, recalc_on_rate, last_deadline.
     * @return stdClass The stored rule.
     */
    public function create_rule(array $record): stdClass {
        global $DB;

        if (empty($record['cmid'])) {
            throw new coding_exception('create_rule() requires a cmid.');
        }

        $rule = (object) array_merge([
            'enabled' => 1,
            'daily_penalty' => 10.0,
            'max_penalty' => 50.0,
            'recalc_on_deadline' => 1,
            'recalc_on_rate' => 1,
            'last_deadline' => 0,
        ], $record);

        $existing = $DB->get_record('local_latepenalty_rules', ['cmid' => $rule->cmid]);
        if ($existing) {
            $rule->id = $existing->id;
            $DB->update_record('local_latepenalty_rules', $rule);
        } else {
            $rule->id = $DB->insert_record('local_latepenalty_rules', $rule);
        }

        return $DB->get_record('local_latepenalty_rules', ['id' => $rule->id], '*', MUST_EXIST);
    }

    /**
     * Create a per-user override.
     *
     * @param array $record Must contain cmid and userid; optional deadline, daily_penalty, max_penalty.
     * @return stdClass The stored override.
     */
    public function create_override(array $record): stdClass {
        global $DB;

        if (empty($record['cmid']) || empty($record['userid'])) {
            throw new coding_exception('create_override() requires cmid and userid.');
        }

        $now = time();
        $override = (object) array_merge([
            'deadline' => null,
            'daily_penalty' => null,
            'max_penalty' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ], $record);
        $override->id = $DB->insert_record('local_latepenalty_overrides', $override);

        return $override;
    }

    /**
     * Create a per-group override.
     *
     * @param array $record Must contain cmid and groupid; optional deadline, daily_penalty, max_penalty.
     * @return stdClass The stored override.
     */
    public function create_group_override(array $record): stdClass {
        global $DB;

        if (empty($record['cmid']) || empty($record['groupid'])) {
            throw new coding_exception('create_group_override() requires cmid and groupid.');
        }

        $now = time();
        $override = (object) array_merge([
            'deadline' => null,
            'daily_penalty' => null,
            'max_penalty' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ], $record);
        $override->id = $DB->insert_record('local_latepenalty_group_overrides', $override);

        return $override;
    }
}
