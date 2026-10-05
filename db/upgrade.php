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

    if ($oldversion < 2026100100) {
        // Option to keep the best penalised grade among attempts (modules with no readable grading method).
        $table = new xmldb_table('local_latepenalty_rules');
        $field = new xmldb_field('keepbest', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'last_deadline');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // The reprocess_grades task only looks at grade history written from now on.
        set_config('reprocesscursor', (int) $DB->get_field_sql('SELECT MAX(id) FROM {grade_grades_history}'), 'local_latepenalty');

        upgrade_plugin_savepoint(true, 2026100100, 'local', 'latepenalty');
    }

    if ($oldversion < 2026100501) {
        // When each rule was first enabled: grades that arrived earlier are left alone (F19).
        $table = new xmldb_table('local_latepenalty_rules');
        $field = new xmldb_field('timeenabled', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'keepbest');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // The real date was never stored. Where the plugin penalised, its first penalty is the closest
        // to it; a rule that is on but never penalised protects every grade that exists today. One
        // statement for the whole table, as core upgrade steps do; rules left at 0 count as never enabled.
        $DB->execute(
            "UPDATE {local_latepenalty_rules}
                SET timeenabled = (SELECT MIN(h.timemodified)
                                     FROM {course_modules} cm
                                     JOIN {modules} m ON m.id = cm.module
                                     JOIN {grade_items} gi ON gi.itemtype = 'mod'
                                                          AND gi.itemmodule = m.name
                                                          AND gi.iteminstance = cm.instance
                                                          AND gi.courseid = cm.course
                                     JOIN {grade_grades_history} h ON h.itemid = gi.id
                                    WHERE cm.id = {local_latepenalty_rules}.cmid
                                      AND h.source = :source)
              WHERE timeenabled = 0
                AND EXISTS (SELECT 1
                              FROM {course_modules} cm
                              JOIN {modules} m ON m.id = cm.module
                              JOIN {grade_items} gi ON gi.itemtype = 'mod'
                                                   AND gi.itemmodule = m.name
                                                   AND gi.iteminstance = cm.instance
                                                   AND gi.courseid = cm.course
                              JOIN {grade_grades_history} h ON h.itemid = gi.id
                             WHERE cm.id = {local_latepenalty_rules}.cmid
                               AND h.source = :source2)",
            ['source' => 'local_latepenalty', 'source2' => 'local_latepenalty']
        );
        $DB->set_field_select('local_latepenalty_rules', 'timeenabled', time(), 'enabled = 1 AND timeenabled = 0');

        upgrade_plugin_savepoint(true, 2026100501, 'local', 'latepenalty');
    }

    return true;
}
