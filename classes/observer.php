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
 * Event observer for applying late penalties to grades.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty;

/**
 * Observer class for handling grade events.
 */
class observer {
    /**
     * Static array to prevent recursive processing of the same grade.
     *
     * @var array
     */
    private static $processing = [];

    /**
     * Handle the user_graded event and apply late penalty if applicable.
     *
     * @param \core\event\user_graded $event The grade event.
     * @return void
     */
    public static function user_graded(\core\event\user_graded $event): void {
        global $DB;

        // Extract event data.
        $eventdata = $event->get_data();
        $userid = $event->relateduserid;
        $contextid = $event->contextid;

        // Validate event data.
        if (empty($userid) || empty($contextid)) {
            debugging('local_latepenalty: Invalid event data (missing userid or contextid)', DEBUG_DEVELOPER);
            return;
        }

        // Get context and course module.
        $context = \context::instance_by_id($contextid, IGNORE_MISSING);
        if (!$context || $context->contextlevel != CONTEXT_MODULE) {
            return;
        }

        $cmid = $context->instanceid;

        // Prevent recursive processing.
        $key = $userid . '_' . $cmid;
        if (isset(self::$processing[$key])) {
            return;
        }

        self::$processing[$key] = true;

        try {
            self::process_penalty($userid, $cmid, $eventdata);
        } finally {
            unset(self::$processing[$key]);
        }
    }

    /**
     * Process the late penalty calculation and application.
     *
     * @param int $userid User ID.
     * @param int $cmid Course module ID.
     * @param array $eventdata Event data array.
     * @return void
     */
    private static function process_penalty(int $userid, int $cmid, array $eventdata): void {
        global $DB;

        // Get penalty rule for this course module.
        $rule = $DB->get_record('local_latepenalty_rules', ['cmid' => $cmid]);

        if (!$rule || !$rule->enabled) {
            return;
        }

        // Get course module details.
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);

        // Get deadline (completionexpected or timeclose).
        $deadline = self::get_deadline($cm);

        if (!$deadline) {
            debugging(
                'local_latepenalty: No valid deadline for cmid ' . $cmid,
                DEBUG_DEVELOPER
            );
            return;
        }

        // Get submission timestamp (when the student submitted, not when graded).
        // For auto-graded modules the grade event itself is the student action, so
        // use the event timestamp when no explicit submission record exists.
        // For manually-graded modules (e.g. forum), if no student action is found
        // there is nothing to measure lateness against — skip the penalty.
        $submissiontime = self::get_submission_time($userid, $cm);

        if ($submissiontime === null) {
            if (in_array($cm->modname, self::$autogradedmodules)) {
                $submissiontime = (int) ($eventdata['timecreated'] ?? time());
            } else {
                debugging(
                    'local_latepenalty: No submission found for userid ' . $userid . ' cmid ' . $cmid . ', skipping penalty',
                    DEBUG_DEVELOPER
                );
                return;
            }
        }

        // Calculate days late.
        $dayslate = self::calculate_days_late($submissiontime, $deadline);

        if ($dayslate <= 0) {
            return;
        }

