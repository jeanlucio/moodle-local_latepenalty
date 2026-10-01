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

use local_latepenalty\local\deadline_resolver;

/**
 * Hook listener class.
 */
class hook_listener {
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

        $now = time();
        $notices = [];
        $cms = [];
        foreach ($records as $record) {
            $cms[(int) $record->cmid] = (object) [
                'id' => (int) $record->cmid,
                'modname' => $record->modname,
                'instance' => (int) $record->instance,
                'completionexpected' => (int) $record->completionexpected,
            ];
        }

        // No notice where the plugin never acts: no numeric grade item, or the core
        // assignment penalty in charge.
        $penalisable = penalty_helper::cms_with_penalisable_items($cms, $courseid);
        $assignids = [];
        foreach ($cms as $cm) {
            if ($cm->modname === 'assign') {
                $assignids[] = $cm->instance;
            }
        }
        $native = penalty_helper::native_penalty_assignments($assignids);
        foreach ($cms as $cmid => $cm) {
            if (empty($penalisable[$cmid]) || ($cm->modname === 'assign' && !empty($native[$cm->instance]))) {
                unset($cms[$cmid], $records[$cmid]);
            }
        }
        if (empty($records)) {
            return;
        }

        $isteacher = has_capability(
            'local/latepenalty:viewreport',
            \context_course::instance($courseid)
        );

