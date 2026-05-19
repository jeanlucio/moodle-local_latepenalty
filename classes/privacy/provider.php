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
 * Privacy provider for the Late Penalty plugin.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for local_latepenalty.
 *
 * The plugin stores per-student penalty overrides in local_latepenalty_overrides,
 * keyed by userid and course module. This constitutes personal data and requires
 * full export and deletion support.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_latepenalty_overrides',
            [
                'userid'        => 'privacy:metadata:local_latepenalty_overrides:userid',
                'cmid'          => 'privacy:metadata:local_latepenalty_overrides:cmid',
                'deadline'      => 'privacy:metadata:local_latepenalty_overrides:deadline',
                'daily_penalty' => 'privacy:metadata:local_latepenalty_overrides:daily_penalty',
                'max_penalty'   => 'privacy:metadata:local_latepenalty_overrides:max_penalty',
                'timecreated'   => 'privacy:metadata:local_latepenalty_overrides:timecreated',
                'timemodified'  => 'privacy:metadata:local_latepenalty_overrides:timemodified',
            ],
            'privacy:metadata:local_latepenalty_overrides'
        );
        return $collection;
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {local_latepenalty_overrides} lo
               JOIN {course_modules} cm ON cm.id = lo.cmid
               JOIN {context} ctx ON ctx.instanceid = cm.id
                                 AND ctx.contextlevel = :contextlevel
              WHERE lo.userid = :userid",
            ['userid' => $userid, 'contextlevel' => CONTEXT_MODULE]
        );
        return $contextlist;
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $userlist->add_from_sql(
            'userid',
            "SELECT lo.userid
               FROM {local_latepenalty_overrides} lo
              WHERE lo.cmid = :cmid",
            ['cmid' => $context->instanceid]
        );
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $record = $DB->get_record(
                'local_latepenalty_overrides',
                ['cmid' => $context->instanceid, 'userid' => $userid]
            );
            if (!$record) {
                continue;
            }
            writer::with_context($context)->export_data(
                [get_string('overrides', 'local_latepenalty')],
                (object) [
                    'deadline'      => $record->deadline !== null ? userdate((int) $record->deadline) : null,
                    'daily_penalty' => $record->daily_penalty,
                    'max_penalty'   => $record->max_penalty,
                    'timecreated'   => userdate((int) $record->timecreated),
                    'timemodified'  => userdate((int) $record->timemodified),
                ]
            );
        }
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }
        $DB->delete_records('local_latepenalty_overrides', ['cmid' => $context->instanceid]);
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $DB->delete_records(
                'local_latepenalty_overrides',
                ['cmid' => $context->instanceid, 'userid' => $userid]
            );
        }
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $inparams['cmid'] = $context->instanceid;
        $DB->delete_records_select(
            'local_latepenalty_overrides',
            "cmid = :cmid AND userid $insql",
            $inparams
        );
    }
}
