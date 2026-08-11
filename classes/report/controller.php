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
 * Controller for the late penalty course report.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty\report;

use context_course;

/**
 * Builds the template context for the late penalty report page.
 *
 * Queries grade_grades_history for rows written by this plugin and
 * returns the most recent penalty event per student+activity pair.
 *
 * @package local_latepenalty\report
 */
class controller {
    /** @var int The course id. */
    private int $courseid;

    /** @var context_course The course context. */
    private context_course $context;

    /** @var int Filter by user id (0 = all). */
    private int $filteruserid;

    /** @var int Filter by course module id (0 = all). */
    private int $filtercmid;

    /**
     * Group IDs the caller belongs to in this course.
     *
     * Always resolved (possibly empty), independent of whether any activity in
     * the course actually needs the restriction below.
     *
     * @var int[]
     */
    private array $callergroupids;

    /**
     * Course module IDs whose rows must be confined to $callergroupids.
     *
     * Set by resolve_group_restriction() to every cmid carrying an enabled rule
     * whose *effective* group mode (course-level or activity-level — see
     * groups_get_activity_groupmode()) is SEPARATEGROUPS, when the caller lacks
     * moodle/site:accessallgroups. Empty means no restriction anywhere.
     *
     * @var int[]
     */
    private array $restrictedcmids;

    /**
     * Deadline field per module type, mirroring the observer's map.
     *
     * @var array<string,string>
     */
    private static array $deadlinefields = [
        'assign'      => 'duedate',
        'forum'       => 'duedate',
        'lesson'      => 'deadline',
        'playergroup' => 'timeclose',
        'quiz'        => 'timeclose',
        'scorm'       => 'timeclose',
        'workshop'    => 'submissionend',
    ];

    /**
     * Constructor.
     *
     * @param int            $courseid        The course id.
     * @param context_course $context         The course context.
     * @param int            $filteruserid    User id to filter by (0 = all).
     * @param int            $filtercmid      Course module id to filter by (0 = all).
     * @param int[]          $callergroupids  Group IDs the caller belongs to. See resolve_group_restriction().
     * @param int[]          $restrictedcmids Course module IDs to confine to $callergroupids.
     */
    public function __construct(
        int $courseid,
        context_course $context,
        int $filteruserid = 0,
        int $filtercmid = 0,
        array $callergroupids = [],
        array $restrictedcmids = []
    ) {
        $this->courseid        = $courseid;
        $this->context         = $context;
        $this->filteruserid    = $filteruserid;
        $this->filtercmid      = $filtercmid;
        $this->callergroupids  = $callergroupids;
        $this->restrictedcmids = $restrictedcmids;
    }

    /**
     * Resolve which activities, if any, a report caller must see only their own
     * group's rows for, plus the caller's own group membership in the course.
     *
     * A course's group mode does not, by itself, determine an activity's
     * effective group mode: groups_get_activity_groupmode() shows the activity's
     * own groupmode can apply instead of the course's, when the course does not
     * force it. So this resolves the restriction per course module carrying an
     * enabled rule, rather than once for the whole course.
     *
     * @param \stdClass      $course  The course.
     * @param context_course $context The course context.
     * @return array{groupids: int[], restrictedcmids: int[]} Caller's own group IDs, and the
     *         course module IDs (among those with an enabled rule) that must be confined to them.
     */
    public static function resolve_group_restriction(\stdClass $course, context_course $context): array {
        global $USER, $DB;

        $groupids = array_keys(groups_get_all_groups($course->id, (int) $USER->id));

        if (has_capability('moodle/site:accessallgroups', $context)) {
            return ['groupids' => $groupids, 'restrictedcmids' => []];
        }

        $activecmids = array_keys($DB->get_records_sql(
            "SELECT cm.id
               FROM {local_latepenalty_rules} r
               JOIN {course_modules} cm ON cm.id = r.cmid
              WHERE cm.course = :courseid AND r.enabled = 1",
            ['courseid' => $course->id]
        ));

        if (empty($activecmids)) {
            return ['groupids' => $groupids, 'restrictedcmids' => []];
        }

        $modinfo = get_fast_modinfo($course);
        $restrictedcmids = [];
        foreach ($activecmids as $cmid) {
            if (!isset($modinfo->cms[$cmid])) {
                continue;
            }
            if ((int) groups_get_activity_groupmode($modinfo->cms[$cmid]) === SEPARATEGROUPS) {
                $restrictedcmids[] = $cmid;
            }
        }

        return ['groupids' => $groupids, 'restrictedcmids' => $restrictedcmids];
    }