        if ($isteacher) {
            $cmids = array_map(fn($r) => (int) $r->cmid, $records);
            $pendingcounts = self::load_pending_counts($cmids, $courseid);
            $activitydeadlines = deadline_resolver::activity_deadlines($cms);

            foreach ($records as $record) {
                $cmid = (int) $record->cmid;
                $deadline = $activitydeadlines[$cmid]->time;
                if (!$deadline) {
                    continue;
                }

                $daily = (float) $record->daily_penalty;
                $max   = (float) $record->max_penalty;

                [$badgelabel, $badgestate, $notice] = self::compute_badge(
                    $deadline,
                    $daily,
                    $max,
                    $now
                );

                if ($badgestate !== 'ontime') {
                    $pending = $pendingcounts[$cmid] ?? 0;
                    if ($pending === 0) {
                        continue;
                    }
                    [$badgelabel, $notice] = self::compute_teacher_badge(
                        $deadline,
                        $daily,
                        $max,
                        $now,
                        $pending,
                        $badgestate
                    );
                }

                $notices[] = [
                    'cmid'       => $cmid,
                    'notice'     => $notice,
                    'badgelabel' => $badgelabel,
                    'badgestate' => $badgestate,
                ];
            }
        } else {
            // Drop rules for activities hidden, stealth, or access-restricted for this
            // student — the query above has no visibility filter, so without this the
            // notices payload below would reveal the deadline/rate of activities the
            // course UI hides from them.
            $modinfo = get_fast_modinfo($courseid, (int) $USER->id);
            $records = array_filter(
                $records,
                fn($record) => ($modinfo->cms[(int) $record->cmid] ?? null)?->uservisible ?? false
            );

            if (empty($records)) {
                return;
            }

            // Records are keyed by cmid, so this keeps only the visible activities.
            $userdeadlines = deadline_resolver::for_user_in_cms(array_intersect_key($cms, $records), (int) $USER->id);

            foreach ($records as $record) {
                $cmid = (int) $record->cmid;
                if (isset($completedcmids[$cmid])) {
                    continue;
                }

                $resolved = $userdeadlines[$cmid];
                if (!$resolved->exists()) {
                    continue;
                }
                $deadline = $resolved->time;
                $daily = $resolved->daily_for($record);
                $max = $resolved->max_for($record);

                [$badgelabel, $badgestate, $notice] = self::compute_badge(
                    $deadline,
                    $daily,
                    $max,
                    $now
                );

                $notices[] = [
                    'cmid'       => (int) $record->cmid,
                    'notice'     => $notice,
                    'badgelabel' => $badgelabel,
                    'badgestate' => $badgestate,
                ];
            }
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

        $isteacher = has_capability(
            'local/latepenalty:viewreport',
            \context_course::instance((int) $cm->course)
        );

        $cmrecord = (object) [
            'id' => (int) $cm->id,
            'modname' => $cm->modname,
            'instance' => (int) $cm->instance,
            'completionexpected' => (int) ($cm->completionexpected ?? 0),
        ];
        if (
            empty(penalty_helper::cms_with_penalisable_items([$cmrecord], (int) $cm->course))
            || penalty_helper::native_penalty_active($cmrecord)
        ) {
            return;
        }

        if ($isteacher) {
            $deadline = deadline_resolver::activity_deadline($cmrecord)->time;
            if (!$deadline) {
                return;
            }

            $daily = (float) $rule->daily_penalty;
            $max   = (float) $rule->max_penalty;
            [, $badgestate, $notice] = self::compute_badge($deadline, $daily, $max, time());

            if ($badgestate !== 'ontime') {
                $pending = self::count_pending_students($cm);
                if ($pending === 0) {
                    return;
                }
                [, $notice] = self::compute_teacher_badge(
                    $deadline,
                    $daily,
                    $max,
                    time(),
                    $pending,
                    $badgestate
                );
            }

            $PAGE->requires->js_call_amd('local_latepenalty/activityinfo', 'init', [$notice]);
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

        $resolved = deadline_resolver::for_user($cmrecord, (int) $USER->id);
        if (!$resolved->exists()) {
            return;
        }
        $deadline = $resolved->time;
        $daily = $resolved->daily_for($rule);
        $max = $resolved->max_for($rule);
        [, , $notice] = self::compute_badge($deadline, $daily, $max, time());

        $PAGE->requires->js_call_amd('local_latepenalty/activityinfo', 'init', [$notice]);
    }

    /**
     * Compute the teacher-specific badge label and notice for an overdue activity.
     *
     * Called only when the current user is a teacher and there are pending students.
     * Reuses the CSS state already determined by compute_badge().
     *
     * @param int    $deadline Unix timestamp of the activity deadline.
     * @param float  $daily    Daily penalty percentage.
     * @param float  $max      Maximum penalty percentage.
     * @param int    $now      Current Unix timestamp.
     * @param int    $pending  Number of students who have not yet completed the activity.
     * @param string $state    Badge state: 'warning' or 'danger'.
     * @return array{string, string} [badgelabel, notice].
     */
    private static function compute_teacher_badge(
        int $deadline,
        float $daily,
        float $max,
        int $now,
        int $pending,
        string $state
    ): array {
        $datestr = penalty_helper::format_deadline($deadline);
        $daysoverdue = (int) ceil(($now - $deadline) / DAYSECS);
        $penalty = min($daysoverdue * $daily, $max);

        if ($state === 'danger') {
            $label = get_string('badge_teacher_pending_max', 'local_latepenalty', [
                'pct'     => $max,
                'pending' => $pending,
            ]);
            $notice = get_string('courseinfo_teacher_overdue_max', 'local_latepenalty', (object) [
                'deadline' => $datestr,
                'max'      => (string) $max,
                'pending'  => $pending,
            ]);
        } else {
            $label = get_string('badge_teacher_pending', 'local_latepenalty', [
                'pct'     => $penalty,
                'pending' => $pending,
            ]);
            $notice = get_string('courseinfo_teacher_overdue', 'local_latepenalty', (object) [
                'deadline' => $datestr,
                'pct'      => (string) $penalty,
                'daily'    => (string) $daily,
                'max'      => (string) $max,
                'pending'  => $pending,
            ]);
        }

        return [$label, $notice];
    }

    /**
     * Count enrolled students who have not yet completed a given course module.
     *
     * Uses role archetype 'student' to exclude teachers and managers. Honours the
     * activity's separate-groups scoping: a caller without moodle/site:accessallgroups
     * only counts students in their own group(s), mirroring group_scope::resolve_activity_restriction()
     * as already applied on the override management pages. Returns 0 if no students
     * are enrolled, or if the caller belongs to no group at all in a restricted activity.
     *
     * @param \cm_info $cm Course module info for the activity.
     * @return int Number of students without completionstate >= 1 for this CM.
     */
    private static function count_pending_students(\cm_info $cm): int {
        $courseid = (int) $cm->course;
        $contextid = \context_course::instance($courseid)->id;
        $modcontext = \context_module::instance((int) $cm->id);
        $restrictgroupids = group_scope::resolve_activity_restriction($cm, $modcontext);

        $counts = self::count_pending_for_cmids([(int) $cm->id], $contextid, $restrictgroupids);

        return $counts[(int) $cm->id] ?? 0;
    }

    /**
     * Bulk-load pending student counts for multiple course modules.
     *
     * Mirrors report\controller::resolve_group_restriction(): a caller without
     * moodle/site:accessallgroups in the course is confined to their own group(s)
     * for every course module whose effective group mode is SEPARATEGROUPS, using
     * a single get_fast_modinfo() call to resolve each cm's groupmode (no query in
     * the loop — get_fast_modinfo() preloads every module context/groupmode for
     * the course in bulk). Unrestricted and restricted CMs are then each counted
     * in one shared query, not per-activity.
     *
     * @param int[] $cmids    Course module IDs to count for.
     * @param int   $courseid Course ID.
     * @return array<int, int> Map of cmid => pending student count.
     */
    private static function load_pending_counts(array $cmids, int $courseid): array {
        global $USER;

        if (empty($cmids)) {
            return [];
        }

        $coursecontext = \context_course::instance($courseid);
        $contextid = $coursecontext->id;

        if (has_capability('moodle/site:accessallgroups', $coursecontext)) {
            return self::count_pending_for_cmids($cmids, $contextid, null);
        }

        $modinfo = get_fast_modinfo($courseid);
        $restrictedcmids = [];
        $unrestrictedcmids = [];
        foreach ($cmids as $cmid) {
            $cm = $modinfo->cms[$cmid] ?? null;
            if ($cm && (int) groups_get_activity_groupmode($cm) === SEPARATEGROUPS) {
                $restrictedcmids[] = $cmid;
            } else {
                $unrestrictedcmids[] = $cmid;
            }
        }

        $result = self::count_pending_for_cmids($unrestrictedcmids, $contextid, null);

        if (!empty($restrictedcmids)) {
            $callergroupids = array_keys(groups_get_all_groups($courseid, (int) $USER->id));
            $result += self::count_pending_for_cmids($restrictedcmids, $contextid, $callergroupids);
        }

        return $result;
    }

    /**
     * Bulk-count pending students for a set of course modules, optionally confined
     * to specific group IDs.
     *
     * Runs at most two queries for the whole set: one for total eligible students,
     * one for per-CM completion counts. No per-activity loop queries.
     *
     * @param int[]      $cmids     Course module IDs to count for.
     * @param int        $contextid Course context ID.
     * @param int[]|null $groupids  Group IDs to confine the count to, or null for no restriction.
     * @return array<int, int> Map of cmid => pending student count.
     */
    private static function count_pending_for_cmids(array $cmids, int $contextid, ?array $groupids): array {
        if (empty($cmids)) {
            return [];
        }

        if ($groupids === []) {
            // Caller belongs to no group at all in a restricted activity: sees nothing.
            return array_fill_keys($cmids, 0);
        }

        global $DB;

        [$groupjoin, $groupparams] = self::group_scope_join('ra.userid', $groupids);

        $total = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT ra.userid)
               FROM {role_assignments} ra
               JOIN {role} r ON r.id = ra.roleid AND r.archetype = 'student'
               $groupjoin
              WHERE ra.contextid = :contextid",
            array_merge(['contextid' => $contextid], $groupparams)
        );

