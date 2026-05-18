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
 * Shared penalty calculation helpers used by the observer and the recalculator.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty;

/**
 * Static helpers for deadline resolution, submission lookup, and grade maths.
 */
class penalty_helper {
    /**
     * Map of module name to its deadline field in the module table.
     *
     * @var array<string, string>
     */
    public static array $deadlinefields = [
        'assign'      => 'duedate',
        'forum'       => 'duedate',
        'lesson'      => 'deadline',
        'playergroup' => 'timeclose',
        'quiz'        => 'timeclose',
        'scorm'       => 'timeclose',
        'workshop'    => 'submissionend',
    ];

    /**
     * Modules where the grade event itself represents the student action.
     * Submission records do not exist for these; the event timestamp is used instead.
     *
     * @var array<int, string>
     */
    public static array $autogradedmodules = [
        'lesson',
        'playergroup',
        'scorm',
    ];

    /**
     * Get the resolved deadline for a course module.
     *
     * Priority: completionexpected → module-specific deadline field.
     *
     * @param \stdClass $cm Course module object.
     * @return int|null Deadline timestamp, or null if unavailable.
     */
    public static function get_deadline(\stdClass $cm): ?int {
        global $DB;

        if (!empty($cm->completionexpected)) {
            return (int) $cm->completionexpected;
        }

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
     * Returns null for unsupported module types or when no submission exists.
     *
     * @param int       $userid User ID.
     * @param \stdClass $cm     Course module object.
     * @return int|null Submission timestamp or null if unavailable.
     */
    public static function get_submission_time(int $userid, \stdClass $cm): ?int {
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
                if ($row) {
                    return (int) $row->timemodified;
                }

                // Fall back to team submission: userid = 0, keyed by groupid.
                $courseid = (int) ($cm->course ?? 0);
                if (!$courseid) {
                    return null;
                }
                $usergroups = groups_get_user_groups($courseid, $userid);
                $groupids = array_values($usergroups[0] ?? []);
                if (empty($groupids)) {
                    return null;
                }
                [$insql, $inparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'grp');
                $row = $DB->get_record_sql(
                    "SELECT timemodified
                       FROM {assign_submission}
                      WHERE assignment = :assignment
                        AND userid = 0
                        AND status = 'submitted'
                        AND groupid $insql
                   ORDER BY timemodified DESC",
                    array_merge(['assignment' => $cm->instance], $inparams),
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
     * Get the per-user override record for a course module, if one exists.
     *
     * @param int $cmid   Course module ID.
     * @param int $userid User ID.
     * @return \stdClass|null Override record or null if none exists.
     */
    public static function get_override(int $cmid, int $userid): ?\stdClass {
        global $DB;
        return $DB->get_record('local_latepenalty_overrides', ['cmid' => $cmid, 'userid' => $userid]) ?: null;
    }

    /**
     * Calculate the number of days a submission is late.
     *
     * @param int $submissiontime Timestamp when the student submitted.
     * @param int $deadline       Deadline timestamp.
     * @return int Number of days late (0 if on time).
     */
    public static function calculate_days_late(int $submissiontime, int $deadline): int {
        if ($submissiontime <= $deadline) {
            return 0;
        }

        return (int) ceil(($submissiontime - $deadline) / DAYSECS);
    }

    /**
     * Apply the late penalty to a raw grade.
     *
     * @param float $rawgrade    Original grade before penalty.
     * @param int   $dayslate    Number of days late.
     * @param float $dailypenalty Daily penalty percentage (0–100).
     * @param float $maxpenalty  Maximum penalty cap percentage (0–100).
     * @return float Final grade after penalty (never below 0).
     */
    public static function apply_penalty(
        float $rawgrade,
        int $dayslate,
        float $dailypenalty,
        float $maxpenalty
    ): float {
        $discountpct = min($dayslate * $dailypenalty, $maxpenalty);
        $finalgrade  = $rawgrade * (1.0 - $discountpct / 100.0);

        return max(0.0, $finalgrade);
    }
}