    /**
     * Build the WHERE fragment and params that confine a query's rows — for
     * course modules in $this->restrictedcmids — to the caller's own groups.
     *
     * Activities outside $this->restrictedcmids are never filtered by group.
     *
     * @return array{0: string, 1: array} [SQL fragment starting with " AND ...", params].
     */
    private function group_scope_where(): array {
        if (empty($this->restrictedcmids)) {
            return ['', []];
        }

        global $DB;
        [$notinsql, $notinparams] = $DB->get_in_or_equal($this->restrictedcmids, SQL_PARAMS_NAMED, 'sepcm', false);

        if (empty($this->callergroupids)) {
            // No groups of their own: restricted activities show nothing; other
            // activities in the same report are unaffected.
            return [" AND cm.id $notinsql", $notinparams];
        }

        [$grpsql, $grpparams] = $DB->get_in_or_equal($this->callergroupids, SQL_PARAMS_NAMED, 'grpscope');
        $where = " AND (cm.id $notinsql"
            . " OR ggh.userid IN (SELECT gm.userid FROM {groups_members} gm WHERE gm.groupid $grpsql))";

        return [$where, array_merge($notinparams, $grpparams)];
    }

    /**
     * Returns the template context array for the report page.
     *
     * @return array Context array ready for render_from_template.
     */
    public function get_template_context(): array {
        global $DB;

        $params = [
            'courseid'  => $this->courseid,
            'courseid2' => $this->courseid,
        ];

        $userwhere = '';
        if ($this->filteruserid > 0) {
            $userwhere   = ' AND ggh.userid = :filteruserid';
            $params['filteruserid'] = $this->filteruserid;
        }

        $cmwhere = '';
        if ($this->filtercmid > 0) {
            $cmwhere   = ' AND cm.id = :filtercmid';
            $params['filtercmid'] = $this->filtercmid;
        }

        [$groupwhere, $groupparams] = $this->group_scope_where();
        $params = array_merge($params, $groupparams);

        $sql = "SELECT ggh.id, ggh.userid, ggh.itemid,
                       ggh.rawgrade, ggh.finalgrade, ggh.timemodified,
                       gi.grademax, gi.itemmodule, gi.iteminstance,
                       cm.id AS cmid, cm.completionexpected,
                       u.firstname, u.lastname,
                       u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename
                  FROM {grade_grades_history} ggh
                  JOIN {grade_items} gi ON gi.id = ggh.itemid
                                       AND gi.itemtype = 'mod'
                                       AND gi.courseid = :courseid
                  JOIN {user} u ON u.id = ggh.userid AND u.deleted = 0
                  JOIN {modules} m ON m.name = gi.itemmodule
                  JOIN {course_modules} cm ON cm.instance = gi.iteminstance
                                          AND cm.course = :courseid2
                                          AND cm.module = m.id
                  JOIN {local_latepenalty_rules} r ON r.cmid = cm.id AND r.enabled = 1
                 WHERE ggh.source = 'local_latepenalty'
                       {$userwhere}
                       {$cmwhere}
                       {$groupwhere}
                 ORDER BY u.lastname, u.firstname, cm.id, ggh.timemodified DESC";

        $rows = $DB->get_records_sql($sql, $params);

        $modinfo = get_fast_modinfo($this->courseid);
        $moduledeadlines = self::load_module_deadlines($rows);
        $overrides = self::load_overrides($rows);

        // Keep only the most recent penalty per student + grade item (ORDER BY DESC above).
        $seen      = [];
        $penalties = [];

        foreach ($rows as $row) {
            $key = $row->userid . '_' . $row->itemid;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $rawgrade   = (float) $row->rawgrade;
            $finalgrade = (float) $row->finalgrade;
            $discount   = ($rawgrade > 0)
                ? round((1.0 - $finalgrade / $rawgrade) * 100.0, 1)
                : 0.0;

            $overridekey    = $row->userid . '_' . $row->cmid;
            $hasuseroverride  = !empty($overrides['user'][$overridekey]);
            $hasgroupoverride = !$hasuseroverride && !empty($overrides['group'][$overridekey]);

            $fakeuser = (object) [
                'firstname'         => $row->firstname ?? '',
                'lastname'          => $row->lastname ?? '',
                'firstnamephonetic' => $row->firstnamephonetic ?? '',
                'lastnamephonetic'  => $row->lastnamephonetic ?? '',
                'middlename'        => $row->middlename ?? '',
                'alternatename'     => $row->alternatename ?? '',
            ];

            $cmname = isset($modinfo->cms[$row->cmid])
                ? format_string($modinfo->cms[$row->cmid]->name, true, ['context' => $this->context])
                : '';

            $deadline = self::resolve_deadline($row, $moduledeadlines);

            $penalties[] = [
                'fullname'           => format_string(
                    fullname($fakeuser),
                    true,
                    ['context' => $this->context]
                ),
                'activity'           => $cmname,
                'hasdeadline'        => $deadline !== null,
                'completionexpected' => $deadline !== null
                    ? userdate($deadline)
                    : '',
                'rawgrade'           => format_float($rawgrade, 2),
                'hasdiscount'        => $discount > 0,
                'discount'           => format_float($discount, 1),
                'finalgrade'         => format_float($finalgrade, 2),
                'grademax'           => format_float((float) $row->grademax, 2),
                'penaltydate'        => userdate((int) $row->timemodified),
                'hasuseroverride'    => $hasuseroverride,
                'hasgroupoverride'   => $hasgroupoverride,
            ];
        }

        return [
            'penalties'    => $penalties,
            'haspenalties' => !empty($penalties),
            'formaction'   => (new \moodle_url('/local/latepenalty/report.php'))->out(false),
            'exporturl'    => (new \moodle_url('/local/latepenalty/report_export.php', [
                'courseid' => $this->courseid,
                'userid'   => $this->filteruserid,
                'cmid'     => $this->filtercmid,
            ]))->out(false),
            'courseid'     => $this->courseid,
            'useroptions'  => $this->build_user_options(),
            'cmoptions'    => $this->build_cm_options(),
            'filteruserid' => $this->filteruserid,
            'filtercmid'   => $this->filtercmid,
        ];
    }

