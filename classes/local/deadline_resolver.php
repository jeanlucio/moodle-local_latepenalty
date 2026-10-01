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

namespace local_latepenalty\local;

/**
 * The single deadline chain used by every part of the plugin.
 *
 * The first step that is set wins:
 *  1. Late Penalty override for the student.
 *  2. Late Penalty override for one of the student's groups (most lenient per field).
 *  3. The activity's own override or extension for the student, read the way
 *     the activity itself reads it:
 *     - assign: extension, then the student override, else the group override
 *       with the highest priority (sortorder); a student override without a
 *       due date falls back to the assignment's due date, as in core;
 *     - quiz: the override due date (Moodle 5.3+), student field first, else the
 *       groups' (any 0 wins, else the latest); 0 removes the due date. Without an
 *       effective due date, the override close date (legacy behaviour);
 *     - lesson: the override deadline, student first, else the groups'.
 *  4. The activity's due date (assign, forum, and quiz from Moodle 5.3).
 *  5. The "Set reminder in Timeline" date (completionexpected).
 *
 * Workshop submission end, quiz close and lesson deadline are hard closing
 * dates rather than due dates, so the activity values are not part of the
 * chain (only per-student overrides of quiz and lesson are, see step 3).
 *
 * All lookups are bulk: the number of queries depends on the number of module
 * types involved, never on the number of students or activities.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class deadline_resolver {
    /** @var string No deadline anywhere in the chain. */
    public const ORIGIN_NONE = 'none';

    /** @var string Late Penalty override for the student. */
    public const ORIGIN_PLUGIN_USER = 'plugin_user';

    /** @var string Late Penalty override for a group of the student. */
    public const ORIGIN_PLUGIN_GROUP = 'plugin_group';

    /** @var string Assignment extension. */
    public const ORIGIN_EXTENSION = 'extension';

    /** @var string The activity's own student or group override (due date or lesson deadline). */
    public const ORIGIN_ACTIVITY_OVERRIDE = 'activity_override';

    /** @var string Close date of a quiz override, used only when the quiz has no due date. */
    public const ORIGIN_ACTIVITY_CLOSE = 'activity_close';

    /** @var string The activity's due date. */
    public const ORIGIN_DUEDATE = 'duedate';

    /** @var string The "Set reminder in Timeline" date. */
    public const ORIGIN_REMINDER = 'reminder';

    /** @var bool|null Whether quiz.duedate exists (Moodle 5.3+), cached per request. */
    private static ?bool $quizhasduedate = null;

    /**
     * Whether this Moodle has the quiz due date (MDL-82521, Moodle 5.3).
     *
     * Detected from the schema rather than the version number, which would be
     * wrong on betas and backports.
     *
     * @return bool
     */
    public static function quiz_has_duedate(): bool {
        global $DB;

        if (self::$quizhasduedate === null) {
            self::$quizhasduedate = $DB->get_manager()->field_exists('quiz', 'duedate');
        }
        return self::$quizhasduedate;
    }

    /**
     * Forget cached schema facts (tests only).
     *
     * @return void
     */
    public static function reset_caches(): void {
        self::$quizhasduedate = null;
    }

    /**
     * Due date column of a module, or null when the module has no due date.
     *
     * @param string $modname Module name.
     * @return string|null
     */
    public static function duedate_field(string $modname): ?string {
        switch ($modname) {
            case 'assign':
            case 'forum':
                return 'duedate';
            case 'quiz':
                return self::quiz_has_duedate() ? 'duedate' : null;
            default:
                return null;
        }
    }

    /**
     * Activity-level deadline (steps 4 and 5), ignoring every override.
     *
     * @param \stdClass $cm Course module (id, modname, instance, completionexpected).
     * @return deadline
     */
    public static function activity_deadline(\stdClass $cm): deadline {
        return self::activity_deadlines([$cm])[(int) $cm->id];
    }

    /**
     * Activity-level deadlines (steps 4 and 5) of several course modules.
     *
     * @param \stdClass[] $cms Course modules (id, modname, instance, completionexpected).
     * @return deadline[] Keyed by course module ID.
     */
    public static function activity_deadlines(array $cms): array {
        $duedates = self::load_duedates($cms);

        $result = [];
        foreach ($cms as $cm) {
            $cmid = (int) $cm->id;
            $duedate = $duedates[$cm->modname][(int) $cm->instance] ?? 0;
            if ($duedate > 0) {
                $result[$cmid] = new deadline($duedate, self::ORIGIN_DUEDATE);
            } else if (!empty($cm->completionexpected)) {
                $result[$cmid] = new deadline((int) $cm->completionexpected, self::ORIGIN_REMINDER);
            } else {
                $result[$cmid] = new deadline(0, self::ORIGIN_NONE);
            }
        }
        return $result;
    }

    /**
     * Deadline of one student in one activity.
     *
     * @param \stdClass $cm Course module (id, modname, instance, completionexpected).
     * @param int $userid Student ID.
     * @return deadline
     */
    public static function for_user(\stdClass $cm, int $userid): deadline {
        return self::resolve([$cm], [$userid])[(int) $cm->id][$userid];
    }

    /**
     * Deadlines of several students in one activity.
     *
     * @param \stdClass $cm Course module (id, modname, instance, completionexpected).
     * @param int[] $userids Student IDs.
     * @return deadline[] Keyed by user ID.
     */
    public static function for_users(\stdClass $cm, array $userids): array {
        return self::resolve([$cm], $userids)[(int) $cm->id];
    }

    /**
     * Deadlines of one student in several activities.
     *
     * @param \stdClass[] $cms Course modules (id, modname, instance, completionexpected).
     * @param int $userid Student ID.
     * @return deadline[] Keyed by course module ID.
     */
    public static function for_user_in_cms(array $cms, int $userid): array {
        $result = [];
        foreach (self::resolve($cms, [$userid]) as $cmid => $byuser) {
            $result[$cmid] = $byuser[$userid];
        }
        return $result;
    }

    /**
     * Deadlines of several students in several activities.
     *
     * @param \stdClass[] $cms Course modules (id, modname, instance, completionexpected).
     * @param int[] $userids Student IDs.
     * @return array Deadline objects keyed by course module ID, then user ID.
     */
    public static function resolve(array $cms, array $userids): array {
        $cmsbyid = [];
        foreach ($cms as $cm) {
            $cmsbyid[(int) $cm->id] = $cm;
        }
        $userids = array_values(array_unique(array_map('intval', $userids)));

        $result = array_fill_keys(array_keys($cmsbyid), []);
        if (empty($cmsbyid) || empty($userids)) {
            return $result;
        }

        $bases = self::activity_deadlines($cmsbyid);
        $pluginusers = self::load_plugin_user_overrides(array_keys($cmsbyid), $userids);
        $plugingroups = self::load_plugin_group_overrides(array_keys($cmsbyid), $userids);
        $activity = self::load_activity_overrides($cmsbyid, $userids);

        foreach ($cmsbyid as $cmid => $cm) {
            foreach ($userids as $userid) {
                $user = $pluginusers[$cmid][$userid] ?? null;
                $group = $plugingroups[$cmid][$userid] ?? null;
                $daily = self::first_float($user->daily_penalty ?? null, $group->daily_penalty ?? null);
                $max = self::first_float($user->max_penalty ?? null, $group->max_penalty ?? null);

                if ($user !== null && $user->deadline !== null) {
                    $time = (int) $user->deadline;
                    $origin = self::ORIGIN_PLUGIN_USER;
                } else if ($group !== null && $group->deadline !== null) {
                    $time = (int) $group->deadline;
                    $origin = self::ORIGIN_PLUGIN_GROUP;
                } else if (isset($activity[$cmid][$userid])) {
                    [$time, $origin] = $activity[$cmid][$userid];
                } else {
                    $time = $bases[$cmid]->time;
                    $origin = $bases[$cmid]->origin;
                }
                $result[$cmid][$userid] = new deadline($time, $origin, $daily, $max);
            }
        }

        return $result;
    }

    /**
     * First non-null value as a float.
     *
     * @param mixed $first Preferred value.
     * @param mixed $second Fallback value.
     * @return float|null
     */
    private static function first_float($first, $second): ?float {
        if ($first !== null) {
            return (float) $first;
        }
        return $second !== null ? (float) $second : null;
    }

    /**
     * Activity due dates, keyed by module name and instance ID.
     *
     * @param \stdClass[] $cms Course modules.
     * @return array Due date timestamps keyed by module name, then instance ID.
     */
    private static function load_duedates(array $cms): array {
        global $DB;

        $instances = [];
        foreach ($cms as $cm) {
            if (self::duedate_field($cm->modname) !== null) {
                $instances[$cm->modname][(int) $cm->instance] = (int) $cm->instance;
            }
        }

        $result = [];
        foreach ($instances as $modname => $ids) {
            $field = self::duedate_field($modname);
            [$insql, $params] = $DB->get_in_or_equal(array_values($ids), SQL_PARAMS_NAMED, 'inst');
            $rows = $DB->get_records_sql("SELECT id, $field AS duedate FROM {{$modname}} WHERE id $insql", $params);
            foreach ($rows as $row) {
                $result[$modname][(int) $row->id] = (int) $row->duedate;
            }
        }
        return $result;
    }

    /**
     * Late Penalty student overrides, keyed by course module ID and user ID.
     *
     * @param int[] $cmids Course module IDs.
     * @param int[] $userids User IDs.
     * @return array Override records keyed by course module ID, then user ID.
     */
    private static function load_plugin_user_overrides(array $cmids, array $userids): array {
        global $DB;

        [$cmsql, $cmparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        $rows = $DB->get_records_select(
            'local_latepenalty_overrides',
            "cmid $cmsql AND userid $usersql",
            $cmparams + $userparams,
            '',
            'id, cmid, userid, deadline, daily_penalty, max_penalty'
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->cmid][(int) $row->userid] = $row;
        }
        return $result;
    }

    /**
     * Late Penalty group overrides merged per student, keyed by course module ID and user ID.
     *
     * A student in several overridden groups gets the most lenient value of each
     * field: the latest deadline, the lowest daily penalty and the lowest cap.
     *
     * @param int[] $cmids Course module IDs.
     * @param int[] $userids User IDs.
     * @return array Merged records keyed by course module ID, then user ID.
     */
    private static function load_plugin_group_overrides(array $cmids, array $userids): array {
        global $DB;

        [$cmsql, $cmparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        $rs = $DB->get_recordset_sql(
            "SELECT go.cmid, gm.userid,
                    MAX(go.deadline) AS deadline,
                    MIN(go.daily_penalty) AS daily_penalty,
                    MIN(go.max_penalty) AS max_penalty
               FROM {local_latepenalty_group_overrides} go
               JOIN {groups_members} gm ON gm.groupid = go.groupid
              WHERE go.cmid $cmsql
                AND gm.userid $usersql
           GROUP BY go.cmid, gm.userid",
            $cmparams + $userparams
        );

        $result = [];
        foreach ($rs as $row) {
            $result[(int) $row->cmid][(int) $row->userid] = $row;
        }
        $rs->close();
        return $result;
    }

    /**
     * Step 3 of the chain for every module type that has per-student overrides.
     *
     * @param \stdClass[] $cms Course modules keyed by ID.
     * @param int[] $userids User IDs.
     * @return array [time, origin] pairs keyed by course module ID, then user ID; absent when step 3 is empty.
     */
    private static function load_activity_overrides(array $cms, array $userids): array {
        $instances = [];
        $cmidbyinstance = [];
        foreach ($cms as $cmid => $cm) {
            if (in_array($cm->modname, ['assign', 'quiz', 'lesson'], true)) {
                $instances[$cm->modname][] = (int) $cm->instance;
                $cmidbyinstance[$cm->modname][(int) $cm->instance] = $cmid;
            }
        }

        $result = [];
        foreach ($instances as $modname => $ids) {
            switch ($modname) {
                case 'assign':
                    $byinstance = self::load_assign_overrides($ids, $userids);
                    break;
                case 'quiz':
                    $byinstance = self::load_quiz_overrides($ids, $userids);
                    break;
                default:
                    $byinstance = self::load_lesson_overrides($ids, $userids);
                    break;
            }
            foreach ($byinstance as $instanceid => $byuser) {
                $result[$cmidbyinstance[$modname][$instanceid]] = $byuser;
            }
        }
        return $result;
    }

    /**
     * Assignment extensions and overrides, read the way assign::override_exists() does.
     *
     * The extension wins (assign treats a submission as late only after it).
     * Otherwise the student override is used whenever it exists, even without a
     * due date (core then keeps the assignment due date and ignores groups);
     * failing that, the group override with the lowest sortorder.
     *
     * @param int[] $assignids Assignment IDs.
     * @param int[] $userids User IDs.
     * @return array [time, origin] pairs keyed by assignment ID, then user ID.
     */
    private static function load_assign_overrides(array $assignids, array $userids): array {
        global $DB;

        [$asql, $aparams] = $DB->get_in_or_equal($assignids, SQL_PARAMS_NAMED, 'asg');
        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        $params = $aparams + $uparams;

        $extensions = [];
        $rs = $DB->get_recordset_sql(
            "SELECT assignment, userid, extensionduedate
               FROM {assign_user_flags}
              WHERE assignment $asql
                AND userid $usql
                AND extensionduedate > 0",
            $params
        );
        foreach ($rs as $row) {
            $extensions[(int) $row->assignment][(int) $row->userid] = (int) $row->extensionduedate;
        }
        $rs->close();

        $useroverrides = [];
        $rs = $DB->get_recordset_sql(
            "SELECT assignid, userid, duedate
               FROM {assign_overrides}
              WHERE assignid $asql
                AND userid $usql",
            $params
        );
        foreach ($rs as $row) {
            $useroverrides[(int) $row->assignid][(int) $row->userid] = $row->duedate;
        }
        $rs->close();

        $groupoverrides = [];
        $rs = $DB->get_recordset_sql(
            "SELECT ao.assignid, gm.userid, ao.duedate
               FROM {assign_overrides} ao
               JOIN {groups_members} gm ON gm.groupid = ao.groupid
              WHERE ao.assignid $asql
                AND gm.userid $usql
           ORDER BY ao.sortorder ASC, ao.id ASC",
            $params
        );
        foreach ($rs as $row) {
            $assignid = (int) $row->assignid;
            $userid = (int) $row->userid;
            if (!array_key_exists($userid, $groupoverrides[$assignid] ?? [])) {
                $groupoverrides[$assignid][$userid] = $row->duedate;
            }
        }
        $rs->close();

        $result = [];
        foreach ($assignids as $assignid) {
            foreach ($userids as $userid) {
                if (isset($extensions[$assignid][$userid])) {
                    $result[$assignid][$userid] = [$extensions[$assignid][$userid], self::ORIGIN_EXTENSION];
                    continue;
                }
                if (array_key_exists($userid, $useroverrides[$assignid] ?? [])) {
                    $duedate = $useroverrides[$assignid][$userid];
                } else {
                    $duedate = $groupoverrides[$assignid][$userid] ?? null;
                }
                if ($duedate !== null) {
                    $result[$assignid][$userid] = [(int) $duedate, self::ORIGIN_ACTIVITY_OVERRIDE];
                }
            }
        }
        return $result;
    }

    /**
     * Quiz overrides, merged the way quiz_update_effective_access() does.
     *
     * A field the student override leaves empty comes from the groups: 0 if any
     * group has 0, else the latest. With the quiz due date (Moodle 5.3+) the
     * override due date decides, 0 meaning "no due date" for the student; the
     * override close date is used only when no due date applies at all.
     *
     * @param int[] $quizids Quiz IDs.
     * @param int[] $userids User IDs.
     * @return array [time, origin] pairs keyed by quiz ID, then user ID.
     */
    private static function load_quiz_overrides(array $quizids, array $userids): array {
        global $DB;

        $hasduedate = self::quiz_has_duedate();
        $fields = $hasduedate ? ['timeclose', 'duedate'] : ['timeclose'];
        [$user, $groups] = self::load_override_fields('quiz_overrides', 'quiz', $fields, $quizids, $userids);

        $baseduedates = [];
        if ($hasduedate) {
            [$qsql, $qparams] = $DB->get_in_or_equal($quizids, SQL_PARAMS_NAMED, 'quiz');
            $baseduedates = $DB->get_records_select_menu('quiz', "id $qsql", $qparams, '', 'id, duedate');
        }

        $result = [];
        foreach ($quizids as $quizid) {
            foreach ($userids as $userid) {
                if ($hasduedate) {
                    $duedate = self::merge_override_field($user, $groups, $quizid, $userid, 'duedate');
                    if ($duedate !== null) {
                        $result[$quizid][$userid] = [$duedate, self::ORIGIN_ACTIVITY_OVERRIDE];
                        continue;
                    }
                    if (!empty($baseduedates[$quizid])) {
                        continue;
                    }
                }
                $close = self::merge_override_field($user, $groups, $quizid, $userid, 'timeclose');
                if (!empty($close)) {
                    $result[$quizid][$userid] = [$close, self::ORIGIN_ACTIVITY_CLOSE];
                }
            }
        }
        return $result;
    }

    /**
     * Lesson overrides, merged the way lesson::update_effective_access() does.
     *
     * The lesson deadline is a hard close, so an override of 0 ("no deadline")
     * only means the step is empty and the chain goes on.
     *
     * @param int[] $lessonids Lesson IDs.
     * @param int[] $userids User IDs.
     * @return array [time, origin] pairs keyed by lesson ID, then user ID.
     */
    private static function load_lesson_overrides(array $lessonids, array $userids): array {
        [$user, $groups] = self::load_override_fields('lesson_overrides', 'lessonid', ['deadline'], $lessonids, $userids);

        $result = [];
        foreach ($lessonids as $lessonid) {
            foreach ($userids as $userid) {
                $deadline = self::merge_override_field($user, $groups, $lessonid, $userid, 'deadline');
                if (!empty($deadline)) {
                    $result[$lessonid][$userid] = [$deadline, self::ORIGIN_ACTIVITY_OVERRIDE];
                }
            }
        }
        return $result;
    }

    /**
     * Load student and group override fields of quiz-style override tables.
     *
     * @param string $table Override table.
     * @param string $instancefield Column holding the activity ID.
     * @param string[] $fields Date columns to read.
     * @param int[] $instanceids Activity IDs.
     * @param int[] $userids User IDs.
     * @return array [student rows keyed by instance and user, group rows (lists) keyed by instance and user]
     */
    private static function load_override_fields(
        string $table,
        string $instancefield,
        array $fields,
        array $instanceids,
        array $userids
    ): array {
        global $DB;

        [$isql, $iparams] = $DB->get_in_or_equal($instanceids, SQL_PARAMS_NAMED, 'inst');
        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        $params = $iparams + $uparams;
        $select = implode(', ', array_map(fn(string $field): string => "o.$field", $fields));

        $user = [];
        $rs = $DB->get_recordset_sql(
            "SELECT o.id, o.$instancefield AS instanceid, o.userid, $select
               FROM {{$table}} o
              WHERE o.$instancefield $isql
                AND o.userid $usql",
            $params
        );
        foreach ($rs as $row) {
            $user[(int) $row->instanceid][(int) $row->userid] = $row;
        }
        $rs->close();

        $groups = [];
        $rs = $DB->get_recordset_sql(
            "SELECT o.id, o.$instancefield AS instanceid, gm.userid, $select
               FROM {{$table}} o
               JOIN {groups_members} gm ON gm.groupid = o.groupid
              WHERE o.$instancefield $isql
                AND gm.userid $usql",
            $params
        );
        foreach ($rs as $row) {
            $groups[(int) $row->instanceid][(int) $row->userid][] = $row;
        }
        $rs->close();

        return [$user, $groups];
    }

    /**
     * Effective value of one override date for one student.
     *
     * The student's own value wins when set; otherwise the groups' values,
     * where 0 (no date) beats any date and the latest date wins otherwise.
     *
     * @param array $user Student rows keyed by instance and user.
     * @param array $groups Group rows keyed by instance and user.
     * @param int $instanceid Activity ID.
     * @param int $userid User ID.
     * @param string $field Date column.
     * @return int|null The value, or null when no override sets it.
     */
    private static function merge_override_field(array $user, array $groups, int $instanceid, int $userid, string $field): ?int {
        $own = $user[$instanceid][$userid]->$field ?? null;
        if ($own !== null) {
            return (int) $own;
        }

        $values = [];
        foreach ($groups[$instanceid][$userid] ?? [] as $row) {
            if ($row->$field !== null) {
                $values[] = (int) $row->$field;
            }
        }
        if (empty($values)) {
            return null;
        }
        return in_array(0, $values, true) ? 0 : max($values);
    }
}