        // Get the grade item.
        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
            'courseid' => $cm->course,
        ]);

        if (!$gradeitem) {
            debugging('local_latepenalty: Grade item not found for cmid ' . $cmid, DEBUG_DEVELOPER);
            return;
        }

        // Get the user's grade.
        $grade = new \grade_grade(['itemid' => $gradeitem->id, 'userid' => $userid]);
        $grade->load_optional_fields();

        if (empty($grade->finalgrade)) {
            return;
        }

        $rawgrade = $grade->finalgrade;

        // Calculate penalty.
        $finalgrade = self::apply_penalty($rawgrade, $dayslate, $rule->daily_penalty, $rule->max_penalty);

        // Update grade if changed.
        if (abs($finalgrade - $rawgrade) > 0.01) {
            $gradeitem->update_final_grade(
                $userid,
                $finalgrade,
                'local_latepenalty',
                false,
                FORMAT_MOODLE,
                null,
                null,
                true
            );
        }
    }

    /**
     * Modules where the grade event itself represents the student's action.
     * For these, the event timestamp is used when no submission record exists.
     *
     * @var array
     */
    private static $autogradedmodules = [
        'lesson',
        'playergroup',
        'scorm',
    ];

    /**
     * Map of module name to its deadline field in the module table.
     *
     * @var array
     */
    private static $deadlinefields = [
        'assign'      => 'duedate',
        'forum'       => 'duedate',
        'lesson'      => 'deadline',
        'playergroup' => 'timeclose',
        'quiz'        => 'timeclose',
        'scorm'       => 'timeclose',
        'workshop'    => 'submissionend',
    ];

    /**
     * Get the deadline for a course module.
     *
     * @param \stdClass $cm Course module object.
     * @return int|null Deadline timestamp or null if not available.
     */
    private static function get_deadline(\stdClass $cm): ?int {
        global $DB;

        // Priority 1: completionexpected.
        if (!empty($cm->completionexpected)) {
            return $cm->completionexpected;
        }

        // Priority 2: module-specific deadline field.
        $field = self::$deadlinefields[$cm->modname] ?? null;

        if (!$field) {
            return null;
        }

        $instance = $DB->get_record($cm->modname, ['id' => $cm->instance], $field);

        if ($instance && !empty($instance->$field)) {
            return (int) $instance->$field;
        }

        return null;
    }

    /**
     * Get the submission timestamp for a user in a given course module.
     *
     * Returns null for unsupported module types.
     *
     * @param int $userid User ID.
     * @param \stdClass $cm Course module object.
     * @return int|null Submission timestamp or null if unavailable.
     */
    private static function get_submission_time(int $userid, \stdClass $cm): ?int {
        global $DB;

        switch ($cm->modname) {
            case 'assign':
                $row = $DB->get_record_sql(
                    "SELECT timemodified
                       FROM {assign_submission}
                      WHERE assignment = :assignment
                        AND userid = :userid
                        AND status = 'submitted'
                   ORDER BY timemodified DESC",
                    ['assignment' => $cm->instance, 'userid' => $userid],
                    IGNORE_MISSING
                );
                return $row ? (int) $row->timemodified : null;

            case 'quiz':
                $row = $DB->get_record_sql(
                    "SELECT timefinish
                       FROM {quiz_attempts}
                      WHERE quiz = :quiz
                        AND userid = :userid
                        AND state = 'finished'
                   ORDER BY timefinish DESC",
                    ['quiz' => $cm->instance, 'userid' => $userid],
                    IGNORE_MISSING
                );
                return ($row && !empty($row->timefinish)) ? (int) $row->timefinish : null;

            case 'workshop':
                $row = $DB->get_record_sql(
                    "SELECT timemodified
                       FROM {workshop_submissions}
                      WHERE workshopid = :workshopid
                        AND authorid = :userid
                   ORDER BY timemodified DESC",
                    ['workshopid' => $cm->instance, 'userid' => $userid],
                    IGNORE_MISSING
                );
                return $row ? (int) $row->timemodified : null;

            case 'forum':
                $row = $DB->get_record_sql(
                    "SELECT MAX(p.created) AS lastpost
                       FROM {forum_posts} p
                       JOIN {forum_discussions} d ON d.id = p.discussion
                      WHERE d.forum = :forum
                        AND p.userid = :userid",
                    ['forum' => $cm->instance, 'userid' => $userid],
                    IGNORE_MISSING
                );
                return ($row && !empty($row->lastpost)) ? (int) $row->lastpost : null;

            default:
                return null;
        }
    }

    /**
     * Calculate the number of days late.
     *
     * @param int $gradedtime Timestamp when grade was created.
     * @param int $deadline Deadline timestamp.
     * @return int Number of days late (0 if not late).
     */
    private static function calculate_days_late(int $gradedtime, int $deadline): int {
        if ($gradedtime <= $deadline) {
            return 0;
        }

        $secondslate = $gradedtime - $deadline;
        $dayslate = ceil($secondslate / 86400);

        return (int) $dayslate;
    }

    /**
     * Apply the late penalty to a grade.
     *
     * @param float $rawgrade Original grade.
     * @param int $dayslate Number of days late.
     * @param float $dailypenalty Daily penalty percentage.
     * @param float $maxpenalty Maximum penalty percentage.
     * @return float Final grade after penalty.
     */
    private static function apply_penalty(
        float $rawgrade,
        int $dayslate,
        float $dailypenalty,
        float $maxpenalty
    ): float {
        // Calculate total discount.
        $totaldiscount = $dayslate * $dailypenalty;

        // Apply maximum cap.
        $finaldiscount = min($totaldiscount, $maxpenalty);

        // Calculate penalty multiplier.
        $multiplier = 1 - ($finaldiscount / 100);

        // Calculate final grade.
        $finalgrade = $rawgrade * $multiplier;

        // Ensure grade is not negative.
        return max(0, $finalgrade);
    }
}
