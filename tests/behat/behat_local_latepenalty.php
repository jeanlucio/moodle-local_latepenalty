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
 * Behat steps of local_latepenalty.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Moodle\BehatExtension\Exception\SkippedException;

/**
 * Steps that skip a scenario where a core feature Late Penalty relies on does not exist.
 */
class behat_local_latepenalty extends behat_base {
    /**
     * Skip the scenario unless this Moodle has the quiz due date (Moodle 5.3+).
     *
     * @Given /^the quiz due date is available to Late Penalty$/
     * @return void
     */
    public function the_quiz_due_date_is_available_to_late_penalty(): void {
        global $DB;

        if (!$DB->get_manager()->field_exists('quiz', 'duedate')) {
            throw new SkippedException('The quiz due date exists only from Moodle 5.3.');
        }
    }

    /**
     * Turn on the core assignment grade penalty, or skip the scenario before Moodle 5.0.
     *
     * No core penalty rule is added, so the core itself deducts nothing: the
     * scenario only checks that Late Penalty steps aside.
     *
     * @Given /^the core assignment penalty is enabled for Late Penalty$/
     * @return void
     */
    public function the_core_assignment_penalty_is_enabled_for_late_penalty(): void {
        if (!class_exists(\mod_assign\penalty\helper::class)) {
            throw new SkippedException('The core assignment penalty exists only from Moodle 5.0.');
        }
        \core_grades\penalty_manager::enable_module('assign');
    }

    /**
     * Skip the scenario unless Late Penalty stores penalties as deducted marks (core with MDL-88407).
     *
     * @Given /^Late Penalty stores penalties as deducted marks$/
     * @return void
     */
    public function late_penalty_stores_penalties_as_deducted_marks(): void {
        if (!\local_latepenalty\local\penalty_writer::uses_deducted_mark()) {
            throw new SkippedException('This core has no MDL-88407 fix: penalties are stored as overrides.');
        }
    }
}
