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
 * Event observers for the Late Penalty plugin.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\user_graded',
        'callback' => '\local_latepenalty\observer::user_graded',
    ],
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback' => '\local_latepenalty\observer::course_module_deleted',
    ],
    [
        // Before the assignment, quiz and lesson observers delete their group overrides.
        'eventname' => '\core\event\group_deleted',
        'callback' => '\local_latepenalty\observer::group_deleted',
        'priority' => 100,
    ],
    [
        'eventname' => '\core\event\group_member_added',
        'callback' => '\local_latepenalty\observer::group_member_changed',
    ],
    [
        'eventname' => '\core\event\group_member_removed',
        'callback' => '\local_latepenalty\observer::group_member_changed',
    ],
    [
        'eventname' => '\core\event\course_reset_started',
        'callback' => '\local_latepenalty\observer::course_reset_started',
    ],
    [
        'eventname' => '\core\event\course_reset_ended',
        'callback' => '\local_latepenalty\observer::course_reset_ended',
    ],
    // Overrides and extensions of the activities themselves change a student's deadline.
    // Optional integration: these modules are not dependencies; without them the observers
    // never fire (see SCOPE_FEATURE.md, DA9).
    [
        'eventname' => '\mod_assign\event\user_override_created',
        'callback' => '\local_latepenalty\observer::activity_deadline_changed',
    ],
    [
        'eventname' => '\mod_assign\event\user_override_updated',
        'callback' => '\local_latepenalty\observer::activity_deadline_changed',
    ],
    [
        'eventname' => '\mod_assign\event\user_override_deleted',
        'callback' => '\local_latepenalty\observer::activity_deadline_changed',
    ],
    [
        'eventname' => '\mod_assign\event\group_override_created',
        'callback' => '\local_latepenalty\observer::activity_deadline_changed',
    ],
    [
        'eventname' => '\mod_assign\event\group_override_updated',
        'callback' => '\local_latepenalty\observer::activity_deadline_changed',
    ],
    [
        'eventname' => '\mod_assign\event\group_override_deleted',
        'callback' => '\local_latepenalty\observer::activity_deadline_changed',
    ],
    [
        'eventname' => '\mod_assign\event\extension_granted',
        'callback' => '\local_latepenalty\observer::activity_deadline_changed',
    ],
    [
        'eventname' => '\mod_lesson\event\user_override_created',
        'callback' => '\local_latepenalty\observer::activity_deadline_changed',
    ],
    [
        'eventname' => '\mod_lesson\event\user_override_updated',
        'callback' => '\local_latepenalty\observer::activity_deadline_changed',
    ],
    [
        'eventname' => '\mod_lesson\event\user_override_deleted',
        'callback' => '\local_latepenalty\observer::activity_deadline_changed',
    ],
    [
        'eventname' => '\mod_lesson\event\group_override_created',
        'callback' => '\local_latepenalty\observer::activity_deadline_changed',
    ],
    [
        'eventname' => '\mod_lesson\event\group_override_updated',
        'callback' => '\local_latepenalty\observer::activity_deadline_changed',
    ],
    [
        'eventname' => '\mod_lesson\event\group_override_deleted',
        'callback' => '\local_latepenalty\observer::activity_deadline_changed',
    ],
    [
        'eventname' => '\mod_quiz\event\user_override_created',
        'callback' => '\local_latepenalty\observer::activity_deadline_changed',
    ],
    [
        'eventname' => '\mod_quiz\event\user_override_updated',
        'callback' => '\local_latepenalty\observer::activity_deadline_changed',
    ],
    [
        'eventname' => '\mod_quiz\event\user_override_deleted',
        'callback' => '\local_latepenalty\observer::activity_deadline_changed',
    ],
    [
        'eventname' => '\mod_quiz\event\group_override_created',
        'callback' => '\local_latepenalty\observer::activity_deadline_changed',
    ],
    [
        'eventname' => '\mod_quiz\event\group_override_updated',
        'callback' => '\local_latepenalty\observer::activity_deadline_changed',
    ],
    [
        'eventname' => '\mod_quiz\event\group_override_deleted',
        'callback' => '\local_latepenalty\observer::activity_deadline_changed',
    ],
];