    /**
     * Returns column headers and data rows suitable for \core\dataformat::download_data().
     *
     * Mirrors get_template_context() but returns raw numeric values for grades
     * and a plain-text override label instead of a Mustache badge.
     *
     * @return array{0: string[], 1: array[]} Tuple of [columns, rows].
     */
    public function get_export_data(): array {
        global $DB;

        $params = [
            'courseid'  => $this->courseid,
            'courseid2' => $this->courseid,
        ];

        $userwhere = '';
        if ($this->filteruserid > 0) {
            $userwhere = ' AND ggh.userid = :filteruserid';
            $params['filteruserid'] = $this->filteruserid;
        }

        $cmwhere = '';
        if ($this->filtercmid > 0) {
            $cmwhere = ' AND cm.id = :filtercmid';
            $params['filtercmid'] = $this->filtercmid;
        }

        [$groupwhere, $groupparams] = $this->group_scope_where();
        $params = array_merge($params, $groupparams);

        $sql = "SELECT ggh.id, ggh.userid, ggh.itemid,
                       ggh.rawgrade, ggh.finalgrade, ggh.timemodified,
                       gi.grademax, gi.itemmodule, gi.iteminstance,
                       cm.id AS cmid, cm.completionexpected,
                       u.firstname, u.lastname,
                       u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename
                  FROM {grade_grades_history} ggh
                  JOIN {grade_items} gi ON gi.id = ggh.itemid
                                       AND gi.itemtype = 'mod'
                                       AND gi.courseid = :courseid
                  JOIN {user} u ON u.id = ggh.userid AND u.deleted = 0
                  JOIN {modules} m ON m.name = gi.itemmodule
                  JOIN {course_modules} cm ON cm.instance = gi.iteminstance
                                          AND cm.course = :courseid2
                                          AND cm.module = m.id
                  JOIN {local_latepenalty_rules} r ON r.cmid = cm.id AND r.enabled = 1
                 WHERE ggh.source = 'local_latepenalty'
                       {$userwhere}
                       {$cmwhere}
                       {$groupwhere}
                 ORDER BY u.lastname, u.firstname, cm.id, ggh.timemodified DESC";

        $rows = $DB->get_records_sql($sql, $params);

        $modinfo        = get_fast_modinfo($this->courseid);
        $moduledeadlines = self::load_module_deadlines($rows);
        $overrides      = self::load_overrides($rows);

        $seen = [];
        $data = [];

        foreach ($rows as $row) {
            $key = $row->userid . '_' . $row->itemid;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $rawgrade   = (float) $row->rawgrade;
            $finalgrade = (float) $row->finalgrade;
            $discount   = ($rawgrade > 0)
                ? round((1.0 - $finalgrade / $rawgrade) * 100.0, 1)
                : 0.0;

            $overridekey    = $row->userid . '_' . $row->cmid;
            $hasuseroverride  = !empty($overrides['user'][$overridekey]);
            $hasgroupoverride = !$hasuseroverride && !empty($overrides['group'][$overridekey]);

            $fakeuser = (object) [
                'firstname'         => $row->firstname ?? '',
                'lastname'          => $row->lastname ?? '',
                'firstnamephonetic' => $row->firstnamephonetic ?? '',
                'lastnamephonetic'  => $row->lastnamephonetic ?? '',
                'middlename'        => $row->middlename ?? '',
                'alternatename'     => $row->alternatename ?? '',
            ];

            $cmname  = isset($modinfo->cms[$row->cmid])
                ? format_string($modinfo->cms[$row->cmid]->name, true, ['context' => $this->context])
                : '';
            $deadline = self::resolve_deadline($row, $moduledeadlines);

            if ($hasuseroverride) {
                $overridelabel = get_string('report_override_user', 'local_latepenalty');
            } else if ($hasgroupoverride) {
                $overridelabel = get_string('report_override_group', 'local_latepenalty');
            } else {
                $overridelabel = '';
            }

            $data[] = [
                format_string(fullname($fakeuser), true, ['context' => $this->context]),
                $cmname,
                $deadline !== null ? userdate($deadline) : '',
                $rawgrade,
                (float) $row->grademax,
                $discount,
                $finalgrade,
                userdate((int) $row->timemodified),
                $overridelabel,
            ];
        }

        $columns = [
            get_string('report_col_student', 'local_latepenalty'),
            get_string('report_col_activity', 'local_latepenalty'),
            get_string('report_col_deadline', 'local_latepenalty'),
            get_string('report_col_rawgrade', 'local_latepenalty'),
            get_string('report_export_grademax', 'local_latepenalty'),
            get_string('report_col_discount', 'local_latepenalty'),
            get_string('report_col_finalgrade', 'local_latepenalty'),
            get_string('report_col_date', 'local_latepenalty'),
            get_string('report_export_override', 'local_latepenalty'),
        ];

        return [$columns, $data];
    }

