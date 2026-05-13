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
     * @var array<string, string>
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
        global $DB, $PAGE;

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

        $dateformat = get_string('strftimedatefullshort', 'langconfig');
        $notices = [];

        foreach ($records as $record) {
            $deadline = self::resolve_deadline($record);
            if (!$deadline) {
                continue;
            }

            $daily = (string) (float) $record->daily_penalty;
            $max   = (string) (float) $record->max_penalty;

            $notices[] = [
                'cmid'   => (int) $record->cmid,
                'notice' => get_string('courseinfo_notice', 'local_latepenalty', (object) [
                    'deadline' => userdate($deadline, $dateformat),
                    'daily'    => $daily,
                    'max'      => $max,
                ]),
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
        global $DB, $PAGE;

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

        $record = (object) [
            'completionexpected' => $cm->completionexpected ?? 0,
            'instance'           => $cm->instance,
            'modname'            => $cm->modname,
        ];

        $deadline = self::resolve_deadline($record);
        if (!$deadline) {
            return;
        }

        $dateformat = get_string('strftimedatefullshort', 'langconfig');
        $notice = get_string('courseinfo_notice', 'local_latepenalty', (object) [
            'deadline' => userdate($deadline, $dateformat),
            'daily'    => (string) (float) $rule->daily_penalty,
            'max'      => (string) (float) $rule->max_penalty,
        ]);

        $PAGE->requires->js_call_amd('local_latepenalty/activityinfo', 'init', [$notice]);
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
