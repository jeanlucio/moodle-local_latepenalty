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

namespace local_latepenalty;

use local_latepenalty\tests\latepenalty_testcase;

/**
 * The notice on the activity page itself (before_http_headers hook).
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\hook_listener
 * @covers \local_latepenalty\local\deadline_resolver
 * @covers \local_latepenalty\penalty_helper
 */
final class activity_notice_test extends latepenalty_testcase {
    /** @var \stdClass Course with completion enabled. */
    private \stdClass $course;

    /** @var \stdClass Student. */
    private \stdClass $student;

    /** @var \stdClass Editing teacher. */
    private \stdClass $teacher;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
    }

    /**
     * Assignment with manual completion and an enabled rule (10% a day, 50% maximum).
     *
     * @param array $params Assignment settings.
     * @return \stdClass
     */
    private function assignment(array $params): \stdClass {
        $assign = $this->create_assign_activity($this->course, $params + ['completion' => COMPLETION_TRACKING_MANUAL]);
        $this->enable_rule($assign->cmid);
        return $assign;
    }

    /**
     * Open the activity page as a user and return the notice the plugin queued, if any.
     *
     * @param \stdClass $module Module record.
     * @param \stdClass|null $user User, or null for a guest.
     * @return string|null The notice text, or null when none was queued.
     */
    private function notice(\stdClass $module, ?\stdClass $user): ?string {
        global $PAGE;

        if ($user === null) {
            $this->setGuestUser();
        } else {
            $this->setUser($user);
        }
        $PAGE = new \moodle_page();
        $PAGE->set_course($this->course);
        $PAGE->set_cm(get_fast_modinfo($this->course)->get_cm($module->cmid));
        $PAGE->set_url('/mod/assign/view.php', ['id' => $module->cmid]);

        $hook = (new \ReflectionClass(\core\hook\output\before_http_headers::class))->newInstanceWithoutConstructor();
        hook_listener::inject_activity_notice($hook);

        $code = implode("\n", (new \ReflectionProperty($PAGE->requires, 'amdjscode'))->getValue($PAGE->requires));
        if (!str_contains($code, 'local_latepenalty/activityinfo')) {
            return null;
        }
        // The call looks like amd.init("notice"); followed by core's own completion call.
        preg_match('/\.init\((".*?")\);/s', $code, $matches);
        return json_decode($matches[1]);
    }

    /**
     * Student: before the deadline, overdue and at the maximum.
     *
     * @return void
     */
    public function test_student_notice_states(): void {
        $future = time() + 3 * DAYSECS;
        $ontime = $this->assignment(['duedate' => $future]);
        $this->assertSame(get_string('courseinfo_notice', 'local_latepenalty', (object) [
            'deadline' => penalty_helper::format_deadline($future),
            'daily' => '10',
            'max' => '50',
        ]), $this->notice($ontime, $this->student));

        $past = time() - 2 * DAYSECS + HOURSECS;
        $overdue = $this->assignment(['duedate' => $past]);
        $this->assertSame(get_string('courseinfo_notice_overdue', 'local_latepenalty', (object) [
            'deadline' => penalty_helper::format_deadline($past),
            'pct' => '20',
            'daily' => '10',
            'max' => '50',
        ]), $this->notice($overdue, $this->student));

        $longago = time() - 9 * DAYSECS;
        $atmax = $this->assignment(['duedate' => $longago]);
        $this->assertSame(get_string('courseinfo_notice_overdue_max', 'local_latepenalty', (object) [
            'deadline' => penalty_helper::format_deadline($longago),
            'max' => '50',
        ]), $this->notice($atmax, $this->student));
    }

    /**
     * Student: the notice follows the student's own deadline (an extension here).
     *
     * @return void
     */
    public function test_student_notice_uses_extension(): void {
        $assign = $this->assignment(['duedate' => time() - DAYSECS]);
        $extension = time() + 4 * DAYSECS;
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_extension([
            'cmid' => $assign->cmid,
            'userid' => $this->student->id,
            'extensionduedate' => $extension,
        ]);

        $this->assertStringContainsString(penalty_helper::format_deadline($extension), $this->notice($assign, $this->student));
    }

    /**
     * Student: no notice once the activity is complete.
     *
     * @return void
     */
    public function test_no_notice_when_completed(): void {
        $assign = $this->assignment(['duedate' => time() - DAYSECS]);
        [, $cm] = get_course_and_cm_from_cmid($assign->cmid, 'assign');
        (new \completion_info($this->course))->update_state($cm, COMPLETION_COMPLETE, $this->student->id);

        $this->assertNull($this->notice($assign, $this->student));
    }

    /**
     * Teacher: the overdue notice counts pending students; none pending, no notice; on time, the plain notice.
     *
     * @return void
     */
    public function test_teacher_notice(): void {
        $past = time() - 2 * DAYSECS + HOURSECS;
        $assign = $this->assignment(['duedate' => $past]);
        $this->assertSame(get_string('courseinfo_teacher_overdue', 'local_latepenalty', (object) [
            'deadline' => penalty_helper::format_deadline($past),
            'pct' => '20',
            'daily' => '10',
            'max' => '50',
            'pending' => 1,
        ]), $this->notice($assign, $this->teacher));

        [, $cm] = get_course_and_cm_from_cmid($assign->cmid, 'assign');
        (new \completion_info($this->course))->update_state($cm, COMPLETION_COMPLETE, $this->student->id);
        $this->assertNull($this->notice($assign, $this->teacher));

        $future = $this->assignment(['duedate' => time() + DAYSECS]);
        $this->assertStringContainsString('10', (string) $this->notice($future, $this->teacher));
    }

    /**
     * No notice: guest, rule disabled, no deadline, scale grade.
     *
     * @return void
     */
    public function test_no_notice_cases(): void {
        global $DB;

        $assign = $this->assignment(['duedate' => time() + DAYSECS]);
        $this->assertNull($this->notice($assign, null), 'Guest');

        $DB->set_field('local_latepenalty_rules', 'enabled', 0, ['cmid' => $assign->cmid]);
        $this->assertNull($this->notice($assign, $this->student), 'Rule disabled');

        $nodeadline = $this->assignment(['duedate' => 0]);
        $this->assertNull($this->notice($nodeadline, $this->student), 'No deadline (student)');
        $this->assertNull($this->notice($nodeadline, $this->teacher), 'No deadline (teacher)');

        $scale = $this->getDataGenerator()->create_scale(['courseid' => $this->course->id]);
        $scaled = $this->assignment(['duedate' => time() + DAYSECS, 'grade' => -$scale->id]);
        $this->assertNull($this->notice($scaled, $this->student), 'Scale grade');
    }

    /**
     * No notice on a page outside any activity.
     *
     * @return void
     */
    public function test_no_notice_outside_activities(): void {
        global $PAGE;

        $this->setUser($this->student);
        $PAGE = new \moodle_page();
        $PAGE->set_course($this->course);
        $PAGE->set_url('/course/view.php', ['id' => $this->course->id]);
        $hook = (new \ReflectionClass(\core\hook\output\before_http_headers::class))->newInstanceWithoutConstructor();

        hook_listener::inject_activity_notice($hook);

        $code = implode("\n", (new \ReflectionProperty($PAGE->requires, 'amdjscode'))->getValue($PAGE->requires));
        $this->assertStringNotContainsString('local_latepenalty/activityinfo', $code);
    }
}