    /**
     * Build the list of users who have received a penalty in this course,
     * for the student filter select.
     *
     * @return array Array of {value, label, selected} objects.
     */
    private function build_user_options(): array {
        global $DB;

        [$groupwhere, $groupparams] = $this->group_scope_where();

        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname,
                       u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename
                  FROM {grade_grades_history} ggh
                  JOIN {grade_items} gi ON gi.id = ggh.itemid
                                       AND gi.itemtype = 'mod'
                                       AND gi.courseid = :courseid
                  JOIN {user} u ON u.id = ggh.userid AND u.deleted = 0
                  JOIN {modules} m ON m.name = gi.itemmodule
                  JOIN {course_modules} cm ON cm.instance = gi.iteminstance
                                          AND cm.course = :courseid2
                                          AND cm.module = m.id
                  JOIN {local_latepenalty_rules} r ON r.cmid = cm.id AND r.enabled = 1
                 WHERE ggh.source = 'local_latepenalty'
                       {$groupwhere}
                 ORDER BY u.lastname, u.firstname";

        $rows = $DB->get_records_sql($sql, array_merge([
            'courseid'  => $this->courseid,
            'courseid2' => $this->courseid,
        ], $groupparams));

        $options = [[
            'value'    => 0,
            'label'    => get_string('filter_all_students', 'local_latepenalty'),
            'selected' => $this->filteruserid === 0,
        ]];
        foreach ($rows as $row) {
            $fakeuser = (object) [
                'firstname'         => $row->firstname ?? '',
                'lastname'          => $row->lastname ?? '',
                'firstnamephonetic' => $row->firstnamephonetic ?? '',
                'lastnamephonetic'  => $row->lastnamephonetic ?? '',
                'middlename'        => $row->middlename ?? '',
                'alternatename'     => $row->alternatename ?? '',
            ];
            $options[] = [
                'value'    => (int) $row->id,
                'label'    => format_string(fullname($fakeuser), true, ['context' => $this->context]),
                'selected' => (int) $row->id === $this->filteruserid,
            ];
        }
        return $options;
    }

