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
 * Backup support for the local_latepenalty plugin.
 *
 * Moodle's backup framework auto-discovers this class and calls
 * define_module_plugin_structure() when any course module is backed up,
 * so the penalty rule travels with the activity on restore or duplicate.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Backup plugin class that attaches the penalty rule to its parent module node.
 */
class backup_local_latepenalty_plugin extends backup_local_plugin {
    /**
     * Returns the backup sub-tree to add inside the module's backup node.
     *
     * The rule record is fetched from local_latepenalty_rules using the
     * current course_modules.id (backup::VAR_MODID).  The cmid column is
     * intentionally excluded from the exported fields because it is
     * reconstructed from context on restore.
     *
     * @return backup_nested_element
     */
    protected function define_module_plugin_structure(): backup_nested_element {
        $plugin = $this->get_plugin_element();

        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);

        $rule = new backup_nested_element('rule', ['id'], [
            'enabled',
            'daily_penalty',
            'max_penalty',
            'recalc_on_deadline',
            'recalc_on_rate',
        ]);
        $wrapper->add_child($rule);

        $overrides = new backup_nested_element('overrides');
        $override  = new backup_nested_element('override', ['id'], [
            'userid',
            'deadline',
            'daily_penalty',
            'max_penalty',
            'timecreated',
            'timemodified',
        ]);
        $wrapper->add_child($overrides);
        $overrides->add_child($override);

        // Backup::VAR_MODID is resolved to the course_modules.id at backup time.
        $rule->set_source_table('local_latepenalty_rules', ['cmid' => backup::VAR_MODID]);
        $override->set_source_table('local_latepenalty_overrides', ['cmid' => backup::VAR_MODID]);

        // Annotate userid so the restore framework can remap user IDs.
        $override->annotate_ids('user', 'userid');

        return $plugin;
    }
}
