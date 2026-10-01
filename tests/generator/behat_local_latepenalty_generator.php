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
 * Behat data generator for local_latepenalty.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Lets Behat create Late Penalty rules and overrides with the "the following ... exist" step.
 */
class behat_local_latepenalty_generator extends behat_generator_base {
    #[\Override]
    protected function get_creatable_entities(): array {
        return [
            'rules' => [
                'singular' => 'rule',
                'datagenerator' => 'rule',
                'required' => ['activity'],
                'switchids' => ['activity' => 'cmid'],
            ],
            'overrides' => [
                'singular' => 'override',
                'datagenerator' => 'override',
                'required' => ['activity', 'user'],
                'switchids' => ['activity' => 'cmid', 'user' => 'userid'],
            ],
            'group overrides' => [
                'singular' => 'group override',
                'datagenerator' => 'group_override',
                'required' => ['activity', 'group'],
                'switchids' => ['activity' => 'cmid', 'group' => 'groupid'],
            ],
        ];
    }
}
