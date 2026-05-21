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
     * @param int            $courseid     The course id.
     * @param context_course $context      The course context.
     * @param int            $filteruserid User id to filter by (0 = all).
     * @param int            $filtercmid   Course module id to filter by (0 = all).
     */
    public function __construct(
        int $courseid,
        context_course $context,
        int $filteruserid = 0,
        int $filtercmid = 0
    ) {
        $this->courseid     = $courseid;
        $this->context      = $context;
        $this->filteruserid = $filteruserid;
        $this->filtercmid   = $filtercmid;
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
                  JOIN {modules} mod ON mod.name = gi.itemmodule
                  JOIN {course_modules} cm ON cm.instance = gi.iteminstance
                                          AND cm.course = :courseid2
                                          AND cm.module = mod.id
                  JOIN {local_latepenalty_rules} r ON r.cmid = cm.id AND r.enabled = 1
                 WHERE ggh.source = 'local_latepenalty'
                       {$userwhere}
                       {$cmwhere}
                 ORDER BY u.lastname, u.firstname, cm.id, ggh.timemodified DESC";

        $rows = $DB->get_records_sql($sql, $params);

        $modinfo = get_fast_modinfo($this->courseid);
        $moduledeadlines = self::load_module_deadlines($rows);

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
                'discount'           => format_float($discount, 1),
                'finalgrade'         => format_float($finalgrade, 2),
                'grademax'           => format_float((float) $row->grademax, 2),
                'penaltydate'        => userdate((int) $row->timemodified),
            ];
        }

        return [
            'penalties'    => $penalties,
            'haspenalties' => !empty($penalties),
            'formaction'   => (new \moodle_url('/local/latepenalty/report.php'))->out(false),
            'courseid'     => $this->courseid,
            'useroptions'  => $this->build_user_options(),
            'cmoptions'    => $this->build_cm_options(),
            'filteruserid' => $this->filteruserid,
            'filtercmid'   => $this->filtercmid,
        ];
    }

    /**
     * Build the list of users who have received a penalty in this course,
     * for the student filter select.
     *
     * @return array Array of {value, label, selected} objects.
     */
    private function build_user_options(): array {
        global $DB;

        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname,
                       u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename
                  FROM {grade_grades_history} ggh
                  JOIN {grade_items} gi ON gi.id = ggh.itemid
                                       AND gi.itemtype = 'mod'
                                       AND gi.courseid = :courseid
                  JOIN {user} u ON u.id = ggh.userid AND u.deleted = 0
                  JOIN {modules} mod ON mod.name = gi.itemmodule
                  JOIN {course_modules} cm ON cm.instance = gi.iteminstance
                                          AND cm.course = :courseid2
                                          AND cm.module = mod.id
                  JOIN {local_latepenalty_rules} r ON r.cmid = cm.id AND r.enabled = 1
                 WHERE ggh.source = 'local_latepenalty'
                 ORDER BY u.lastname, u.firstname";

        $rows = $DB->get_records_sql($sql, [
            'courseid'  => $this->courseid,
            'courseid2' => $this->courseid,
        ]);

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

        $sql = "SELECT DISTINCT cm.id, gi.itemname
                  FROM {grade_grades_history} ggh
                  JOIN {grade_items} gi ON gi.id = ggh.itemid
                                       AND gi.itemtype = 'mod'
                                       AND gi.courseid = :courseid
                  JOIN {modules} mod ON mod.name = gi.itemmodule
                  JOIN {course_modules} cm ON cm.instance = gi.iteminstance
                                          AND cm.course = :courseid2
                                          AND cm.module = mod.id
                  JOIN {local_latepenalty_rules} r ON r.cmid = cm.id AND r.enabled = 1
                 WHERE ggh.source = 'local_latepenalty'
                 ORDER BY gi.itemname";

        $rows = $DB->get_records_sql($sql, [
            'courseid'  => $this->courseid,
            'courseid2' => $this->courseid,
        ]);

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
