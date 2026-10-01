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
 * Activity overrides and extensions recalculate the student at once (F7).
 *
 * Overrides are saved the way each module saves them: the quiz through its
 * override_manager; assignments and lessons have no API for saving, so the
 * test writes what their overrideedit.php writes and fires the same event,
 * and deletes through assign::delete_override() / lesson::delete_override().
 *
 * Running example: deadline D, student graded 100 two days late (80). An
 * override to D + 3 days puts the student on time (100); moved to D - 1 day,
 * three days late (70); deleted, back to 80.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_latepenalty\observer
 * @covers \local_latepenalty\recalculator
 */
final class activity_overrides_test extends latepenalty_testcase {
    /**
     * Modules with overrides, for students and groups.
     *
     * @return array
     */
    public static function overrides(): array {
        $cases = [];
        foreach (['assign', 'quiz', 'lesson'] as $modname) {
            foreach (['user', 'group'] as $kind) {
                $cases["$modname $kind"] = [$modname, $kind];
            }
        }
        return $cases;
    }

    /**
     * An activity with a rule and a student graded 100 two days after its deadline.
     *
     * @param string $modname assign, quiz or lesson.
     * @return array [module record, student, course, deadline]
     */
    private function graded_late(string $modname): array {
        global $CFG, $DB;

        $deadline = time() - 5 * DAYSECS;
        [$course, $student, $teacher] = $this->create_course_with_users();
        $late = $deadline + 2 * DAYSECS - 60;

        switch ($modname) {
            case 'assign':
                $deadline = time() - 2 * DAYSECS + HOURSECS;
                $module = $this->create_assign_activity($course, ['duedate' => $deadline]);
                $this->enable_rule($module->cmid);
                $this->submit_assign($module, $student);
                $this->grade_assign($module, $student, $teacher, 100);
                break;
            case 'quiz':
                $module = $this->create_quiz_with_questions($course, 10);
                $this->set_reminder($module->cmid, $deadline);
                $this->enable_rule($module->cmid);
                $this->attempt_quiz($module, $student, 10, $late);
                break;
            default:
                require_once($CFG->dirroot . '/mod/lesson/lib.php');
                $module = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id, 'grade' => 100]);
                $this->set_reminder($module->cmid, $deadline);
                $this->enable_rule($module->cmid);
                $lesson = $DB->get_record('lesson', ['id' => $module->id], '*', MUST_EXIST);
                $lesson->cmidnumber = $module->cmid;
                // Mirrors the end of lesson in mod/lesson/locallib.php.
                $DB->insert_record('lesson_grades', (object) [
                    'lessonid' => $lesson->id,
                    'userid' => $student->id,
                    'grade' => 100,
                    'completed' => $late,
                ]);
                lesson_update_grades($lesson, $student->id);
                break;
        }
        $this->assertSame(80.0, $this->final_grade($modname, $module->id, $student->id));
        return [$module, $student, $course, $deadline];
    }

    /**
     * Create or update an activity override the way the module saves it.
     *
     * @param string $modname assign, quiz or lesson.
     * @param \stdClass $module Module record.
     * @param array $target ['userid' => ...] or ['groupid' => ...].
     * @param int $deadline New deadline of the override.
     * @param int|null $id Override ID to update, null to create.
     * @return int Override ID.
     */
    private function save_override(string $modname, \stdClass $module, array $target, int $deadline, ?int $id): int {
        global $DB;

        $context = \context_module::instance($module->cmid);
        if ($modname === 'quiz') {
            $manager = new \mod_quiz\local\override_manager(\mod_quiz\quiz_settings::create($module->id)->get_quiz(), $context);
            $field = \local_latepenalty\local\deadline_resolver::quiz_has_duedate() ? 'duedate' : 'timeclose';
            $data = $target + ['quiz' => $module->id, $field => $deadline];
            if ($id) {
                $data['id'] = $id;
            }
            return $manager->save_override($data);
        }

        if ($modname === 'assign' && class_exists(\mod_assign\override_manager::class)) {
            // Moodle 5.3+: overrideedit.php saves through the assignment override manager.
            $manager = new \mod_assign\override_manager($DB->get_record('assign', ['id' => $module->id]), $context);
            $data = $target + ['assignid' => $module->id, 'duedate' => $deadline];
            if ($id) {
                $data['id'] = $id;
            }
            return $manager->save_overrides([$data])[0];
        }

        // Mirrors mod/assign/overrideedit.php (before 5.3) and mod/lesson/overrideedit.php.
        [$table, $instancefield, $datefield] = $modname === 'assign'
            ? ['assign_overrides', 'assignid', 'duedate']
            : ['lesson_overrides', 'lessonid', 'deadline'];
        $record = (object) ($target + [$instancefield => $module->id, $datefield => $deadline]);
        if ($id) {
            $record->id = $id;
            $DB->update_record($table, $record);
        } else {
            if (isset($target['groupid']) && $modname === 'assign') {
                $record->sortorder = 1;
            }
            $id = $DB->insert_record($table, $record);
        }
        $kind = isset($target['userid']) ? 'user' : 'group';
        $action = $record->id ?? false ? 'updated' : 'created';
        $params = ['context' => $context, 'objectid' => $id, 'other' => [$instancefield => $module->id]];
        if ($kind === 'user') {
            $params['relateduserid'] = $target['userid'];
        } else {
            $params['other']['groupid'] = $target['groupid'];
        }
        $class = "\\mod_{$modname}\\event\\{$kind}_override_{$action}";
        $class::create($params)->trigger();
        return $id;
    }

    /**
     * Delete an activity override through the module.
     *
     * @param string $modname assign, quiz or lesson.
     * @param \stdClass $module Module record.
     * @param int $id Override ID.
     * @return void
     */
    private function delete_override(string $modname, \stdClass $module, int $id): void {
        global $CFG, $DB;

        [$course, $cm] = get_course_and_cm_from_cmid($module->cmid, $modname);
        $context = \context_module::instance($cm->id);
        switch ($modname) {
            case 'quiz':
                $manager = new \mod_quiz\local\override_manager(\mod_quiz\quiz_settings::create($module->id)->get_quiz(), $context);
                $manager->delete_overrides([$DB->get_record('quiz_overrides', ['id' => $id])]);
                break;
            case 'assign':
                if (class_exists(\mod_assign\override_manager::class)) {
                    $manager = new \mod_assign\override_manager($DB->get_record('assign', ['id' => $module->id]), $context);
                    $manager->delete_overrides_by_id([$id]);
                } else {
                    require_once($CFG->dirroot . '/mod/assign/locallib.php');
                    (new \assign($context, $cm, $course))->delete_override($id);
                }
                break;
            default:
                require_once($CFG->dirroot . '/mod/lesson/locallib.php');
                (new \lesson($DB->get_record('lesson', ['id' => $module->id]), $cm, $course))->delete_override($id);
                break;
        }
    }

    /**
     * Creating, changing and deleting an override recalculates at once (F7-01).
     *
     * @dataProvider overrides
     * @param string $modname Module name.
     * @param string $kind user or group.
     * @return void
     */
    public function test_override_lifecycle_recalculates(string $modname, string $kind): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$module, $student, $course, $deadline] = $this->graded_late($modname);

        if ($kind === 'user') {
            $target = ['userid' => $student->id];
        } else {
            $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
            $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);
            $target = ['groupid' => (int) $group->id];
        }

        $id = $this->save_override($modname, $module, $target, $deadline + 3 * DAYSECS, null);
        $this->assertSame(100.0, $this->final_grade($modname, $module->id, $student->id), 'created');

        $this->save_override($modname, $module, $target, $deadline - DAYSECS, $id);
        $this->assertSame(70.0, $this->final_grade($modname, $module->id, $student->id), 'updated');

        $this->delete_override($modname, $module, $id);
        $this->assertSame(80.0, $this->final_grade($modname, $module->id, $student->id), 'deleted');
    }

    /**
     * Granting an assignment extension recalculates at once (F7-02).
     *
     * @return void
     */
    public function test_extension_recalculates(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$assign, $student, , $deadline] = $this->graded_late('assign');

        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_extension([
            'cmid' => $assign->cmid,
            'userid' => $student->id,
            'extensionduedate' => $deadline + 3 * DAYSECS,
        ]);

        $this->assertSame(100.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * Disabled rule, student without grade, teacher edit, locked grade: nothing happens (F7-03, F7-04).
     *
     * @return void
     */
    public function test_nothing_to_do(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Disabled rule.
        [$assign, $student, , $deadline] = $this->graded_late('assign');
        $DB->set_field('local_latepenalty_rules', 'enabled', 0, ['cmid' => $assign->cmid]);
        $this->save_override('assign', $assign, ['userid' => $student->id], $deadline + 3 * DAYSECS, null);
        $this->assertSame(80.0, $this->final_grade('assign', $assign->id, $student->id));

        // Student without a grade.
        [$assign, , $course, $deadline] = $this->graded_late('assign');
        $ungraded = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->save_override('assign', $assign, ['userid' => $ungraded->id], $deadline + 3 * DAYSECS, null);
        $this->assertNull($this->final_grade('assign', $assign->id, $ungraded->id));

        // Teacher edit and locked grade.
        [$assign, $student, $course, $deadline] = $this->graded_late('assign');
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id,
            'courseid' => $course->id]);
        $item->update_final_grade($student->id, 55.0, 'gradebook');
        $locked = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->submit_assign($assign, $locked);
        $this->grade_assign($assign, $locked, get_admin(), 100);
        \grade_grade::fetch(['itemid' => $item->id, 'userid' => $locked->id])->set_locked(1);
        foreach ([$student, $locked] as $user) {
            $this->save_override('assign', $assign, ['userid' => $user->id], $deadline + 3 * DAYSECS, null);
        }
        $this->assertSame(55.0, $this->final_grade('assign', $assign->id, $student->id));
        $this->assertSame(80.0, $this->final_grade('assign', $assign->id, $locked->id));
    }

    /**
     * A group override reaches only its members; a student in two groups gets the most lenient (F7-07).
     *
     * @return void
     */
    public function test_group_scope(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$quiz, $student, $course, $deadline] = $this->graded_late('quiz');
        $outsider = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->attempt_quiz($quiz, $outsider, 10, $deadline + 2 * DAYSECS - 60);

        $groups = [];
        foreach ([2, 3] as $days) {
            $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
            $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);
            $this->save_override('quiz', $quiz, ['groupid' => (int) $group->id], $deadline + $days * DAYSECS, null);
            $groups[] = $group;
        }

        $this->assertSame(100.0, $this->final_grade('quiz', $quiz->id, $student->id));
        $this->assertSame(80.0, $this->final_grade('quiz', $quiz->id, $outsider->id));
    }

    /**
     * An override of another activity leaves this one alone (F7-08).
     *
     * @return void
     */
    public function test_other_activity_untouched(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$assign, $student, $course, $deadline] = $this->graded_late('assign');
        $other = $this->create_assign_activity($course, ['duedate' => $deadline]);
        $this->enable_rule($other->cmid);

        $this->save_override('assign', $other, ['userid' => $student->id], $deadline + 3 * DAYSECS, null);

        $this->assertSame(80.0, $this->final_grade('assign', $assign->id, $student->id));
    }

    /**
     * A group override with many members costs the same queries as with a few (F7-09).
     *
     * @return void
     */
    public function test_group_override_queries_do_not_grow(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $reads = [];
        foreach ([3, 40] as $size) {
            [$assign, $student, $course, $deadline] = $this->graded_late('assign');
            $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
            $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);
            for ($i = 1; $i < $size; $i++) {
                $member = $this->getDataGenerator()->create_and_enrol($course, 'student');
                $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $member->id]);
            }
            // Warm the per-request caches first.
            recalculator::recalculate_for_group($assign->cmid, (int) $group->id, 10.0, 50.0);

            $before = $DB->perf_get_reads();
            recalculator::recalculate_for_group($assign->cmid, (int) $group->id, 10.0, 50.0);
            $reads[$size] = $DB->perf_get_reads() - $before;
        }

        $this->assertSame($reads[3], $reads[40]);
    }

    /**
     * The registered observers are exactly the expected events, and every class exists (F7-10).
     *
     * @return void
     */
    public function test_registered_events(): void {
        global $CFG;

        $observers = [];
        require($CFG->dirroot . '/local/latepenalty/db/events.php');
        $events = array_column(array_filter(
            $observers,
            fn(array $observer): bool => $observer['callback'] === '\local_latepenalty\observer::activity_deadline_changed'
        ), 'eventname');

        $expected = ['\mod_assign\event\extension_granted'];
        foreach (['assign', 'lesson', 'quiz'] as $modname) {
            foreach (['user', 'group'] as $kind) {
                foreach (['created', 'updated', 'deleted'] as $action) {
                    $expected[] = "\\mod_{$modname}\\event\\{$kind}_override_{$action}";
                }
            }
        }
        sort($expected);
        sort($events);
        $this->assertSame($expected, $events);
        foreach ($events as $event) {
            $this->assertTrue(class_exists($event), $event);
        }
    }
}
