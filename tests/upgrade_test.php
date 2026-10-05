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

use local_latepenalty\task\reprocess_grades;
use local_latepenalty\tests\latepenalty_testcase;

/**
 * The upgrade from 1.1.x and the post-installation step (I-02).
 *
 * The keepbest column is dropped and the real upgrade function adds it back,
 * as on a site still on 1.1.x; the column is restored in any case so the
 * schema of the test site never changes.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::xmldb_local_latepenalty_upgrade
 * @covers ::xmldb_local_latepenalty_install
 */
final class upgrade_test extends latepenalty_testcase {
    /** @var int Version of local_latepenalty 1.1.2. */
    private const V112 = 2026092500;

    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/latepenalty/db/upgrade.php');
        require_once($CFG->dirroot . '/local/latepenalty/db/install.php');
        parent::setUpBeforeClass();
    }

    /**
     * The keepbest column definition, as the upgrade step adds it.
     *
     * @return array [table, field]
     */
    private function keepbest(): array {
        return [
            new \xmldb_table('local_latepenalty_rules'),
            new \xmldb_field('keepbest', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'last_deadline'),
        ];
    }

    /**
     * A 1.1.x site: the column is added with 0, rules stay as they were, the task cursor starts at the end of the history.
     *
     * @return void
     */
    public function test_upgrade_from_112(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $student, $teacher] = $this->create_course_with_users();
        $assign = $this->create_assign_activity($course, ['duedate' => time() - 2 * DAYSECS + HOURSECS]);
        $this->enable_rule($assign->cmid, 7.5, 30.0);
        $this->submit_assign($assign, $student);
        $this->grade_assign($assign, $student, $teacher, 100);
        $before = $DB->get_record('local_latepenalty_rules', ['cmid' => $assign->cmid], '*', MUST_EXIST);

        $dbman = $DB->get_manager();
        [$table, $field] = $this->keepbest();
        try {
            $dbman->drop_field($table, $field);
            unset_config(reprocess_grades::CURSOR, 'local_latepenalty');
            set_config('version', self::V112, 'local_latepenalty');

            $this->assertTrue(xmldb_local_latepenalty_upgrade(self::V112));

            $this->assertTrue($dbman->field_exists($table, 'keepbest'));
            $after = $DB->get_record('local_latepenalty_rules', ['cmid' => $assign->cmid], '*', MUST_EXIST);
            $this->assertSame('0', (string) $after->keepbest);
            // The rule is on and penalised: it is dated from its first penalty (F19).
            $this->assertGreaterThan(0, (int) $after->timeenabled);
            unset($after->keepbest, $before->keepbest, $after->timeenabled, $before->timeenabled);
            $this->assertEquals($before, $after);
            $this->assertSame(
                (int) $DB->get_field_sql('SELECT MAX(id) FROM {grade_grades_history}'),
                (int) get_config('local_latepenalty', reprocess_grades::CURSOR)
            );
            $this->assertEquals(2026100501, get_config('local_latepenalty', 'version'));

            // Running the step again (an interrupted upgrade resumed) does not fail.
            set_config('version', self::V112, 'local_latepenalty');
            $this->assertTrue(xmldb_local_latepenalty_upgrade(self::V112));
            $this->assertTrue($dbman->field_exists($table, 'keepbest'));
        } finally {
            if (!$dbman->field_exists($table, 'keepbest')) {
                $dbman->add_field($table, $field);
            }
        }
    }

    /**
     * A site already on the current version runs no step.
     *
     * @return void
     */
    public function test_upgrade_from_current_version(): void {
        $this->resetAfterTest();
        set_config(reprocess_grades::CURSOR, 42, 'local_latepenalty');

        $this->assertTrue(xmldb_local_latepenalty_upgrade(2026100501));

        $this->assertEquals(42, get_config('local_latepenalty', reprocess_grades::CURSOR));
    }

    /**
     * The first enabling date is filled from what the site can tell (F19-15).
     *
     * Where the plugin penalised, its first penalty; a rule that is on but never penalised, the upgrade
     * time; a rule that is off and never penalised, 0 (the next enabling counts as the first).
     *
     * @return void
     */
    public function test_upgrade_fills_first_enabling(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $student, $teacher] = $this->create_course_with_users();
        $late = time() - 2 * DAYSECS + HOURSECS;
        $penalised = $this->create_assign_activity($course, ['duedate' => $late]);
        $this->enable_rule($penalised->cmid);
        $this->submit_assign($penalised, $student);
        $this->grade_assign($penalised, $student, $teacher, 100);
        $offpenalised = $this->create_assign_activity($course, ['duedate' => $late]);
        $this->enable_rule($offpenalised->cmid);
        $this->submit_assign($offpenalised, $student);
        $this->grade_assign($offpenalised, $student, $teacher, 100);
        $this->lp_generator()->create_rule(['cmid' => $offpenalised->cmid, 'enabled' => 0]);
        $onnever = $this->create_assign_activity($course, ['duedate' => time() + DAYSECS]);
        $this->enable_rule($onnever->cmid);
        $offnever = $this->create_assign_activity($course, ['duedate' => time() + DAYSECS]);
        $firstpenalty = fn(\stdClass $assign): int => (int) $DB->get_field_sql(
            "SELECT MIN(h.timemodified)
               FROM {grade_grades_history} h
               JOIN {grade_items} gi ON gi.id = h.itemid
              WHERE gi.itemmodule = 'assign' AND gi.iteminstance = :instance AND h.source = :source",
            ['instance' => $assign->id, 'source' => 'local_latepenalty']
        );
        $this->assertGreaterThan(0, $firstpenalty($penalised));
        // Every rule as before the upgrade: no first enabling date yet.
        $DB->set_field_select('local_latepenalty_rules', 'timeenabled', 0, 'timeenabled <> 0');
        $before = time();
        set_config('version', 2026100100, 'local_latepenalty');

        $this->assertTrue(xmldb_local_latepenalty_upgrade(2026100100));

        $timeenabled = fn(\stdClass $assign): int => (int) $DB->get_field(
            'local_latepenalty_rules',
            'timeenabled',
            ['cmid' => $assign->cmid]
        );
        $this->assertSame($firstpenalty($penalised), $timeenabled($penalised), 'On, penalised');
        $this->assertSame($firstpenalty($offpenalised), $timeenabled($offpenalised), 'Off, penalised');
        $this->assertGreaterThanOrEqual($before, $timeenabled($onnever), 'On, never penalised');
        $this->assertSame(0, $timeenabled($offnever), 'Off, never penalised');
    }

    /**
     * A new installation starts the task cursor at the end of the grade history.
     *
     * @return void
     */
    public function test_install_sets_cursor(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $student, $teacher] = $this->create_course_with_users();
        $assign = $this->create_assign_activity($course);
        $this->submit_assign($assign, $student);
        $this->grade_assign($assign, $student, $teacher, 50);
        unset_config(reprocess_grades::CURSOR, 'local_latepenalty');

        $this->assertTrue(xmldb_local_latepenalty_install());

        $this->assertSame(
            (int) $DB->get_field_sql('SELECT MAX(id) FROM {grade_grades_history}'),
            (int) get_config('local_latepenalty', reprocess_grades::CURSOR)
        );
    }
}