    /**
     * Build the list of activities that have at least one penalty recorded in this course,
     * for the activity filter select.
     *
     * @return array Array of {value, label, selected} objects.
     */
    private function build_cm_options(): array {
        global $DB;

        [$groupwhere, $groupparams] = $this->group_scope_where();

        $sql = "SELECT DISTINCT cm.id, gi.itemname
                  FROM {grade_grades_history} ggh
                  JOIN {grade_items} gi ON gi.id = ggh.itemid
                                       AND gi.itemtype = 'mod'
                                       AND gi.courseid = :courseid
                  JOIN {modules} m ON m.name = gi.itemmodule
                  JOIN {course_modules} cm ON cm.instance = gi.iteminstance
                                          AND cm.course = :courseid2
                                          AND cm.module = m.id
                  JOIN {local_latepenalty_rules} r ON r.cmid = cm.id AND r.enabled = 1
                 WHERE ggh.source = 'local_latepenalty'
                       {$groupwhere}
                 ORDER BY gi.itemname";

        $rows = $DB->get_records_sql($sql, array_merge([
            'courseid'  => $this->courseid,
            'courseid2' => $this->courseid,
        ], $groupparams));

        $modinfo = get_fast_modinfo($this->courseid);

        $options = [[
            'value'    => 0,
            'label'    => get_string('filter_all_activities', 'local_latepenalty'),
            'selected' => $this->filtercmid === 0,
        ]];
        foreach ($rows as $row) {
            $cmname = isset($modinfo->cms[$row->id])
                ? format_string($modinfo->cms[$row->id]->name, true, ['context' => $this->context])
                : format_string($row->itemname ?? '', true, ['context' => $this->context]);
            $options[] = [
                'value'    => (int) $row->id,
                'label'    => $cmname,
                'selected' => (int) $row->id === $this->filtercmid,
            ];
        }
        return $options;
    }

    /**
     * Resolve the effective deadline for a grade history row.
     *
     * Mirrors the observer logic: completionexpected takes priority; falls back
     * to the module-specific deadline field (assign.duedate, forum.duedate).
     *
     * @param \stdClass $row            Row from the report query containing completionexpected,
     *                                  itemmodule and iteminstance.
     * @param array     $moduledeadlines Pre-loaded map of modname → instanceid → deadline timestamp.
     * @return int|null Deadline timestamp or null if not determinable.
     */
    private static function resolve_deadline(\stdClass $row, array $moduledeadlines): ?int {
        if (!empty($row->completionexpected)) {
            return (int) $row->completionexpected;
        }

        $value = $moduledeadlines[$row->itemmodule][(int) $row->iteminstance] ?? null;
        return ($value) ? (int) $value : null;
    }

