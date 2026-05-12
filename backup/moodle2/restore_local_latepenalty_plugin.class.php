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
 * Restore support for the local_latepenalty plugin.
 *
 * Moodle's restore framework auto-discovers this class and calls
 * define_module_plugin_structure() when any course module is restored,
 * so the penalty rule is recreated with the new course_modules.id.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restore plugin class that reads the penalty rule from the backup XML.
 */
class restore_local_latepenalty_plugin extends restore_local_plugin {
    /**
     * Returns the XML paths to process, relative to the module node.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure(): array {
        return [
            new restore_path_element(
                'local_latepenalty_rule',
                $this->get_pathfor('/rule')
            ),
        ];
    }

    /**
     * Process one rule record from the backup XML.
     *
     * Replaces the backed-up cmid with the newly assigned one from the
     * restore task and upserts the record.  An existing row may already be
     * present because local_latepenalty_coursemodule_edit_post_actions()
     * runs during module creation and inserts a disabled rule; in that case
     * the backed-up values simply overwrite it.
     *
     * @param array $data Raw element data from the XML.
     * @return void
     */
    public function process_local_latepenalty_rule(array $data): void {
        global $DB;

        $data = (object) $data;

        // Replace the original cmid with the new one created during restore.
        $data->cmid = $this->task->get_moduleid();

        $existing = $DB->get_record('local_latepenalty_rules', ['cmid' => $data->cmid]);
        if ($existing) {
            $existing->enabled       = $data->enabled;
            $existing->daily_penalty = $data->daily_penalty;
            $existing->max_penalty   = $data->max_penalty;
            $DB->update_record('local_latepenalty_rules', $existing);
        } else {
            unset($data->id);
            $DB->insert_record('local_latepenalty_rules', $data);
        }
    }
}
