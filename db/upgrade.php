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
 * Upgrade script for the Late Penalty plugin.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute upgrade steps for the plugin.
 *
 * @param int $oldversion The old version of the plugin.
 * @return bool Always returns true.
 */
function xmldb_local_latepenalty_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026051800) {
        $table = new xmldb_table('local_latepenalty_rules');

        $field = new xmldb_field('recalc_on_deadline', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'max_penalty');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('recalc_on_rate', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'recalc_on_deadline');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('last_deadline', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'recalc_on_rate');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026051800, 'local', 'latepenalty');
    }

    if ($oldversion < 2026051900) {
        $table = new xmldb_table('local_latepenalty_overrides');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('deadline', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('daily_penalty', XMLDB_TYPE_NUMBER, '5', null, null, null, null, null, '2');
            $table->add_field('max_penalty', XMLDB_TYPE_NUMBER, '5', null, null, null, null, null, '2');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('cmid_userid', XMLDB_KEY_UNIQUE, ['cmid', 'userid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026051900, 'local', 'latepenalty');
    }

    return true;
}
