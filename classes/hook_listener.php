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
use local_latepenalty\local\submission_resolver;

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
            $pendingcounts = self::load_pending_counts($cms, $courseid);
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
            $visiblecms = array_intersect_key($cms, $records);
            $userdeadlines = deadline_resolver::for_user_in_cms($visiblecms, (int) $USER->id);
            // No badge once the student has handed the work in (activity completion may not involve that).
            $handedin = submission_resolver::handed_in($visiblecms, [(int) $USER->id]);

            foreach ($records as $record) {
                $cmid = (int) $record->cmid;
                if (!empty($handedin[$cmid])) {
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

        // No notice once the student has handed the work in.
        if (!empty(submission_resolver::handed_in([$cmrecord], [(int) $USER->id])[$cmrecord->id])) {
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
     * @param int    $pending  Number of students who have not handed the work in yet.
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
     * Count enrolled students who have not handed a given activity in yet.
     *
     * Counts the students the gradebook grades (see penalty_helper::graded_student_ids()). Honours the
     * activity's separate-groups scoping: a caller without moodle/site:accessallgroups
     * only counts students in their own group(s), mirroring group_scope::resolve_activity_restriction()
     * as already applied on the override management pages. Returns 0 if no students
     * are enrolled, or if the caller belongs to no group at all in a restricted activity.
     *
     * @param \cm_info $cm Course module info for the activity.
     * @return int Number of students who have not handed the activity in.
     */
    private static function count_pending_students(\cm_info $cm): int {
        $coursecontext = \context_course::instance((int) $cm->course);
        $restrictgroupids = group_scope::resolve_activity_restriction($cm, \context_module::instance((int) $cm->id));

        $students = penalty_helper::graded_student_ids($coursecontext, $restrictgroupids);
        $cmrecord = (object) ['id' => (int) $cm->id, 'modname' => $cm->modname, 'instance' => (int) $cm->instance];
        $handedin = submission_resolver::handed_in([$cmrecord], $students);
        return count($students) - count($handedin[$cmrecord->id]);
    }

    /**
     * Bulk-load pending student counts for multiple course modules.
     *
     * Mirrors report\controller::resolve_group_restriction(): a caller without
     * moodle/site:accessallgroups in the course is confined to their own group(s)
     * for every course module whose effective group mode is SEPARATEGROUPS, using
     * a single get_fast_modinfo() call to resolve each cm's groupmode (no query in
     * the loop). The students are loaded at most twice (all, and the caller's
     * groups) and who handed what in is loaded once for every activity.
     *
     * @param \stdClass[] $cms Course modules (id, modname, instance), keyed by ID.
     * @param int $courseid Course ID.
     * @return array Pending student counts keyed by course module ID.
     */
    private static function load_pending_counts(array $cms, int $courseid): array {
        global $USER;

        $coursecontext = \context_course::instance($courseid);

        $restricted = [];
        if (!has_capability('moodle/site:accessallgroups', $coursecontext)) {
            $modinfo = get_fast_modinfo($courseid);
            foreach ($cms as $cmid => $cm) {
                $info = $modinfo->cms[$cmid] ?? null;
                if ($info && (int) groups_get_activity_groupmode($info) === SEPARATEGROUPS) {
                    $restricted[$cmid] = true;
                }
            }
        }

        $allstudents = count($restricted) < count($cms) ? penalty_helper::graded_student_ids($coursecontext, null) : [];
        $groupstudents = [];
        if (!empty($restricted)) {
            $callergroupids = array_keys(groups_get_all_groups($courseid, (int) $USER->id));
            $groupstudents = penalty_helper::graded_student_ids($coursecontext, $callergroupids);
        }

        $handedin = submission_resolver::handed_in($cms, array_merge($allstudents, $groupstudents));
        $result = [];
        foreach ($cms as $cmid => $cm) {
            $students = isset($restricted[$cmid]) ? $groupstudents : $allstudents;
            $result[$cmid] = count(array_diff_key(array_flip($students), $handedin[$cmid]));
        }
        return $result;
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
