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
 * Hook listener for local_latepenalty.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty;

/**
 * Hook listener class.
 */
class hook_listener {
    /**
     * Map of module name to the field that holds its deadline timestamp.
     *
     * Mirrors penalty_helper::$deadlinefields — only soft-deadline modules.
     *
     * @var array<string, string>
     */
    private static array $deadlinefields = [
        'assign' => 'duedate',
        'forum'  => 'duedate',
    ];

    /**
     * Inject late-penalty notices into the course page.
     *
     * Called by the before_standard_footer_html_generation hook, which fires
     * after the course content is fully rendered. Queuing the AMD call here
     * ensures it runs after the courseformat reactive components have
     * initialised, preventing race conditions in view mode.
     *
     * Handles both course/view.php (pagetype course-view-*) and
     * course/section.php (pagetype course-view-section-*).
     *
     * @param \core\hook\output\before_standard_footer_html_generation $hook The hook instance.
     * @return void
     */
    public static function inject_course_notices(
        \core\hook\output\before_standard_footer_html_generation $hook
    ): void {
        global $DB, $PAGE, $USER;

        if (!isloggedin() || isguestuser()) {
            return;
        }

        if (!str_starts_with($PAGE->pagetype, 'course-view-')) {
            return;
        }

        $courseid = (int) $PAGE->course->id;

        // Load all enabled rules for modules in this course in a single query.
        $sql = "SELECT r.cmid, r.daily_penalty, r.max_penalty,
                       cm.completionexpected, cm.instance, m.name AS modname
                  FROM {local_latepenalty_rules} r
                  JOIN {course_modules} cm ON cm.id = r.cmid
                  JOIN {modules} m ON m.id = cm.module
                 WHERE cm.course = :courseid
                   AND r.enabled = 1";
        $records = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        if (empty($records)) {
            return;
        }

        // Load cmids already completed by this user to suppress their badges.
        $completedsql = "SELECT cmc.coursemoduleid
                           FROM {course_modules_completion} cmc
                           JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                          WHERE cm.course = :courseid
                            AND cmc.userid = :userid
                            AND cmc.completionstate >= 1";
        $completedrows = $DB->get_records_sql($completedsql, ['courseid' => $courseid, 'userid' => (int) $USER->id]);
        $completedcmids = array_flip(array_column($completedrows, 'coursemoduleid'));

        $dateformat = get_string('strftimedatefullshort', 'langconfig');
        $now = time();
        $notices = [];
        $activitydeadlines = self::load_activity_deadlines($records);
        $userdeadlines = self::load_user_module_deadlines($records, (int) $USER->id);

        // Load all per-user overrides for this user in this course (single query).
        $overridessql = "SELECT o.cmid, o.deadline, o.daily_penalty, o.max_penalty
                           FROM {local_latepenalty_overrides} o
                           JOIN {course_modules} cm ON cm.id = o.cmid
                          WHERE cm.course = :courseid
                            AND o.userid = :userid";
        $useroverriderows = $DB->get_records_sql($overridessql, [
            'courseid' => $courseid,
            'userid'   => (int) $USER->id,
        ]);
        $useroverrides = [];
        foreach ($useroverriderows as $o) {
            $useroverrides[(int) $o->cmid] = $o;
        }

        foreach ($records as $record) {
            if (isset($completedcmids[(int) $record->cmid])) {
                continue;
            }

            $override = $useroverrides[(int) $record->cmid] ?? null;

            if ($override && $override->deadline !== null) {
                $deadline = (int) $override->deadline;
            } else {
                $cmid = (int) $record->cmid;
                $deadline = $userdeadlines[$cmid] ?? $activitydeadlines[$cmid] ?? null;
            }
            if (!$deadline) {
                continue;
            }

            $daily = ($override && $override->daily_penalty !== null)
                ? (float) $override->daily_penalty
                : (float) $record->daily_penalty;
            $max = ($override && $override->max_penalty !== null)
                ? (float) $override->max_penalty
                : (float) $record->max_penalty;

            [$badgelabel, $badgestate, $notice] = self::compute_badge(
                $deadline,
                $daily,
                $max,
                $now,
                $dateformat
            );

            $notices[] = [
                'cmid'       => (int) $record->cmid,
                'notice'     => $notice,
                'badgelabel' => $badgelabel,
                'badgestate' => $badgestate,
            ];
        }

        if (empty($notices)) {
            return;
        }

        $PAGE->requires->js_call_amd('local_latepenalty/courseinfo', 'init', [$notices]);
    }