    /**
     * Load user and group overrides in bulk for the given report rows.
     *
     * Returns two maps, each keyed by "userid_cmid" → true, so callers can do a
     * cheap isset() check per row without triggering any additional queries.
     *
     * @param array $rows Report rows, each having userid and cmid properties.
     * @return array{user: array<string,bool>, group: array<string,bool>}
     */
    private static function load_overrides(array $rows): array {
        global $DB;

        $userids = [];
        $cmids   = [];
        foreach ($rows as $row) {
            $userids[(int) $row->userid] = (int) $row->userid;
            $cmids[(int) $row->cmid]     = (int) $row->cmid;
        }

        if (empty($userids) || empty($cmids)) {
            return ['user' => [], 'group' => []];
        }

        $useridlist = array_values($userids);
        $cmidlist   = array_values($cmids);

        // User overrides.
        [$usql, $uparams] = $DB->get_in_or_equal($useridlist, SQL_PARAMS_NAMED, 'uo_uid');
        [$csql, $cparams] = $DB->get_in_or_equal($cmidlist, SQL_PARAMS_NAMED, 'uo_cm');
        $useroverrides = [];
        $records = $DB->get_records_sql(
            "SELECT id, userid, cmid FROM {local_latepenalty_overrides}
              WHERE userid $usql AND cmid $csql",
            array_merge($uparams, $cparams)
        );
        foreach ($records as $record) {
            $useroverrides[(int) $record->userid . '_' . (int) $record->cmid] = true;
        }

        // Group overrides — resolved to individual users via groups_members.
        [$usql2, $uparams2] = $DB->get_in_or_equal($useridlist, SQL_PARAMS_NAMED, 'go_uid');
        [$csql2, $cparams2] = $DB->get_in_or_equal($cmidlist, SQL_PARAMS_NAMED, 'go_cm');
        $groupoverrides = [];
        $records = $DB->get_records_sql(
            "SELECT go.id, go.cmid, gm.userid
               FROM {local_latepenalty_group_overrides} go
               JOIN {groups_members} gm ON gm.groupid = go.groupid
              WHERE go.cmid $csql2 AND gm.userid $usql2",
            array_merge($cparams2, $uparams2)
        );
        foreach ($records as $record) {
            $groupoverrides[(int) $record->userid . '_' . (int) $record->cmid] = true;
        }

        return ['user' => $useroverrides, 'group' => $groupoverrides];
    }

    /**
     * Load module deadline fields in bulk, grouped by module type.
     *
     * @param array $rows Report rows containing itemmodule and iteminstance.
     * @return array<string, array<int, int>> Deadline timestamps keyed by module name and instance ID.
     */
    private static function load_module_deadlines(array $rows): array {
        global $DB;

        $instancesbymodule = [];
        foreach ($rows as $row) {
            if (!empty($row->completionexpected) || empty(self::$deadlinefields[$row->itemmodule])) {
                continue;
            }
            $instancesbymodule[$row->itemmodule][(int) $row->iteminstance] = (int) $row->iteminstance;
        }

        $deadlines = [];
        self::load_deadlines_for_module('assign', $instancesbymodule, $deadlines);
        self::load_deadlines_for_module('forum', $instancesbymodule, $deadlines);
        self::load_deadlines_for_module('lesson', $instancesbymodule, $deadlines);
        self::load_deadlines_for_module('playergroup', $instancesbymodule, $deadlines);
        self::load_deadlines_for_module('quiz', $instancesbymodule, $deadlines);
        self::load_deadlines_for_module('scorm', $instancesbymodule, $deadlines);
        self::load_deadlines_for_module('workshop', $instancesbymodule, $deadlines);

        return $deadlines;
    }

    /**
     * Load deadline values for one whitelisted module table.
     *
     * @param string $modname Module name.
     * @param array $instancesbymodule Instance IDs grouped by module name.
     * @param array $deadlines Deadline accumulator keyed by module name and instance ID.
     * @return void
     */
    private static function load_deadlines_for_module(
        string $modname,
        array $instancesbymodule,
        array &$deadlines
    ): void {
        global $DB;

        if (empty($instancesbymodule[$modname])) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($instancesbymodule[$modname], SQL_PARAMS_NAMED, 'inst');
        $field = self::$deadlinefields[$modname];
        $records = $DB->get_records_sql(
            "SELECT id, $field AS deadline
               FROM {{$modname}}
              WHERE id $insql",
            $inparams
        );
        foreach ($records as $record) {
            if (!empty($record->deadline)) {
                $deadlines[$modname][(int) $record->id] = (int) $record->deadline;
            }
        }
    }
}