        if ($total === 0) {
            return array_fill_keys($cmids, 0);
        }

        [$insql, $inparams] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');
        $completedrows = $DB->get_records_sql(
            "SELECT cmc.coursemoduleid AS cmid, COUNT(DISTINCT cmc.userid) AS cnt
               FROM {course_modules_completion} cmc
               JOIN {role_assignments} ra ON ra.userid = cmc.userid AND ra.contextid = :contextid
               JOIN {role} r ON r.id = ra.roleid AND r.archetype = 'student'
               $groupjoin
              WHERE cmc.coursemoduleid $insql
                AND cmc.completionstate >= 1
           GROUP BY cmc.coursemoduleid",
            array_merge(['contextid' => $contextid], $groupparams, $inparams)
        );

        $result = [];
        foreach ($cmids as $cmid) {
            $cmid = (int) $cmid;
            $done = isset($completedrows[$cmid]) ? (int) $completedrows[$cmid]->cnt : 0;
            $result[$cmid] = max(0, $total - $done);
        }

        return $result;
    }

    /**
     * Build a group-membership JOIN restricting a pending-count query to specific
     * groups, or no restriction when $groupids is null.
     *
     * Callers must handle an empty $groupids array before reaching this method —
     * get_in_or_equal() rejects an empty list, and an empty group set means the
     * caller belongs to no group at all (sees nothing), not "every group".
     *
     * @param string $useridcolumn Column holding the user ID to join against, e.g. 'ra.userid'.
     * @param int[]|null $groupids Group IDs to confine to, or null for no restriction.
     * @return array{0: string, 1: array} [JOIN SQL fragment ('' when unrestricted), params].
     */
    private static function group_scope_join(string $useridcolumn, ?array $groupids): array {
        if ($groupids === null) {
            return ['', []];
        }

        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'gscope');

        return [" JOIN {groups_members} gms ON gms.userid = $useridcolumn AND gms.groupid $insql", $inparams];
    }

    /**
     * Compute the badge label, CSS state, and tooltip notice for a given deadline and penalty rule.
     *
     * @param int   $deadline Unix timestamp of the activity deadline.
     * @param float $daily    Daily penalty percentage.
     * @param float $max      Maximum penalty percentage.
     * @param int   $now      Current Unix timestamp.
     * @return array{string, string, string} [badgelabel, badgestate, notice] where state is ontime|warning|danger.
     */
    private static function compute_badge(
        int $deadline,
        float $daily,
        float $max,
        int $now
    ): array {
        $datestr = penalty_helper::format_deadline($deadline);

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
}