    /**
     * Inject a late-penalty notice into an activity page header.
     *
     * Called by the before_http_headers hook on every page load. Checks
     * whether the current page is an activity page with an enabled penalty
     * rule, then registers an AMD call to insert the notice inside the
     * standard activity-information block.
     *
     * @param \core\hook\output\before_http_headers $hook The hook instance.
     * @return void
     */
    public static function inject_activity_notice(
        \core\hook\output\before_http_headers $hook
    ): void {
        global $DB, $PAGE, $USER;

        if (!isloggedin() || isguestuser()) {
            return;
        }

        $cm = $PAGE->cm;
        if (!$cm) {
            return;
        }

        $rule = $DB->get_record('local_latepenalty_rules', ['cmid' => $cm->id, 'enabled' => 1]);
        if (!$rule) {
            return;
        }

        // Suppress notice once the student has completed the activity.
        $completed = $DB->get_field(
            'course_modules_completion',
            'completionstate',
            ['coursemoduleid' => $cm->id, 'userid' => (int) $USER->id]
        );
        if ($completed !== false && (int) $completed >= 1) {
            return;
        }

        $record = (object) [
            'completionexpected' => $cm->completionexpected ?? 0,
            'instance'           => $cm->instance,
            'modname'            => $cm->modname,
        ];

        $override = $DB->get_record(
            'local_latepenalty_overrides',
            ['cmid' => $cm->id, 'userid' => (int) $USER->id]
        );

        if ($override && $override->deadline !== null) {
            $deadline = (int) $override->deadline;
        } else {
            $deadline = penalty_helper::get_module_user_deadline(
                $cm->modname,
                (int) $cm->instance,
                (int) $USER->id
            ) ?? self::resolve_deadline($record);
        }
        if (!$deadline) {
            return;
        }

        $dateformat = get_string('strftimedatefullshort', 'langconfig');
        $daily = ($override && $override->daily_penalty !== null)
            ? (float) $override->daily_penalty
            : (float) $rule->daily_penalty;
        $max = ($override && $override->max_penalty !== null)
            ? (float) $override->max_penalty
            : (float) $rule->max_penalty;
        [, , $notice] = self::compute_badge($deadline, $daily, $max, time(), $dateformat);

        $PAGE->requires->js_call_amd('local_latepenalty/activityinfo', 'init', [$notice]);
    }

    /**
     * Compute the badge label, CSS state, and tooltip notice for a given deadline and penalty rule.
     *
     * @param int    $deadline   Unix timestamp of the activity deadline.
     * @param float  $daily      Daily penalty percentage.
     * @param float  $max        Maximum penalty percentage.
     * @param int    $now        Current Unix timestamp.
     * @param string $dateformat Moodle date format string.
     * @return array{string, string, string} [badgelabel, badgestate, notice] where state is ontime|warning|danger.
     */
    private static function compute_badge(
        int $deadline,
        float $daily,
        float $max,
        int $now,
        string $dateformat
    ): array {
        $datestr = userdate($deadline, $dateformat);

        if ($deadline > $now) {
            $label  = get_string('badge_ontime', 'local_latepenalty', ['date' => $datestr]);
            $notice = get_string('courseinfo_notice', 'local_latepenalty', (object) [
                'deadline' => $datestr,
                'daily'    => (string) $daily,
                'max'      => (string) $max,
            ]);
            return [$label, 'ontime', $notice];
        }

        $daysoverdue = (int) ceil(($now - $deadline) / DAYSECS);
        $penalty = min($daysoverdue * $daily, $max);

        if ($penalty >= $max) {
            $label  = get_string('badge_penalty_max', 'local_latepenalty', ['pct' => $max]);
            $notice = get_string('courseinfo_notice_overdue_max', 'local_latepenalty', (object) [
                'deadline' => $datestr,
                'max'      => (string) $max,
            ]);
            return [$label, 'danger', $notice];
        }

        $label  = get_string('badge_penalty', 'local_latepenalty', ['pct' => $penalty]);
        $notice = get_string('courseinfo_notice_overdue', 'local_latepenalty', (object) [
            'deadline' => $datestr,
            'pct'      => (string) $penalty,
            'daily'    => (string) $daily,
            'max'      => (string) $max,
        ]);
        return [$label, 'warning', $notice];
    }

