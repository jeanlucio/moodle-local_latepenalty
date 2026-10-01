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

use local_latepenalty\tests\latepenalty_testcase;

/**
 * The deadline chain (F1, F2, F3, F6 and the plugin's own overrides).
 *
 * Activity overrides and extensions are created with each module's own data
 * generator or API (assign::save_user_extension()), never by writing rows.
 * Tests that need the quiz due date (Moodle 5.3+) are skipped where the column
 * does not exist, and their pre-5.3 counterparts are skipped where it does.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\local\deadline_resolver
 * @covers \local_latepenalty\local\deadline
 * @covers \local_latepenalty\observer
 * @covers \local_latepenalty\recalculator
 * @covers \local_latepenalty\penalty_helper
 * @covers \local_latepenalty\local\submission_resolver
 * @covers \local_latepenalty\local\penalty_writer
 */
final class deadline_resolver_test extends latepenalty_testcase {
    /** @var int Reference time shared by the scenarios. */
    private int $now;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        deadline_resolver::reset_caches();
        $this->now = time();
    }

    /**
     * Course module record as the plugin reads it.
     *
     * @param int $cmid Course module ID.
     * @return \stdClass
     */
    private function cm(int $cmid): \stdClass {
        return get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
    }

    /**
     * Assert the deadline of one student.
     *
     * @param int $time Expected time.
     * @param string $origin Expected origin.
     * @param int $cmid Course module ID.
     * @param int $userid Student ID.
     * @return void
     */
    private function assert_deadline(int $time, string $origin, int $cmid, int $userid): void {
        $resolved = deadline_resolver::for_user($this->cm($cmid), $userid);
        $this->assertSame([$time, $origin], [$resolved->time, $resolved->origin]);
    }

    /**
     * Skip unless this Moodle has the quiz due date.
     *
     * @return void
     */
    private function require_quiz_duedate(): void {
        if (!deadline_resolver::quiz_has_duedate()) {
            $this->markTestSkipped('quiz.duedate exists only from Moodle 5.3.');
        }
    }

    /**
     * Put a student into a new group of the course.
     *
     * @param int $courseid Course ID.
     * @param int $userid User ID.
     * @return int Group ID.
     */
    private function new_group_with(int $courseid, int $userid): int {
        $group = $this->getDataGenerator()->create_group(['courseid' => $courseid]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $userid]);
        return (int) $group->id;
    }

    /**
     * Grant an assignment extension through the assignment API.
     *
     * @param \stdClass $assign Assignment record.
     * @param int $userid Student ID.
     * @param int $date Extension date, 0 to remove it.
     * @return void
     */
    private function grant_extension(\stdClass $assign, int $userid, int $date): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        [$course, $cm] = get_course_and_cm_from_cmid($assign->cmid, 'assign');
        $instance = new \assign(\context_module::instance($cm->id), $cm, $course);
        $this->assertTrue($instance->save_user_extension($userid, $date));
    }

    // Step 4 and 5: activity due date and reminder (F3).

    /**
     * Assignment and forum: the due date wins over the reminder, in both directions (F3-01).
     *
     * @return void
     */
    public function test_duedate_wins_over_reminder(): void {
        [$course, $student] = $this->create_course_with_users();
        $due = $this->now - DAYSECS;

        foreach (['assign', 'forum'] as $modname) {
            foreach ([$due - DAYSECS, $due + DAYSECS] as $reminder) {
                $module = $this->getDataGenerator()->create_module($modname, ['course' => $course->id, 'duedate' => $due]);
                $this->set_reminder($module->cmid, $reminder);
                $this->assert_deadline($due, deadline_resolver::ORIGIN_DUEDATE, $module->cmid, $student->id);
            }
        }
    }

    /**
     * Only a reminder, only a due date, or nothing (F3-02).
     *
     * @return void
     */
    public function test_reminder_duedate_or_nothing(): void {
        [$course, $student] = $this->create_course_with_users();

        $onlyreminder = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'duedate' => 0]);
        $this->set_reminder($onlyreminder->cmid, $this->now);
        $this->assert_deadline($this->now, deadline_resolver::ORIGIN_REMINDER, $onlyreminder->cmid, $student->id);

        $onlydue = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'duedate' => $this->now]);
        $this->assert_deadline($this->now, deadline_resolver::ORIGIN_DUEDATE, $onlydue->cmid, $student->id);

        $nothing = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'duedate' => 0]);
        $this->assert_deadline(0, deadline_resolver::ORIGIN_NONE, $nothing->cmid, $student->id);
        $this->assertFalse(deadline_resolver::for_user($this->cm($nothing->cmid), $student->id)->exists());
    }

    /**
     * Modules without a due date use the reminder (F3-03).
     *
     * @return void
     */
    public function test_modules_without_duedate_use_reminder(): void {
        [$course, $student] = $this->create_course_with_users();

        foreach (['lesson', 'glossary', 'data', 'h5pactivity', 'page'] as $modname) {
            $module = $this->getDataGenerator()->create_module($modname, ['course' => $course->id]);
            $this->set_reminder($module->cmid, $this->now);
            $this->assert_deadline($this->now, deadline_resolver::ORIGIN_REMINDER, $module->cmid, $student->id);
        }
    }

    /**
     * Workshop submission end and lesson deadline are closing dates, not due dates (F3-04, DA6).
     *
     * @return void
     */
    public function test_closing_dates_are_not_due_dates(): void {
        [$course, $student] = $this->create_course_with_users();

        $workshop = $this->getDataGenerator()->create_module('workshop', [
            'course' => $course->id,
            'submissionend' => $this->now - DAYSECS,
        ]);
        $this->set_reminder($workshop->cmid, $this->now);
        $this->assert_deadline($this->now, deadline_resolver::ORIGIN_REMINDER, $workshop->cmid, $student->id);

        $lesson = $this->getDataGenerator()->create_module('lesson', [
            'course' => $course->id,
            'deadline' => $this->now - DAYSECS,
        ]);
        $this->set_reminder($lesson->cmid, $this->now);
        $this->assert_deadline($this->now, deadline_resolver::ORIGIN_REMINDER, $lesson->cmid, $student->id);

        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'timeclose' => $this->now - DAYSECS,
        ]);
        $this->set_reminder($quiz->cmid, $this->now);
        $this->assert_deadline($this->now, deadline_resolver::ORIGIN_REMINDER, $quiz->cmid, $student->id);
    }

    // Quiz due date (F1).

    /**
     * Quiz due date, with and without a reminder (F1-01, F1-02, F1-03, F1-04, F3-01).
     *
     * @return void
     */
    public function test_quiz_duedate(): void {
        $this->require_quiz_duedate();
        [$course, $student] = $this->create_course_with_users();

        foreach ([$this->now - DAYSECS, $this->now + DAYSECS] as $due) {
            $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'duedate' => $due]);
            $this->assert_deadline($due, deadline_resolver::ORIGIN_DUEDATE, $quiz->cmid, $student->id);
            $this->set_reminder($quiz->cmid, $due + HOURSECS);
            $this->assert_deadline($due, deadline_resolver::ORIGIN_DUEDATE, $quiz->cmid, $student->id);
        }

        $nodue = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'duedate' => 0]);
        $this->assert_deadline(0, deadline_resolver::ORIGIN_NONE, $nodue->cmid, $student->id);
        $this->set_reminder($nodue->cmid, $this->now);
        $this->assert_deadline($this->now, deadline_resolver::ORIGIN_REMINDER, $nodue->cmid, $student->id);
    }

    /**
     * Before Moodle 5.3 the quiz keeps using the reminder and override close dates (F1-05).
     *
     * @return void
     */
    public function test_quiz_without_duedate_column(): void {
        if (deadline_resolver::quiz_has_duedate()) {
            $this->markTestSkipped('Only for Moodle versions without quiz.duedate.');
        }
        [$course, $student] = $this->create_course_with_users();
        $other = $this->getDataGenerator()->create_user();

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $this->set_reminder($quiz->cmid, $this->now);
        $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_override([
            'quiz' => $quiz->id,
            'userid' => $student->id,
            'timeclose' => $this->now + DAYSECS,
        ]);

        $this->assert_deadline($this->now + DAYSECS, deadline_resolver::ORIGIN_ACTIVITY_CLOSE, $quiz->cmid, $student->id);
        $this->assert_deadline($this->now, deadline_resolver::ORIGIN_REMINDER, $quiz->cmid, (int) $other->id);
    }

    /**
     * The column check matches the schema and is cached until reset (F1-06).
     *
     * @return void
     */
    public function test_quiz_duedate_detection(): void {
        global $DB;

        $expected = $DB->get_manager()->field_exists('quiz', 'duedate');
        $this->assertSame($expected, deadline_resolver::quiz_has_duedate());
        $this->assertSame($expected ? 'duedate' : null, deadline_resolver::duedate_field('quiz'));

        $reads = $DB->perf_get_reads();
        deadline_resolver::quiz_has_duedate();
        $this->assertSame($reads, $DB->perf_get_reads());

        deadline_resolver::reset_caches();
        $this->assertSame($expected, deadline_resolver::quiz_has_duedate());
    }

    // Quiz overrides with a due date (F2).

    /**
     * Student override due date, later or earlier than the quiz's (F2-01).
     *
     * @return void
     */
    public function test_quiz_user_override_duedate(): void {
        $this->require_quiz_duedate();
        [$course, $student] = $this->create_course_with_users();

        foreach ([$this->now + DAYSECS, $this->now - 2 * DAYSECS] as $override) {
            $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'duedate' => $this->now]);
            $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_override([
                'quiz' => $quiz->id,
                'userid' => $student->id,
                'duedate' => $override,
            ]);
            $this->assert_deadline($override, deadline_resolver::ORIGIN_ACTIVITY_OVERRIDE, $quiz->cmid, $student->id);
        }
    }

    /**
     * An override that only changes other settings keeps the quiz due date (F2-02).
     *
     * @return void
     */
    public function test_quiz_override_without_duedate_inherits(): void {
        $this->require_quiz_duedate();
        [$course, $student] = $this->create_course_with_users();

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'duedate' => $this->now]);
        $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_override([
            'quiz' => $quiz->id,
            'userid' => $student->id,
            'attempts' => 3,
        ]);

        $this->assert_deadline($this->now, deadline_resolver::ORIGIN_DUEDATE, $quiz->cmid, $student->id);
    }

    /**
     * An override due date of 0 removes the due date: the student is exempt, reminder or not (F2-03, DA1).
     *
     * @return void
     */
    public function test_quiz_override_duedate_zero_exempts(): void {
        $this->require_quiz_duedate();
        [$course, $student] = $this->create_course_with_users();

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'duedate' => $this->now]);
        $this->set_reminder($quiz->cmid, $this->now);
        $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_override([
            'quiz' => $quiz->id,
            'userid' => $student->id,
            'duedate' => 0,
            'timeclose' => $this->now + DAYSECS,
        ]);

        $this->assert_deadline(0, deadline_resolver::ORIGIN_ACTIVITY_OVERRIDE, $quiz->cmid, $student->id);
    }

    /**
     * Group overrides: one group, then two with different dates (the latest wins) (F2-04).
     *
     * @return void
     */
    public function test_quiz_group_override_duedate(): void {
        $this->require_quiz_duedate();
        [$course, $student] = $this->create_course_with_users();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'duedate' => $this->now]);
        $first = $this->new_group_with($course->id, $student->id);
        $generator->create_override(['quiz' => $quiz->id, 'groupid' => $first, 'duedate' => $this->now + DAYSECS]);
        $this->assert_deadline($this->now + DAYSECS, deadline_resolver::ORIGIN_ACTIVITY_OVERRIDE, $quiz->cmid, $student->id);

        $second = $this->new_group_with($course->id, $student->id);
        $generator->create_override(['quiz' => $quiz->id, 'groupid' => $second, 'duedate' => $this->now + 3 * DAYSECS]);
        $this->assert_deadline($this->now + 3 * DAYSECS, deadline_resolver::ORIGIN_ACTIVITY_OVERRIDE, $quiz->cmid, $student->id);
    }

    /**
     * Two groups, one of them without a due date: the student is exempt (F2-05).
     *
     * @return void
     */
    public function test_quiz_group_override_zero_wins(): void {
        $this->require_quiz_duedate();
        [$course, $student] = $this->create_course_with_users();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'duedate' => $this->now]);
        $generator->create_override([
            'quiz' => $quiz->id,
            'groupid' => $this->new_group_with($course->id, $student->id),
            'duedate' => $this->now + DAYSECS,
        ]);
        $generator->create_override([
            'quiz' => $quiz->id,
            'groupid' => $this->new_group_with($course->id, $student->id),
            'duedate' => 0,
        ]);

        $this->assert_deadline(0, deadline_resolver::ORIGIN_ACTIVITY_OVERRIDE, $quiz->cmid, $student->id);
    }

    /**
     * Student and group overrides together: the student's wins (F2-06).
     *
     * @return void
     */
    public function test_quiz_user_override_beats_group(): void {
        $this->require_quiz_duedate();
        [$course, $student] = $this->create_course_with_users();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'duedate' => $this->now]);
        $generator->create_override([
            'quiz' => $quiz->id,
            'groupid' => $this->new_group_with($course->id, $student->id),
            'duedate' => $this->now + 5 * DAYSECS,
        ]);
        $generator->create_override(['quiz' => $quiz->id, 'userid' => $student->id, 'duedate' => $this->now + DAYSECS]);

        $this->assert_deadline($this->now + DAYSECS, deadline_resolver::ORIGIN_ACTIVITY_OVERRIDE, $quiz->cmid, $student->id);
    }

    /**
     * Override close dates: used only when no due date applies at all (F2-08, F2-09, F2-10, DA3).
     *
     * @return void
     */
    public function test_quiz_override_close_is_legacy_fallback(): void {
        $this->require_quiz_duedate();
        [$course, $student] = $this->create_course_with_users();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $close = $this->now + 4 * DAYSECS;

        // No due date anywhere: the override close date applies (F2-08).
        $nodue = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'duedate' => 0]);
        $this->set_reminder($nodue->cmid, $this->now);
        $generator->create_override(['quiz' => $nodue->id, 'userid' => $student->id, 'timeclose' => $close]);
        $this->assert_deadline($close, deadline_resolver::ORIGIN_ACTIVITY_CLOSE, $nodue->cmid, $student->id);

        // Quiz due date and an override that only closes later: the due date applies (F2-09).
        $withdue = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'duedate' => $this->now]);
        $generator->create_override(['quiz' => $withdue->id, 'userid' => $student->id, 'timeclose' => $close]);
        $this->assert_deadline($this->now, deadline_resolver::ORIGIN_DUEDATE, $withdue->cmid, $student->id);

        // Override with both: its due date applies (F2-10).
        $both = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'duedate' => $this->now]);
        $generator->create_override([
            'quiz' => $both->id,
            'userid' => $student->id,
            'duedate' => $this->now + DAYSECS,
            'timeclose' => $close,
        ]);
        $this->assert_deadline($this->now + DAYSECS, deadline_resolver::ORIGIN_ACTIVITY_OVERRIDE, $both->cmid, $student->id);
    }

    /**
     * Quiz override close date of a student, as before Moodle 5.3 (migrated from observer_test).
     *
     * @return void
     */
    public function test_quiz_user_override_close(): void {
        [$course, $student] = $this->create_course_with_users();

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $expected = $this->now + 5 * DAYSECS;
        $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_override([
            'quiz' => $quiz->id,
            'userid' => $student->id,
            'timeclose' => $expected,
        ]);

        $this->assert_deadline($expected, deadline_resolver::ORIGIN_ACTIVITY_CLOSE, $quiz->cmid, $student->id);
    }

    // Assignment extensions and overrides (F6).

    /**
     * An extension is the deadline (F6-01) and later removing it restores the due date (F6-05).
     *
     * @return void
     */
    public function test_assign_extension(): void {
        [$course, $student] = $this->create_course_with_users();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'duedate' => $this->now]);

        $this->grant_extension($assign, $student->id, $this->now + 2 * DAYSECS);
        $this->assert_deadline($this->now + 2 * DAYSECS, deadline_resolver::ORIGIN_EXTENSION, $assign->cmid, $student->id);

        $this->grant_extension($assign, $student->id, 0);
        $this->assert_deadline($this->now, deadline_resolver::ORIGIN_DUEDATE, $assign->cmid, $student->id);
    }

    /**
     * The extension wins over student and group overrides, later or earlier (F6-03, F6-04).
     *
     * @return void
     */
    public function test_assign_extension_beats_overrides(): void {
        [$course, $student] = $this->create_course_with_users();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $extension = $this->now + 2 * DAYSECS;

        foreach ([$extension + DAYSECS, $extension - DAYSECS] as $override) {
            $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'duedate' => $this->now]);
            $generator->create_override(['assignid' => $assign->id, 'userid' => $student->id, 'duedate' => $override]);
            $this->grant_extension($assign, $student->id, $extension);
            $this->assert_deadline($extension, deadline_resolver::ORIGIN_EXTENSION, $assign->cmid, $student->id);
        }

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'duedate' => $this->now]);
        $generator->create_override([
            'assignid' => $assign->id,
            'groupid' => $this->new_group_with($course->id, $student->id),
            'duedate' => $extension + DAYSECS,
            'sortorder' => 1,
        ]);
        $this->grant_extension($assign, $student->id, $extension);
        $this->assert_deadline($extension, deadline_resolver::ORIGIN_EXTENSION, $assign->cmid, $student->id);
    }

    /**
     * Team assignment: an extension for one member applies to that member only (F6-06).
     *
     * @return void
     */
    public function test_assign_team_extension_is_per_member(): void {
        [$course, $student] = $this->create_course_with_users();
        $mate = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($mate->id, $course->id, 'student');
        $group = $this->new_group_with($course->id, $student->id);
        $this->getDataGenerator()->create_group_member(['groupid' => $group, 'userid' => $mate->id]);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'duedate' => $this->now,
            'teamsubmission' => 1,
        ]);
        $this->grant_extension($assign, $student->id, $this->now + DAYSECS);

        $this->assert_deadline($this->now + DAYSECS, deadline_resolver::ORIGIN_EXTENSION, $assign->cmid, $student->id);
        $this->assert_deadline($this->now, deadline_resolver::ORIGIN_DUEDATE, $assign->cmid, (int) $mate->id);
    }

    /**
     * Several students with different extensions, resolved in one bulk call (F6-07).
     *
     * @return void
     */
    public function test_assign_extensions_in_bulk(): void {
        [$course, $first] = $this->create_course_with_users();
        $second = $this->getDataGenerator()->create_user();
        $third = $this->getDataGenerator()->create_user();
        foreach ([$second, $third] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'duedate' => $this->now]);
        $this->grant_extension($assign, $first->id, $this->now + DAYSECS);
        $this->grant_extension($assign, $second->id, $this->now + 2 * DAYSECS);

        $resolved = deadline_resolver::for_users($this->cm($assign->cmid), [$first->id, $second->id, $third->id]);

        $this->assertSame($this->now + DAYSECS, $resolved[$first->id]->time);
        $this->assertSame($this->now + 2 * DAYSECS, $resolved[$second->id]->time);
        $this->assertSame(
            [$this->now, deadline_resolver::ORIGIN_DUEDATE],
            [$resolved[$third->id]->time, $resolved[$third->id]->origin]
        );
    }

    /**
     * Student and group overrides of an assignment (migrated from observer_test).
     *
     * @return void
     */
    public function test_assign_user_and_group_overrides(): void {
        [$course, $student] = $this->create_course_with_users();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');

        $useroverride = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $generator->create_override([
            'assignid' => $useroverride->id,
            'userid' => $student->id,
            'duedate' => $this->now + 3 * DAYSECS,
        ]);
        $this->assert_deadline(
            $this->now + 3 * DAYSECS,
            deadline_resolver::ORIGIN_ACTIVITY_OVERRIDE,
            $useroverride->cmid,
            $student->id
        );

        $groupoverride = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $generator->create_override([
            'assignid' => $groupoverride->id,
            'groupid' => $this->new_group_with($course->id, $student->id),
            'duedate' => $this->now + 4 * DAYSECS,
            'sortorder' => 1,
        ]);
        $this->assert_deadline(
            $this->now + 4 * DAYSECS,
            deadline_resolver::ORIGIN_ACTIVITY_OVERRIDE,
            $groupoverride->cmid,
            $student->id
        );
    }

    /**
     * Assignment group overrides follow their priority, not the latest date (assign::override_exists()).
     *
     * @return void
     */
    public function test_assign_group_override_priority(): void {
        [$course, $student] = $this->create_course_with_users();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'duedate' => $this->now]);
        $generator->create_override([
            'assignid' => $assign->id,
            'groupid' => $this->new_group_with($course->id, $student->id),
            'duedate' => $this->now + 5 * DAYSECS,
            'sortorder' => 2,
        ]);
        $generator->create_override([
            'assignid' => $assign->id,
            'groupid' => $this->new_group_with($course->id, $student->id),
            'duedate' => $this->now + DAYSECS,
            'sortorder' => 1,
        ]);

        $this->assert_deadline($this->now + DAYSECS, deadline_resolver::ORIGIN_ACTIVITY_OVERRIDE, $assign->cmid, $student->id);
    }

    /**
     * A student override without a due date hides group overrides and keeps the assignment due date, as in core.
     *
     * @return void
     */
    public function test_assign_user_override_without_duedate(): void {
        [$course, $student] = $this->create_course_with_users();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'duedate' => $this->now]);
        $generator->create_override([
            'assignid' => $assign->id,
            'groupid' => $this->new_group_with($course->id, $student->id),
            'duedate' => $this->now + 5 * DAYSECS,
            'sortorder' => 1,
        ]);
        $generator->create_override([
            'assignid' => $assign->id,
            'userid' => $student->id,
            'cutoffdate' => $this->now + 9 * DAYSECS,
        ]);

        $this->assert_deadline($this->now, deadline_resolver::ORIGIN_DUEDATE, $assign->cmid, $student->id);
    }

    /**
     * An assignment override that removes the due date exempts the student, as for quizzes (DA1).
     *
     * @return void
     */
    public function test_assign_override_duedate_zero_exempts(): void {
        [$course, $student] = $this->create_course_with_users();

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'duedate' => $this->now]);
        $this->set_reminder($assign->cmid, $this->now);
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_override([
            'assignid' => $assign->id,
            'userid' => $student->id,
            'duedate' => 0,
        ]);

        $this->assert_deadline(0, deadline_resolver::ORIGIN_ACTIVITY_OVERRIDE, $assign->cmid, $student->id);
    }

    /**
     * Assignment without extension or override uses the due date chain (migrated from observer_test).
     *
     * @return void
     */
    public function test_assign_without_override(): void {
        [$course, $student] = $this->create_course_with_users();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'duedate' => $this->now]);

        $this->assert_deadline($this->now, deadline_resolver::ORIGIN_DUEDATE, $assign->cmid, $student->id);
    }

    // Lesson overrides.

    /**
     * Lesson student override deadline (migrated from observer_test).
     *
     * @return void
     */
    public function test_lesson_user_override(): void {
        [$course, $student] = $this->create_course_with_users();

        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_lesson')->create_override([
            'lessonid' => $lesson->id,
            'userid' => $student->id,
            'deadline' => $this->now + 6 * DAYSECS,
        ]);

        $this->assert_deadline($this->now + 6 * DAYSECS, deadline_resolver::ORIGIN_ACTIVITY_OVERRIDE, $lesson->cmid, $student->id);
    }

    /**
     * Lesson group overrides: the latest wins; a group without deadline leaves the reminder.
     *
     * @return void
     */
    public function test_lesson_group_overrides(): void {
        [$course, $student] = $this->create_course_with_users();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_lesson');

        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id]);
        $this->set_reminder($lesson->cmid, $this->now);
        foreach ([DAYSECS, 3 * DAYSECS] as $offset) {
            $generator->create_override([
                'lessonid' => $lesson->id,
                'groupid' => $this->new_group_with($course->id, $student->id),
                'deadline' => $this->now + $offset,
            ]);
        }
        $this->assert_deadline($this->now + 3 * DAYSECS, deadline_resolver::ORIGIN_ACTIVITY_OVERRIDE, $lesson->cmid, $student->id);

        $generator->create_override([
            'lessonid' => $lesson->id,
            'groupid' => $this->new_group_with($course->id, $student->id),
            'deadline' => 0,
        ]);
        $this->assert_deadline($this->now, deadline_resolver::ORIGIN_REMINDER, $lesson->cmid, $student->id);
    }

    /**
     * Modules without native overrides fall through to their activity date (migrated from observer_test).
     *
     * @return void
     */
    public function test_forum_has_no_activity_override_step(): void {
        [$course, $student] = $this->create_course_with_users();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        $this->assert_deadline(0, deadline_resolver::ORIGIN_NONE, $forum->cmid, $student->id);
    }

    // Late Penalty overrides (steps 1 and 2).

    /**
     * Late Penalty overrides win over extensions and activity overrides; rates follow the plugin override.
     *
     * @return void
     */
    public function test_plugin_overrides_come_first(): void {
        [$course, $student] = $this->create_course_with_users();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'duedate' => $this->now]);
        $this->grant_extension($assign, $student->id, $this->now + DAYSECS);

        $groupid = $this->new_group_with($course->id, $student->id);
        $this->lp_generator()->create_group_override([
            'cmid' => $assign->cmid,
            'groupid' => $groupid,
            'deadline' => $this->now + 2 * DAYSECS,
            'daily_penalty' => 4.0,
        ]);
        $resolved = deadline_resolver::for_user($this->cm($assign->cmid), $student->id);
        $this->assertSame([$this->now + 2 * DAYSECS, deadline_resolver::ORIGIN_PLUGIN_GROUP], [$resolved->time, $resolved->origin]);
        $this->assertSame([4.0, null], [$resolved->daily, $resolved->max]);

        $this->lp_generator()->create_override([
            'cmid' => $assign->cmid,
            'userid' => $student->id,
            'deadline' => $this->now + 3 * DAYSECS,
            'max_penalty' => 20.0,
        ]);
        $resolved = deadline_resolver::for_user($this->cm($assign->cmid), $student->id);
        $this->assertSame([$this->now + 3 * DAYSECS, deadline_resolver::ORIGIN_PLUGIN_USER], [$resolved->time, $resolved->origin]);
        $this->assertSame([4.0, 20.0], [$resolved->daily, $resolved->max]);
        $this->assertSame(4.0, $resolved->daily_for((object) ['daily_penalty' => 10.0]));
        $this->assertSame(20.0, $resolved->max_for((object) ['max_penalty' => 50.0]));
    }

    /**
     * A Late Penalty override with rates only keeps the rest of the chain for the deadline.
     *
     * @return void
     */
    public function test_plugin_override_without_deadline(): void {
        [$course, $student] = $this->create_course_with_users();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'duedate' => $this->now]);
        $this->grant_extension($assign, $student->id, $this->now + DAYSECS);
        $this->lp_generator()->create_override(['cmid' => $assign->cmid, 'userid' => $student->id, 'daily_penalty' => 2.0]);

        $resolved = deadline_resolver::for_user($this->cm($assign->cmid), $student->id);

        $this->assertSame([$this->now + DAYSECS, deadline_resolver::ORIGIN_EXTENSION], [$resolved->time, $resolved->origin]);
        $this->assertSame(2.0, $resolved->daily);
        $this->assertSame(50.0, $resolved->max_for((object) ['max_penalty' => 50.0]));
    }

    // Bulk paths (F2-11, G14).

    /**
     * Single, per-activity, per-student and matrix calls give identical results (F2-11).
     *
     * @return void
     */
    public function test_all_entry_points_agree(): void {
        [$course, $student] = $this->create_course_with_users();
        $users = [(int) $student->id];
        for ($i = 0; $i < 3; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
            $users[] = (int) $user->id;
        }
        $groupid = $this->new_group_with($course->id, $users[1]);

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'duedate' => $this->now]);
        $this->grant_extension($assign, $users[0], $this->now + DAYSECS);
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_override([
            'assignid' => $assign->id,
            'groupid' => $groupid,
            'duedate' => $this->now + 2 * DAYSECS,
            'sortorder' => 1,
        ]);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $this->set_reminder($quiz->cmid, $this->now);
        $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_override([
            'quiz' => $quiz->id,
            'userid' => $users[2],
            'timeclose' => $this->now + 3 * DAYSECS,
        ]);
        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_lesson')->create_override([
            'lessonid' => $lesson->id,
            'groupid' => $groupid,
            'deadline' => $this->now + 4 * DAYSECS,
        ]);
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'duedate' => $this->now]);
        $this->lp_generator()->create_override([
            'cmid' => $forum->cmid,
            'userid' => $users[3],
            'deadline' => $this->now + 5 * DAYSECS,
        ]);

        $cms = array_map(fn($module) => $this->cm($module->cmid), [$assign, $quiz, $lesson, $forum]);
        $matrix = deadline_resolver::resolve($cms, $users);

        foreach ($cms as $cm) {
            $this->assertEquals($matrix[$cm->id], deadline_resolver::for_users($cm, $users));
            foreach ($users as $userid) {
                $this->assertEquals($matrix[$cm->id][$userid], deadline_resolver::for_user($cm, $userid));
            }
        }
        foreach ($users as $userid) {
            $percm = deadline_resolver::for_user_in_cms($cms, $userid);
            foreach ($cms as $cm) {
                $this->assertEquals($matrix[$cm->id][$userid], $percm[$cm->id]);
            }
        }

        $this->assertSame(deadline_resolver::ORIGIN_EXTENSION, $matrix[$assign->cmid][$users[0]]->origin);
        $this->assertSame(deadline_resolver::ORIGIN_ACTIVITY_OVERRIDE, $matrix[$assign->cmid][$users[1]]->origin);
        $this->assertSame(deadline_resolver::ORIGIN_ACTIVITY_CLOSE, $matrix[$quiz->cmid][$users[2]]->origin);
        $this->assertSame(deadline_resolver::ORIGIN_ACTIVITY_OVERRIDE, $matrix[$lesson->cmid][$users[1]]->origin);
        $this->assertSame(deadline_resolver::ORIGIN_PLUGIN_USER, $matrix[$forum->cmid][$users[3]]->origin);
        $this->assertSame(deadline_resolver::ORIGIN_DUEDATE, $matrix[$forum->cmid][$users[0]]->origin);
    }

    /**
     * The number of queries does not depend on the number of students (G14).
     *
     * @return void
     */
    public function test_query_count_does_not_grow_with_students(): void {
        global $DB;

        [$course] = $this->create_course_with_users();
        $users = [];
        for ($i = 0; $i < 50; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
            $users[] = (int) $user->id;
        }
        $cms = [];
        foreach (['assign', 'quiz', 'lesson', 'forum'] as $modname) {
            $cms[] = $this->cm($this->getDataGenerator()->create_module($modname, ['course' => $course->id])->cmid);
        }
        deadline_resolver::quiz_has_duedate();

        $reads = $DB->perf_get_reads();
        deadline_resolver::resolve($cms, array_slice($users, 0, 5));
        $few = $DB->perf_get_reads() - $reads;

        $reads = $DB->perf_get_reads();
        deadline_resolver::resolve($cms, $users);
        $many = $DB->perf_get_reads() - $reads;

        $this->assertSame($few, $many);
    }

    /**
     * Empty input gives empty results without queries.
     *
     * @return void
     */
    public function test_empty_input(): void {
        [$course] = $this->create_course_with_users();
        $cm = $this->cm($this->getDataGenerator()->create_module('assign', ['course' => $course->id])->cmid);

        $this->assertSame([], deadline_resolver::for_users($cm, []));
        $this->assertSame([], deadline_resolver::resolve([], [1, 2]));
        $this->assertSame([], deadline_resolver::for_user_in_cms([], 1));
    }
}
