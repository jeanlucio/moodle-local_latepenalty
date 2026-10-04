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
 * When a student handed in the work behind their current grade.
 *
 * The time is always the student's own action, never the time of grading:
 *  - assign: the latest submitted submission (the student's, else their group's);
 *  - quiz: the attempt that produced the grade, by grading method (first, last,
 *    highest - ties to the earliest -, average -> the last counted attempt),
 *    choosing among the attempts quiz_get_user_attempts() counts;
 *  - lesson: the attempt lesson_get_user_grades() uses (no retakes -> first,
 *    highest grade -> that attempt, mean -> the last);
 *  - forum, glossary and database ratings: the item behind the aggregate
 *    (maximum -> the item with the highest rating, minimum -> the lowest,
 *    other aggregations -> the latest rated item), using its creation time;
 *  - whole-forum grading: the student's latest post;
 *  - workshop: the latest change to the submission;
 *  - any other module: the submission date it reports to the gradebook
 *    (grade_grade::get_datesubmitted()), else the grading date it reports
 *    (or the time it wrote the grade).
 *
 * All lookups are bulk: the number of queries does not depend on the number
 * of students.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class submission_resolver {
    /**
     * Submission time of one student.
     *
     * @param \stdClass $cm Course module (modname, instance).
     * @param \grade_item $gradeitem Grade item being penalised.
     * @param int $userid Student ID.
     * @return int|null Submission time, or null when there is nothing to measure.
     */
    public static function for_user(\stdClass $cm, \grade_item $gradeitem, int $userid): ?int {
        return self::for_users($cm, $gradeitem, [$userid])[$userid];
    }

    /**
     * Submission times of several students.
     *
     * @param \stdClass $cm Course module (modname, instance).
     * @param \grade_item $gradeitem Grade item being penalised.
     * @param int[] $userids Student IDs.
     * @return array Submission time (int) or null, keyed by user ID.
     */
    public static function for_users(\stdClass $cm, \grade_item $gradeitem, array $userids): array {
        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (empty($userids)) {
            return [];
        }

        $instanceid = (int) $cm->instance;
        switch ($cm->modname) {
            case 'assign':
                $times = self::assign($instanceid, $userids);
                break;
            case 'quiz':
                $times = self::quiz($instanceid, $userids);
                break;
            case 'lesson':
                $times = self::lesson($instanceid, $userids);
                break;
            case 'forum':
                $times = (int) $gradeitem->itemnumber === 0
                    ? self::rated_items($cm, 'mod_forum', 'post', $userids)
                    : self::forum_last_post($instanceid, $userids);
                break;
            case 'glossary':
            case 'data':
                $times = self::rated_items($cm, 'mod_' . $cm->modname, 'entry', $userids);
                break;
            case 'workshop':
                $times = self::workshop($instanceid, $userids);
                break;
            default:
                $times = self::reported_by_module($gradeitem, $userids);
                break;
        }

        $result = [];
        foreach ($userids as $userid) {
            $result[$userid] = $times[$userid] ?? null;
        }
        return $result;
    }

    /**
     * Students who have handed something in, for several activities of one course.
     *
     * Handed in means the work the penalty measures exists, graded or not: a
     * submitted assignment (the student's own or their team's), a finished quiz
     * attempt, a finished lesson, a forum post, a glossary entry, a database
     * record, a workshop submission, and for any other module a grade on one of
     * its numeric items. Activity completion is not used: its conditions (viewing
     * the page, for instance) need not involve handing anything in.
     *
     * One query per module type (two for assignments), whatever the number of activities.
     *
     * @param \stdClass[] $cms Course modules (id, modname, instance).
     * @param int[]|null $userids Students to look at, or null for all of them.
     * @return array Sets of user IDs (user ID => true) keyed by course module ID; every given activity has an entry.
     */
    public static function handed_in(array $cms, ?array $userids): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/grade/constants.php');

        $result = [];
        $bymodule = [];
        foreach ($cms as $cm) {
            $result[(int) $cm->id] = [];
            $bymodule[$cm->modname][(int) $cm->instance] = (int) $cm->id;
        }
        if ($userids !== null) {
            $userids = array_values(array_unique(array_map('intval', $userids)));
            if (empty($userids)) {
                return $result;
            }
        }

        foreach ($bymodule as $modname => $instances) {
            [$isql, $params] = $DB->get_in_or_equal(array_keys($instances), SQL_PARAMS_NAMED, 'ins');
            $queries = [];
            switch ($modname) {
                case 'assign':
                    $queries[] = ["SELECT DISTINCT assignment AS instanceid, userid
                                     FROM {assign_submission}
                                    WHERE assignment $isql AND userid <> 0 AND status = 'submitted'", 'userid'];
                    $queries[] = ["SELECT DISTINCT s.assignment AS instanceid, gm.userid
                                     FROM {assign_submission} s
                                     JOIN {groups_members} gm ON gm.groupid = s.groupid
                                    WHERE s.assignment $isql AND s.userid = 0 AND s.status = 'submitted'", 'gm.userid'];
                    break;
                case 'quiz':
                    // The same states as the finished attempts of quiz_get_user_attempts().
                    $states = [\mod_quiz\quiz_attempt::FINISHED, \mod_quiz\quiz_attempt::ABANDONED];
                    if (defined(\mod_quiz\quiz_attempt::class . '::SUBMITTED')) {
                        $states[] = constant(\mod_quiz\quiz_attempt::class . '::SUBMITTED');
                    }
                    [$ssql, $sparams] = $DB->get_in_or_equal($states, SQL_PARAMS_NAMED, 'st');
                    $params += $sparams;
                    $queries[] = ["SELECT DISTINCT quiz AS instanceid, userid
                                     FROM {quiz_attempts}
                                    WHERE quiz $isql AND preview = 0 AND state $ssql", 'userid'];
                    break;
                case 'lesson':
                    $queries[] = ["SELECT DISTINCT lessonid AS instanceid, userid
                                     FROM {lesson_grades}
                                    WHERE lessonid $isql", 'userid'];
                    break;
                case 'forum':
                    $queries[] = ["SELECT DISTINCT d.forum AS instanceid, p.userid
                                     FROM {forum_posts} p
                                     JOIN {forum_discussions} d ON d.id = p.discussion
                                    WHERE d.forum $isql", 'p.userid'];
                    break;
                case 'glossary':
                    $queries[] = ["SELECT DISTINCT glossaryid AS instanceid, userid
                                     FROM {glossary_entries}
                                    WHERE glossaryid $isql", 'userid'];
                    break;
                case 'data':
                    $queries[] = ["SELECT DISTINCT dataid AS instanceid, userid
                                     FROM {data_records}
                                    WHERE dataid $isql", 'userid'];
                    break;
                case 'workshop':
                    $queries[] = ["SELECT DISTINCT workshopid AS instanceid, authorid AS userid
                                     FROM {workshop_submissions}
                                    WHERE workshopid $isql AND example = 0", 'authorid'];
                    break;
                default:
                    $params += ['modname' => $modname, 'gradetype' => GRADE_TYPE_VALUE];
                    $queries[] = ["SELECT DISTINCT gi.iteminstance AS instanceid, g.userid
                                     FROM {grade_grades} g
                                     JOIN {grade_items} gi ON gi.id = g.itemid
                                    WHERE gi.itemtype = 'mod' AND gi.itemmodule = :modname AND gi.iteminstance $isql
                                      AND gi.gradetype = :gradetype AND gi.outcomeid IS NULL
                                      AND (g.rawgrade IS NOT NULL OR g.finalgrade IS NOT NULL)", 'g.userid'];
                    break;
            }

            foreach ($queries as [$sql, $usercolumn]) {
                $queryparams = $params;
                if ($userids !== null) {
                    [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
                    $sql .= " AND $usercolumn $usql";
                    $queryparams += $uparams;
                }
                $rs = $DB->get_recordset_sql($sql, $queryparams);
                foreach ($rs as $row) {
                    $result[$instances[(int) $row->instanceid]][(int) $row->userid] = true;
                }
                $rs->close();
            }
        }
        return $result;
    }

    /**
     * Assignment: latest submitted submission, the student's own or else their group's.
     *
     * @param int $assignid Assignment ID.
     * @param int[] $userids User IDs.
     * @return array Times keyed by user ID.
     */
    private static function assign(int $assignid, array $userids): array {
        global $DB;

        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        $params = ['assignment' => $assignid] + $uparams;

        $result = $DB->get_records_sql_menu(
            "SELECT userid, MAX(timemodified)
               FROM {assign_submission}
              WHERE assignment = :assignment
                AND userid $usql
                AND status = 'submitted'
           GROUP BY userid",
            $params
        );

        $missing = array_diff($userids, array_keys($result));
        if (!empty($missing)) {
            [$msql, $mparams] = $DB->get_in_or_equal(array_values($missing), SQL_PARAMS_NAMED, 'mis');
            $result += $DB->get_records_sql_menu(
                "SELECT gm.userid, MAX(s.timemodified)
                   FROM {groups_members} gm
                   JOIN {assign_submission} s ON s.groupid = gm.groupid
                  WHERE gm.userid $msql
                    AND s.assignment = :assignment
                    AND s.userid = 0
                    AND s.status = 'submitted'
               GROUP BY gm.userid",
                ['assignment' => $assignid] + $mparams
            );
        }

        return array_map('intval', $result);
    }

    /**
     * Quiz: the attempt that produced the grade under the quiz grading method.
     *
     * @param int $quizid Quiz ID.
     * @param int[] $userids User IDs.
     * @return array Times keyed by user ID.
     */
    private static function quiz(int $quizid, array $userids): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/lib.php');

        $grademethod = (int) $DB->get_field('quiz', 'grademethod', ['id' => $quizid], MUST_EXIST);

        // Same attempts as quiz_get_user_attempts($quiz, $user, 'finished'): the "submitted"
        // state (awaiting grading after submission) exists only from Moodle 5.0.
        $states = [\mod_quiz\quiz_attempt::FINISHED, \mod_quiz\quiz_attempt::ABANDONED];
        if (defined(\mod_quiz\quiz_attempt::class . '::SUBMITTED')) {
            $states[] = constant(\mod_quiz\quiz_attempt::class . '::SUBMITTED');
        }
        [$ssql, $sparams] = $DB->get_in_or_equal($states, SQL_PARAMS_NAMED, 'st');
        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');

        $rs = $DB->get_recordset_sql(
            "SELECT id, userid, attempt, sumgrades, timefinish, timemodified
               FROM {quiz_attempts}
              WHERE quiz = :quiz
                AND preview = 0
                AND state $ssql
                AND userid $usql
           ORDER BY userid, attempt",
            ['quiz' => $quizid] + $sparams + $uparams
        );
        $attempts = [];
        foreach ($rs as $attempt) {
            $attempts[(int) $attempt->userid][] = $attempt;
        }
        $rs->close();

        $result = [];
        foreach ($attempts as $userid => $list) {
            $chosen = null;
            switch ($grademethod) {
                case QUIZ_ATTEMPTFIRST:
                    $chosen = reset($list);
                    break;
                case QUIZ_ATTEMPTLAST:
                    $chosen = end($list);
                    break;
                case QUIZ_GRADEHIGHEST:
                    foreach ($list as $attempt) {
                        if ($attempt->sumgrades === null) {
                            continue;
                        }
                        if ($chosen === null || (float) $attempt->sumgrades > (float) $chosen->sumgrades) {
                            $chosen = $attempt;
                        }
                    }
                    break;
                default:
                    // Average: every counted attempt contributes, the latest one completes the grade.
                    foreach ($list as $attempt) {
                        if ($attempt->sumgrades !== null) {
                            $chosen = $attempt;
                        }
                    }
                    break;
            }
            if ($chosen !== null) {
                $result[$userid] = (int) ($chosen->timefinish ?: $chosen->timemodified);
            }
        }
        return $result;
    }

    /**
     * Lesson: the attempt lesson_get_user_grades() uses for the grade.
     *
     * @param int $lessonid Lesson ID.
     * @param int[] $userids User IDs.
     * @return array Times keyed by user ID.
     */
    private static function lesson(int $lessonid, array $userids): array {
        global $DB;

        $lesson = $DB->get_record('lesson', ['id' => $lessonid], 'retake, usemaxgrade', MUST_EXIST);
        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');

        $rs = $DB->get_recordset_sql(
            "SELECT id, userid, grade, completed
               FROM {lesson_grades}
              WHERE lessonid = :lessonid
                AND userid $usql
           ORDER BY userid, id",
            ['lessonid' => $lessonid] + $uparams
        );
        $chosen = [];
        foreach ($rs as $row) {
            $userid = (int) $row->userid;
            $current = $chosen[$userid] ?? null;
            if ($current === null) {
                $chosen[$userid] = $row;
            } else if (!$lesson->retake) {
                // No retakes: only the first attempt counts.
                continue;
            } else if ($lesson->usemaxgrade) {
                if ((float) $row->grade > (float) $current->grade) {
                    $chosen[$userid] = $row;
                }
            } else {
                // Mean: the latest attempt completes the grade.
                $chosen[$userid] = $row;
            }
        }
        $rs->close();

        return array_map(fn(\stdClass $row): int => (int) $row->completed, $chosen);
    }

    /**
     * Rated items: the item behind the aggregated rating grade.
     *
     * Mirrors rating_manager::get_user_grades(), which aggregates every rating
     * given to the student's items in this activity.
     *
     * @param \stdClass $cm Course module (id, modname, instance).
     * @param string $component Rating component.
     * @param string $ratingarea Rating area.
     * @param int[] $userids User IDs.
     * @return array Times keyed by user ID.
     */
    private static function rated_items(\stdClass $cm, string $component, string $ratingarea, array $userids): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/rating/lib.php');

        $items = [
            'forum' => ['forum_posts', 'created'],
            'glossary' => ['glossary_entries', 'timecreated'],
            'data' => ['data_records', 'timecreated'],
        ];
        [$table, $timefield] = $items[$cm->modname];
        $aggregation = (int) $DB->get_field($cm->modname, 'assessed', ['id' => $cm->instance], MUST_EXIST);

        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        $rs = $DB->get_recordset_sql(
            "SELECT r.id, i.userid, r.rating, i.$timefield AS itemtime
               FROM {rating} r
               JOIN {{$table}} i ON i.id = r.itemid
              WHERE r.contextid = :contextid
                AND r.component = :component
                AND r.ratingarea = :ratingarea
                AND i.userid $usql
           ORDER BY i.userid, i.$timefield, r.id",
            [
                'contextid' => \context_module::instance($cm->id)->id,
                'component' => $component,
                'ratingarea' => $ratingarea,
            ] + $uparams
        );
        $chosen = [];
        foreach ($rs as $rating) {
            $userid = (int) $rating->userid;
            $current = $chosen[$userid] ?? null;
            if ($current === null) {
                $chosen[$userid] = $rating;
            } else if ($aggregation === RATING_AGGREGATE_MAXIMUM) {
                if ((float) $rating->rating > (float) $current->rating) {
                    $chosen[$userid] = $rating;
                }
            } else if ($aggregation === RATING_AGGREGATE_MINIMUM) {
                if ((float) $rating->rating < (float) $current->rating) {
                    $chosen[$userid] = $rating;
                }
            } else {
                // Average, count and sum: the latest rated item completes the grade.
                $chosen[$userid] = $rating;
            }
        }
        $rs->close();

        return array_map(fn(\stdClass $row): int => (int) $row->itemtime, $chosen);
    }

    /**
     * Whole-forum grading: the student's latest post in the forum.
     *
     * @param int $forumid Forum ID.
     * @param int[] $userids User IDs.
     * @return array Times keyed by user ID.
     */
    private static function forum_last_post(int $forumid, array $userids): array {
        global $DB;

        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        $result = $DB->get_records_sql_menu(
            "SELECT p.userid, MAX(p.created)
               FROM {forum_posts} p
               JOIN {forum_discussions} d ON d.id = p.discussion
              WHERE d.forum = :forum
                AND p.userid $usql
           GROUP BY p.userid",
            ['forum' => $forumid] + $uparams
        );
        return array_map('intval', $result);
    }

    /**
     * Workshop: the latest change to the student's submission.
     *
     * @param int $workshopid Workshop ID.
     * @param int[] $userids User IDs.
     * @return array Times keyed by user ID.
     */
    private static function workshop(int $workshopid, array $userids): array {
        global $DB;

        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        $result = $DB->get_records_sql_menu(
            "SELECT authorid, MAX(timemodified)
               FROM {workshop_submissions}
              WHERE workshopid = :workshopid
                AND example = 0
                AND authorid $usql
           GROUP BY authorid",
            ['workshopid' => $workshopid] + $uparams
        );
        return array_map('intval', $result);
    }

    /**
     * Any other module: the submission date reported with the grade, else its grading date.
     *
     * The submission date is grade_grades.timecreated (grade_grade::get_datesubmitted()).
     * Without it, grade_grades.timemodified holds the grading date the module
     * reported, or the time it wrote the grade. This plugin's own writes never
     * change either column (grade history rows, in contrast, carry the time of
     * every write, including ours, so they are not used here).
     *
     * @param \grade_item $gradeitem Grade item.
     * @param int[] $userids User IDs.
     * @return array Times keyed by user ID.
     */
    private static function reported_by_module(\grade_item $gradeitem, array $userids): array {
        global $DB;

        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        $grades = $DB->get_records_sql(
            "SELECT userid, timecreated, timemodified
               FROM {grade_grades}
              WHERE itemid = :itemid
                AND userid $usql",
            ['itemid' => $gradeitem->id] + $uparams
        );

        $result = [];
        foreach ($grades as $userid => $grade) {
            if (!empty($grade->timecreated)) {
                $result[$userid] = (int) $grade->timecreated;
            } else if (!empty($grade->timemodified)) {
                $result[$userid] = (int) $grade->timemodified;
            }
        }
        return $result;
    }
    /**
     * Graded attempts or items of each student, when the best penalised one must be kept (F15).
     *
     * Applies where the grade is the highest of several: quiz, lesson, SCORM and
     * H5P graded by highest attempt, forum/glossary/database rated by maximum, and,
     * when the rule asks for it, any module without a readable grading method (its
     * earlier grades come from the grade history, timed when they reached the
     * gradebook, since the history keeps no submission date).
     *
     * @param \stdClass $cm Course module (id, modname, instance).
     * @param \grade_item $gradeitem Grade item being penalised.
     * @param int[] $userids Student IDs.
     * @param bool $keepbest Rule option for modules without a readable grading method.
     * @return array|null Lists of [raw grade, time] keyed by user ID, or null when the activity keeps no best grade.
     */
    public static function best_candidates(\stdClass $cm, \grade_item $gradeitem, array $userids, bool $keepbest): ?array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/rating/lib.php');

        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (empty($userids)) {
            return [];
        }
        $instanceid = (int) $cm->instance;

        switch ($cm->modname) {
            case 'quiz':
                // Loaded here only: the quiz module may be uninstalled on sites that use no quiz.
                require_once($CFG->dirroot . '/mod/quiz/lib.php');
                $quiz = $DB->get_record('quiz', ['id' => $instanceid], 'id, grademethod, grade, sumgrades', MUST_EXIST);
                $highest = (int) $quiz->grademethod === (int) QUIZ_GRADEHIGHEST;
                if (!$highest || (int) $gradeitem->itemnumber !== 0 || empty((float) $quiz->sumgrades)) {
                    return null;
                }
                return self::quiz_candidates($quiz, $userids);
            case 'lesson':
                $lesson = $DB->get_record('lesson', ['id' => $instanceid], 'id, retake, usemaxgrade', MUST_EXIST);
                if (empty($lesson->retake) || empty($lesson->usemaxgrade)) {
                    return null;
                }
                return self::lesson_candidates($instanceid, (float) $gradeitem->grademax, $userids);
            case 'h5pactivity':
                $h5p = $DB->get_record('h5pactivity', ['id' => $instanceid], 'id, grademethod, enabletracking', MUST_EXIST);
                $highest = \mod_h5pactivity\local\manager::GRADEHIGHESTATTEMPT;
                if ((int) $h5p->grademethod !== $highest || empty($h5p->enabletracking)) {
                    return null;
                }
                return self::h5p_candidates($instanceid, (float) $gradeitem->grademax, $userids);
            case 'scorm':
                require_once($CFG->dirroot . '/mod/scorm/locallib.php');
                $scorm = $DB->get_record('scorm', ['id' => $instanceid], '*', MUST_EXIST);
                if ((int) $scorm->whatgrade !== (int) HIGHESTATTEMPT) {
                    return null;
                }
                return self::scorm_candidates($scorm, $userids);
            case 'forum':
            case 'glossary':
            case 'data':
                $aggregation = (int) $DB->get_field($cm->modname, 'assessed', ['id' => $instanceid], MUST_EXIST);
                $rated = $cm->modname !== 'forum' || (int) $gradeitem->itemnumber === 0;
                if (!$rated || $aggregation !== RATING_AGGREGATE_MAXIMUM) {
                    return null;
                }
                return self::rating_candidates($cm, $userids);
            case 'assign':
            case 'workshop':
            case 'bigbluebuttonbn':
                return null;
            default:
                return $keepbest ? self::history_candidates($cm, $gradeitem, $userids) : null;
        }
    }

    /**
     * Quiz attempts counted by quiz_get_user_attempts(), with their grade on the quiz scale.
     *
     * @param \stdClass $quiz Quiz (id, grade, sumgrades).
     * @param int[] $userids User IDs.
     * @return array Lists of [raw, time] keyed by user ID.
     */
    private static function quiz_candidates(\stdClass $quiz, array $userids): array {
        global $DB;

        $states = [\mod_quiz\quiz_attempt::FINISHED, \mod_quiz\quiz_attempt::ABANDONED];
        if (defined(\mod_quiz\quiz_attempt::class . '::SUBMITTED')) {
            $states[] = constant(\mod_quiz\quiz_attempt::class . '::SUBMITTED');
        }
        [$ssql, $sparams] = $DB->get_in_or_equal($states, SQL_PARAMS_NAMED, 'st');
        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        $rs = $DB->get_recordset_sql(
            "SELECT id, userid, sumgrades, timefinish, timemodified
               FROM {quiz_attempts}
              WHERE quiz = :quiz AND preview = 0 AND sumgrades IS NOT NULL
                AND state $ssql AND userid $usql
           ORDER BY userid, attempt",
            ['quiz' => $quiz->id] + $sparams + $uparams
        );
        $result = [];
        foreach ($rs as $attempt) {
            // Same scaling as quiz_rescale_grade().
            $raw = (float) $attempt->sumgrades * (float) $quiz->grade / (float) $quiz->sumgrades;
            $result[(int) $attempt->userid][] = [$raw, (int) ($attempt->timefinish ?: $attempt->timemodified)];
        }
        $rs->close();
        return $result;
    }

    /**
     * Lesson attempts, with their grade on the gradebook scale (lesson_update_grades()).
     *
     * @param int $lessonid Lesson ID.
     * @param float $grademax Grade item maximum.
     * @param int[] $userids User IDs.
     * @return array Lists of [raw, time] keyed by user ID.
     */
    private static function lesson_candidates(int $lessonid, float $grademax, array $userids): array {
        global $DB;

        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        $rs = $DB->get_recordset_sql(
            "SELECT id, userid, grade, completed
               FROM {lesson_grades}
              WHERE lessonid = :lessonid AND userid $usql
           ORDER BY userid, id",
            ['lessonid' => $lessonid] + $uparams
        );
        $result = [];
        foreach ($rs as $row) {
            $result[(int) $row->userid][] = [(float) $row->grade * $grademax / 100, (int) $row->completed];
        }
        $rs->close();
        return $result;
    }

    /**
     * Completed H5P attempts, with their grade on the gradebook scale (\mod_h5pactivity\local\grader).
     *
     * @param int $h5pid H5P activity ID.
     * @param float $grademax Grade item maximum.
     * @param int[] $userids User IDs.
     * @return array Lists of [raw, time] keyed by user ID.
     */
    private static function h5p_candidates(int $h5pid, float $grademax, array $userids): array {
        global $DB;

        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        $rs = $DB->get_recordset_sql(
            "SELECT id, userid, scaled, timemodified
               FROM {h5pactivity_attempts}
              WHERE h5pactivityid = :h5pid AND completion = 1 AND userid $usql
           ORDER BY userid, attempt",
            ['h5pid' => $h5pid] + $uparams
        );
        $result = [];
        foreach ($rs as $attempt) {
            $result[(int) $attempt->userid][] = [$grademax * (float) $attempt->scaled, (int) $attempt->timemodified];
        }
        $rs->close();
        return $result;
    }

    /**
     * SCORM attempts, graded by the module's own scorm_grade_user_attempt().
     *
     * The per-attempt grade depends on the SCO tracks and the grading method,
     * so the module's function is called for each attempt, exactly as
     * scorm_grade_user() does for "highest attempt"; the attempt times come in
     * one query.
     *
     * @param \stdClass $scorm SCORM record.
     * @param int[] $userids User IDs.
     * @return array Lists of [raw, time] keyed by user ID.
     */
    private static function scorm_candidates(\stdClass $scorm, array $userids): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        $rs = $DB->get_recordset_sql(
            "SELECT a.id, a.userid, a.attempt, MAX(v.timemodified) AS timemodified
               FROM {scorm_attempt} a
               JOIN {scorm_scoes_value} v ON v.attemptid = a.id
              WHERE a.scormid = :scormid AND a.userid $usql
           GROUP BY a.id, a.userid, a.attempt
           ORDER BY a.userid, a.attempt",
            ['scormid' => $scorm->id] + $uparams
        );
        $result = [];
        foreach ($rs as $attempt) {
            $raw = scorm_grade_user_attempt($scorm, (int) $attempt->userid, (int) $attempt->attempt);
            if ($raw !== null) {
                $result[(int) $attempt->userid][] = [(float) $raw, (int) $attempt->timemodified];
            }
        }
        $rs->close();
        return $result;
    }

    /**
     * Ratings given to the student's items: with maximum aggregation each rating is a possible grade.
     *
     * @param \stdClass $cm Course module (id, modname).
     * @param int[] $userids User IDs.
     * @return array Lists of [raw, time] keyed by user ID.
     */
    private static function rating_candidates(\stdClass $cm, array $userids): array {
        global $DB;

        $items = [
            'forum' => ['forum_posts', 'created', 'mod_forum', 'post'],
            'glossary' => ['glossary_entries', 'timecreated', 'mod_glossary', 'entry'],
            'data' => ['data_records', 'timecreated', 'mod_data', 'entry'],
        ];
        [$table, $timefield, $component, $ratingarea] = $items[$cm->modname];
        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        $rs = $DB->get_recordset_sql(
            "SELECT r.id, i.userid, r.rating, i.$timefield AS itemtime
               FROM {rating} r
               JOIN {{$table}} i ON i.id = r.itemid
              WHERE r.contextid = :contextid AND r.component = :component AND r.ratingarea = :ratingarea
                AND i.userid $usql",
            [
                'contextid' => \context_module::instance($cm->id)->id,
                'component' => $component,
                'ratingarea' => $ratingarea,
            ] + $uparams
        );
        $result = [];
        foreach ($rs as $rating) {
            $result[(int) $rating->userid][] = [(float) $rating->rating, (int) $rating->itemtime];
        }
        $rs->close();
        return $result;
    }

    /**
     * Grades the module wrote over time (grade history), plus the current one.
     *
     * The history keeps no submission date, so an earlier grade counts from the
     * moment it reached the gradebook; the current grade uses the dates the
     * module reports, as everywhere else.
     *
     * @param \stdClass $cm Course module.
     * @param \grade_item $gradeitem Grade item.
     * @param int[] $userids User IDs.
     * @return array Lists of [raw, time] keyed by user ID.
     */
    private static function history_candidates(\stdClass $cm, \grade_item $gradeitem, array $userids): array {
        global $DB;

        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        $rs = $DB->get_recordset_sql(
            "SELECT id, userid, rawgrade, timemodified
               FROM {grade_grades_history}
              WHERE itemid = :itemid AND userid $usql AND rawgrade IS NOT NULL
                AND (source IS NULL OR source <> :source)",
            ['itemid' => $gradeitem->id, 'source' => penalty_writer::SOURCE] + $uparams
        );
        $result = [];
        foreach ($rs as $row) {
            $result[(int) $row->userid][] = [(float) $row->rawgrade, (int) $row->timemodified];
        }
        $rs->close();

        $current = self::for_users($cm, $gradeitem, $userids);
        $raws = $DB->get_records_select_menu(
            'grade_grades',
            "itemid = :itemid AND userid $usql AND rawgrade IS NOT NULL",
            ['itemid' => $gradeitem->id] + $uparams,
            '',
            'userid, rawgrade'
        );
        foreach ($raws as $userid => $raw) {
            if (!empty($current[$userid])) {
                $result[(int) $userid][] = [(float) $raw, (int) $current[$userid]];
            }
        }
        return $result;
    }
}