    /**
     * Load activity deadlines in bulk, keyed by course module ID.
     *
     * @param array $records Rule records containing cmid, completionexpected, instance and modname.
     * @return array<int, int> Deadline timestamps keyed by cmid.
     */
    private static function load_activity_deadlines(array $records): array {
        global $DB;

        $deadlines = [];
        $instancesbymodule = [];
        $cmidbyinstance = [];

        foreach ($records as $record) {
            $cmid = (int) $record->cmid;
            if (!empty($record->completionexpected)) {
                $deadlines[$cmid] = (int) $record->completionexpected;
                continue;
            }
            if (empty(self::$deadlinefields[$record->modname])) {
                continue;
            }
            $instanceid = (int) $record->instance;
            $instancesbymodule[$record->modname][$instanceid] = $instanceid;
            $cmidbyinstance[$record->modname][$instanceid] = $cmid;
        }

        self::load_activity_deadline_module('assign', $instancesbymodule, $cmidbyinstance, $deadlines);
        self::load_activity_deadline_module('forum', $instancesbymodule, $cmidbyinstance, $deadlines);

        return $deadlines;
    }

    /**
     * Load base deadlines for one whitelisted activity module.
     *
     * @param string $modname Module name.
     * @param array $instancesbymodule Instance IDs grouped by module name.
     * @param array $cmidbyinstance Course module IDs grouped by module name and instance ID.
     * @param array $deadlines Deadline accumulator keyed by cmid.
     * @return void
     */
    private static function load_activity_deadline_module(
        string $modname,
        array $instancesbymodule,
        array $cmidbyinstance,
        array &$deadlines
    ): void {
        global $DB;

        if (empty($instancesbymodule[$modname])) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($instancesbymodule[$modname], SQL_PARAMS_NAMED, 'inst');
        $field = self::$deadlinefields[$modname];
        $rows = $DB->get_records_sql(
            "SELECT id, $field AS deadline
               FROM {{$modname}}
              WHERE id $insql",
            $inparams
        );
        foreach ($rows as $row) {
            if (empty($row->deadline)) {
                continue;
            }
            $cmid = $cmidbyinstance[$modname][(int) $row->id];
            $deadlines[$cmid] = (int) $row->deadline;
        }
    }

    /**
     * Load Moodle-native user/group deadline overrides in bulk for one user.
     *
     * @param array $records Rule records containing cmid, instance and modname.
     * @param int $userid User ID.
     * @return array<int, int> Effective native override deadlines keyed by cmid.
     */
    private static function load_user_module_deadlines(array $records, int $userid): array {
        $instancesbymodule = [];
        $cmidbyinstance = [];
        foreach ($records as $record) {
            if (!in_array($record->modname, ['assign', 'lesson', 'quiz'], true)) {
                continue;
            }
            $instanceid = (int) $record->instance;
            $instancesbymodule[$record->modname][$instanceid] = $instanceid;
            $cmidbyinstance[$record->modname][$instanceid] = (int) $record->cmid;
        }

        $deadlines = [];
        self::load_assign_user_deadlines($instancesbymodule, $cmidbyinstance, $userid, $deadlines);
        self::load_quiz_user_deadlines($instancesbymodule, $cmidbyinstance, $userid, $deadlines);
        self::load_lesson_user_deadlines($instancesbymodule, $cmidbyinstance, $userid, $deadlines);

        return $deadlines;
    }

    /**
     * Load assignment user and group override deadlines.
     *
     * @param array $instancesbymodule Module instances grouped by module name.
     * @param array $cmidbyinstance Course module IDs grouped by module name and instance ID.
     * @param int $userid User ID.
     * @param array $deadlines Deadline accumulator keyed by cmid.
     * @return void
     */
    private static function load_assign_user_deadlines(
        array $instancesbymodule,
        array $cmidbyinstance,
        int $userid,
        array &$deadlines
    ): void {
        self::load_native_user_deadlines(
            'assign',
            'assign_overrides',
            'assignid',
            'duedate',
            $instancesbymodule,
            $cmidbyinstance,
            $userid,
            $deadlines
        );
    }

