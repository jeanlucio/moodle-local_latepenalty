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
     * Get the effective deadline for a specific user considering module-native overrides.
     *
     * Reads the module's own override/extension tables before falling back to the
     * global activity deadline. Returns null for modules that have no native
     * per-user override system (forum, workshop, scorm, playergroup).
     *
     * Priority within each module:
     *  - assign: user override → best group override (via assign_overrides)
     *  - quiz:   user override → best group override
     *  - lesson: user override → best group override
     *
     * "Best" group override = the latest (most favourable) deadline among all groups
     * the user belongs to, mirroring Moodle's native behaviour.
     *
     * @param string $modname    Module name (e.g. 'assign', 'quiz').
     * @param int    $instanceid Module instance ID.
     * @param int    $userid     User ID.
     * @return int|null Effective deadline timestamp, or null if no native override exists.
     */
    public static function get_module_user_deadline(string $modname, int $instanceid, int $userid): ?int {
        switch ($modname) {
            case 'assign':
                return self::get_assign_user_deadline($instanceid, $userid);
            case 'quiz':
                return self::get_quiz_user_deadline($instanceid, $userid);
            case 'lesson':
                return self::get_lesson_user_deadline($instanceid, $userid);
            default:
                return null;
        }
    }

    /**
     * Resolve the effective deadline for a student in an Assignment.
     *
     * In Moodle 5.2+, individual extensions are stored as user-specific records in
     * assign_overrides (the assign_user_flags.extensiondue column was removed).
     *
     * @param int $assignid Assignment instance ID.
     * @param int $userid   User ID.
     * @return int|null Effective deadline or null.
     */
    private static function get_assign_user_deadline(int $assignid, int $userid): ?int {
        global $DB;

        // User-specific due date override (covers both scheduled overrides and teacher-granted extensions).
        $row = $DB->get_record('assign_overrides', ['assignid' => $assignid, 'userid' => $userid], 'duedate');
        if ($row && !empty($row->duedate)) {
            return (int) $row->duedate;
        }

        // Best (latest) group override.
        $row = $DB->get_record_sql(
            "SELECT MAX(ao.duedate) AS duedate
               FROM {assign_overrides} ao
               JOIN {groups_members} gm ON gm.groupid = ao.groupid
              WHERE ao.assignid = :assignid
                AND gm.userid = :userid
                AND ao.duedate > 0",
            ['assignid' => $assignid, 'userid' => $userid],
            IGNORE_MISSING
        );
        return ($row && !empty($row->duedate)) ? (int) $row->duedate : null;
    }

    /**
     * Resolve the effective closing time for a student in a Quiz.
     *
     * @param int $quizid Quiz instance ID.
     * @param int $userid User ID.
     * @return int|null Effective deadline or null.
     */
    private static function get_quiz_user_deadline(int $quizid, int $userid): ?int {
        global $DB;

        // User-specific timeclose override.
        $row = $DB->get_record('quiz_overrides', ['quiz' => $quizid, 'userid' => $userid], 'timeclose');
        if ($row && !empty($row->timeclose)) {
            return (int) $row->timeclose;
        }

        // Best (latest) group override.
        $row = $DB->get_record_sql(
            "SELECT MAX(qo.timeclose) AS timeclose
               FROM {quiz_overrides} qo
               JOIN {groups_members} gm ON gm.groupid = qo.groupid
              WHERE qo.quiz = :quizid
                AND gm.userid = :userid
                AND qo.timeclose > 0",
            ['quizid' => $quizid, 'userid' => $userid],
            IGNORE_MISSING
        );
        return ($row && !empty($row->timeclose)) ? (int) $row->timeclose : null;
    }

    /**
     * Resolve the effective deadline for a student in a Lesson.
     *
     * @param int $lessonid Lesson instance ID.
     * @param int $userid   User ID.
     * @return int|null Effective deadline or null.
     */
    private static function get_lesson_user_deadline(int $lessonid, int $userid): ?int {
        global $DB;

        // User-specific deadline override.
        $row = $DB->get_record('lesson_overrides', ['lessonid' => $lessonid, 'userid' => $userid], 'deadline');
        if ($row && !empty($row->deadline)) {
            return (int) $row->deadline;
        }

        // Best (latest) group override.
        $row = $DB->get_record_sql(
            "SELECT MAX(lo.deadline) AS deadline
               FROM {lesson_overrides} lo
               JOIN {groups_members} gm ON gm.groupid = lo.groupid
              WHERE lo.lessonid = :lessonid
                AND gm.userid = :userid
                AND lo.deadline > 0",
            ['lessonid' => $lessonid, 'userid' => $userid],
            IGNORE_MISSING
        );
        return ($row && !empty($row->deadline)) ? (int) $row->deadline : null;
    }

    /**
     * Bulk-load submission times for multiple users in a single course module.
     *
     * Replaces N individual get_submission_time() calls with at most two queries
     * per module type. Returns null for users without a qualifying submission.
     *
     * @param int[]     $userids Array of user IDs.
     * @param \stdClass $cm      Course module object.
     * @return array<int, int|null> Map of userid → submission timestamp (null if not found).
     */
    public static function get_submission_times_bulk(array $userids, \stdClass $cm): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        $result = array_fill_keys($userids, null);
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');

        switch ($cm->modname) {
            case 'assign':
                $rows = $DB->get_records_sql(
                    "SELECT userid, MAX(timemodified) AS timemodified
                       FROM {assign_submission}
                      WHERE assignment = :assignment
                        AND userid $insql
                        AND status = 'submitted'
                   GROUP BY userid",
                    array_merge(['assignment' => $cm->instance], $inparams)
                );
                foreach ($rows as $row) {
                    $result[(int) $row->userid] = (int) $row->timemodified;
                }

                // Group submission fallback for users without an individual submission.
                $missingids = array_keys(array_filter($result, fn($v) => $v === null));
                if (!empty($missingids)) {
                    [$missingsql, $missingparams] = $DB->get_in_or_equal(
                        $missingids,
                        SQL_PARAMS_NAMED,
                        'mu'
                    );
                    $grouprows = $DB->get_records_sql(
                        "SELECT gm.userid, MAX(s.timemodified) AS timemodified
                           FROM {groups_members} gm
                           JOIN {assign_submission} s ON s.groupid = gm.groupid
                          WHERE gm.userid $missingsql
                            AND s.assignment = :assignment
                            AND s.userid = 0
                            AND s.status = 'submitted'
                       GROUP BY gm.userid",
                        array_merge(['assignment' => $cm->instance], $missingparams)
                    );
                    foreach ($grouprows as $grow) {
                        $result[(int) $grow->userid] = (int) $grow->timemodified;
                    }
                }
                break;

            case 'quiz':
                $rows = $DB->get_records_sql(
                    "SELECT userid, MAX(timefinish) AS timefinish
                       FROM {quiz_attempts}
                      WHERE quiz = :quiz
                        AND userid $insql
                        AND state = 'finished'
                   GROUP BY userid",
                    array_merge(['quiz' => $cm->instance], $inparams)
                );
                foreach ($rows as $row) {
                    if (!empty($row->timefinish)) {
                        $result[(int) $row->userid] = (int) $row->timefinish;
                    }
                }
                break;

            case 'workshop':
                $rows = $DB->get_records_sql(
                    "SELECT authorid AS userid, MAX(timemodified) AS timemodified
                       FROM {workshop_submissions}
                      WHERE workshopid = :workshopid
                        AND authorid $insql
                   GROUP BY authorid",
                    array_merge(['workshopid' => $cm->instance], $inparams)
                );
                foreach ($rows as $row) {
                    $result[(int) $row->userid] = (int) $row->timemodified;
                }
                break;

            case 'forum':
                $rows = $DB->get_records_sql(
                    "SELECT p.userid, MAX(p.created) AS lastpost
                       FROM {forum_posts} p
                       JOIN {forum_discussions} d ON d.id = p.discussion
                      WHERE d.forum = :forum
                        AND p.userid $insql
                   GROUP BY p.userid",
                    array_merge(['forum' => $cm->instance], $inparams)
                );
                foreach ($rows as $row) {
                    if (!empty($row->lastpost)) {
                        $result[(int) $row->userid] = (int) $row->lastpost;
                    }
                }
                break;
        }

        return $result;
    }

    /**
     * Bulk-load effective native-module deadlines for multiple users.
     *
     * Mirrors get_module_user_deadline() but resolves all users in at most two
     * queries per module type instead of two per student. User-specific overrides
     * take priority over group overrides, mirroring Moodle's native behaviour.
     * Returns null for modules without native per-user override tables.
     *
     * @param string $modname    Module name (e.g. 'assign', 'quiz').
     * @param int    $instanceid Module instance ID.
     * @param int[]  $userids    Array of user IDs.
     * @return array<int, int|null> Map of userid → effective deadline (null if none).
     */
    public static function get_module_user_deadlines_bulk(
        string $modname,
        int $instanceid,
        array $userids
    ): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        $result = array_fill_keys($userids, null);
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');

        switch ($modname) {
            case 'assign':
                $rows = $DB->get_records_sql(
                    "SELECT userid, duedate
                       FROM {assign_overrides}
                      WHERE assignid = :assignid
                        AND userid $insql
                        AND duedate > 0",
                    array_merge(['assignid' => $instanceid], $inparams)
                );
                foreach ($rows as $row) {
                    $result[(int) $row->userid] = (int) $row->duedate;
                }

                $missingids = array_keys(array_filter($result, fn($v) => $v === null));
                if (!empty($missingids)) {
                    [$missingsql, $missingparams] = $DB->get_in_or_equal(
                        $missingids,
                        SQL_PARAMS_NAMED,
                        'mu'
                    );
                    $grouprows = $DB->get_records_sql(
                        "SELECT gm.userid, MAX(ao.duedate) AS duedate
                           FROM {assign_overrides} ao
                           JOIN {groups_members} gm ON gm.groupid = ao.groupid
                          WHERE ao.assignid = :assignid
                            AND gm.userid $missingsql
                            AND ao.duedate > 0
                       GROUP BY gm.userid",
                        array_merge(['assignid' => $instanceid], $missingparams)
                    );
                    foreach ($grouprows as $grow) {
                        if (!empty($grow->duedate)) {
                            $result[(int) $grow->userid] = (int) $grow->duedate;
                        }
                    }
                }
                break;

            case 'quiz':
                $rows = $DB->get_records_sql(
                    "SELECT userid, timeclose
                       FROM {quiz_overrides}
                      WHERE quiz = :quizid
                        AND userid $insql
                        AND timeclose > 0",
                    array_merge(['quizid' => $instanceid], $inparams)
                );
                foreach ($rows as $row) {
                    $result[(int) $row->userid] = (int) $row->timeclose;
                }

                $missingids = array_keys(array_filter($result, fn($v) => $v === null));
                if (!empty($missingids)) {
                    [$missingsql, $missingparams] = $DB->get_in_or_equal(
                        $missingids,
                        SQL_PARAMS_NAMED,
                        'mu'
                    );
                    $grouprows = $DB->get_records_sql(
                        "SELECT gm.userid, MAX(qo.timeclose) AS timeclose
                           FROM {quiz_overrides} qo
                           JOIN {groups_members} gm ON gm.groupid = qo.groupid
                          WHERE qo.quiz = :quizid
                            AND gm.userid $missingsql
                            AND qo.timeclose > 0
                       GROUP BY gm.userid",
                        array_merge(['quizid' => $instanceid], $missingparams)
                    );
                    foreach ($grouprows as $grow) {
                        if (!empty($grow->timeclose)) {
                            $result[(int) $grow->userid] = (int) $grow->timeclose;
                        }
                    }
                }
                break;

            case 'lesson':
                $rows = $DB->get_records_sql(
                    "SELECT userid, deadline
                       FROM {lesson_overrides}
                      WHERE lessonid = :lessonid
                        AND userid $insql
                        AND deadline > 0",
                    array_merge(['lessonid' => $instanceid], $inparams)
                );
                foreach ($rows as $row) {
                    $result[(int) $row->userid] = (int) $row->deadline;
                }

                $missingids = array_keys(array_filter($result, fn($v) => $v === null));
                if (!empty($missingids)) {
                    [$missingsql, $missingparams] = $DB->get_in_or_equal(
                        $missingids,
                        SQL_PARAMS_NAMED,
                        'mu'
                    );
                    $grouprows = $DB->get_records_sql(
                        "SELECT gm.userid, MAX(lo.deadline) AS deadline
                           FROM {lesson_overrides} lo
                           JOIN {groups_members} gm ON gm.groupid = lo.groupid
                          WHERE lo.lessonid = :lessonid
                            AND gm.userid $missingsql
                            AND lo.deadline > 0
                       GROUP BY gm.userid",
                        array_merge(['lessonid' => $instanceid], $missingparams)
                    );
                    foreach ($grouprows as $grow) {
                        if (!empty($grow->deadline)) {
                            $result[(int) $grow->userid] = (int) $grow->deadline;
                        }
                    }
                }
                break;
        }

        return $result;
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
     * @param float $rawgrade     Original grade before penalty.
     * @param int   $dayslate     Number of days late.
     * @param float $dailypenalty Daily penalty percentage (0–100).
     * @param float $maxpenalty   Maximum penalty cap percentage (0–100).
     * @param float $grademin     Minimum allowed grade (floor). Defaults to 0.
     * @return float Final grade after penalty (never below $grademin).
     */
    public static function apply_penalty(
        float $rawgrade,
        int $dayslate,
        float $dailypenalty,
        float $maxpenalty,
        float $grademin = 0.0
    ): float {
        $discountpct = min($dayslate * $dailypenalty, $maxpenalty);
        $finalgrade  = $rawgrade * (1.0 - $discountpct / 100.0);

        return max($grademin, $finalgrade);
    }
}