    /**
     * Load quiz user and group override deadlines.
     *
     * @param array $instancesbymodule Module instances grouped by module name.
     * @param array $cmidbyinstance Course module IDs grouped by module name and instance ID.
     * @param int $userid User ID.
     * @param array $deadlines Deadline accumulator keyed by cmid.
     * @return void
     */
    private static function load_quiz_user_deadlines(
        array $instancesbymodule,
        array $cmidbyinstance,
        int $userid,
        array &$deadlines
    ): void {
        self::load_native_user_deadlines(
            'quiz',
            'quiz_overrides',
            'quiz',
            'timeclose',
            $instancesbymodule,
            $cmidbyinstance,
            $userid,
            $deadlines
        );
    }

    /**
     * Load lesson user and group override deadlines.
     *
     * @param array $instancesbymodule Module instances grouped by module name.
     * @param array $cmidbyinstance Course module IDs grouped by module name and instance ID.
     * @param int $userid User ID.
     * @param array $deadlines Deadline accumulator keyed by cmid.
     * @return void
     */
    private static function load_lesson_user_deadlines(
        array $instancesbymodule,
        array $cmidbyinstance,
        int $userid,
        array &$deadlines
    ): void {
        self::load_native_user_deadlines(
            'lesson',
            'lesson_overrides',
            'lessonid',
            'deadline',
            $instancesbymodule,
            $cmidbyinstance,
            $userid,
            $deadlines
        );
    }

    /**
     * Load user-specific and best group native overrides for one module type.
     *
     * @param string $modname Module name.
     * @param string $table Native override table.
     * @param string $instancefield Native override instance field.
     * @param string $deadlinefield Native override deadline field.
     * @param array $instancesbymodule Module instances grouped by module name.
     * @param array $cmidbyinstance Course module IDs grouped by module name and instance ID.
     * @param int $userid User ID.
     * @param array $deadlines Deadline accumulator keyed by cmid.
     * @return void
     */
    private static function load_native_user_deadlines(
        string $modname,
        string $table,
        string $instancefield,
        string $deadlinefield,
        array $instancesbymodule,
        array $cmidbyinstance,
        int $userid,
        array &$deadlines
    ): void {
        global $DB;

        if (empty($instancesbymodule[$modname])) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($instancesbymodule[$modname], SQL_PARAMS_NAMED, 'native');
        $params = array_merge(['userid' => $userid], $inparams);
        $userrows = $DB->get_records_sql(
            "SELECT $instancefield AS instanceid, $deadlinefield AS deadline
               FROM {{$table}}
              WHERE $instancefield $insql
                AND userid = :userid
                AND $deadlinefield > 0",
            $params
        );
        foreach ($userrows as $row) {
            $cmid = $cmidbyinstance[$modname][(int) $row->instanceid];
            $deadlines[$cmid] = (int) $row->deadline;
        }

        $grouprows = $DB->get_records_sql(
            "SELECT o.$instancefield AS instanceid, MAX(o.$deadlinefield) AS deadline
               FROM {{$table}} o
               JOIN {groups_members} gm ON gm.groupid = o.groupid
              WHERE o.$instancefield $insql
                AND gm.userid = :userid
                AND o.$deadlinefield > 0
           GROUP BY o.$instancefield",
            $params
        );
        foreach ($grouprows as $row) {
            $cmid = $cmidbyinstance[$modname][(int) $row->instanceid];
            if (!isset($deadlines[$cmid])) {
                $deadlines[$cmid] = (int) $row->deadline;
            }
        }
    }

    /**
     * Resolve the deadline timestamp for a rule record.
     *
     * Priority: completionexpected → module-specific deadline field.
     *
     * @param \stdClass $record Row from the SQL join (cmid, completionexpected, instance, modname).
     * @return int|null Deadline as a Unix timestamp, or null if unavailable.
     */
    private static function resolve_deadline(\stdClass $record): ?int {
        global $DB;

        if (!empty($record->completionexpected)) {
            return (int) $record->completionexpected;
        }

        $field = self::$deadlinefields[$record->modname] ?? null;
        if (!$field) {
            return null;
        }

        $value = $DB->get_field($record->modname, $field, ['id' => $record->instance]);
        return ($value) ? (int) $value : null;
    }
}
