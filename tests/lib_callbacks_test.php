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
 * The lib.php callbacks: form validation, navigation links and form edge cases.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::local_latepenalty_coursemodule_validation
 * @covers ::local_latepenalty_extend_settings_navigation
 * @covers ::local_latepenalty_extend_navigation_course
 * @covers ::local_latepenalty_coursemodule_standard_elements
 * @covers ::local_latepenalty_coursemodule_edit_post_actions
 * @covers ::local_latepenalty_without_numeric_grade
 */
final class lib_callbacks_test extends latepenalty_testcase {
    /**
     * The section on its own, for a module (see lib_test::plugin_section()).
     *
     * @param int $cmid Course module ID (0 when creating).
     * @param string $modname Module name.
     * @return \MoodleQuickForm
     */
    private function section(int $cmid, string $modname): \MoodleQuickForm {
        global $CFG;
        require_once($CFG->libdir . '/formslib.php');

        $mform = new \MoodleQuickForm('latepenaltytest', 'post', '');
        $wrapper = new class ($cmid, $modname) {
            /**
             * Constructor.
             *
             * @param int $cmid Course module ID.
             * @param string $modname Module name.
             */
            public function __construct(
                /** @var int Course module ID. */
                private int $cmid,
                /** @var string Module name. */
                private string $modname
            ) {
            }

            /**
             * The current activity data, as moodleform_mod::get_current() returns it.
             *
             * @return \stdClass
             */
            public function get_current(): \stdClass {
                return (object) ['modulename' => $this->modname, 'coursemodule' => $this->cmid];
            }
        };
        local_latepenalty_coursemodule_standard_elements($wrapper, $mform);
        return $mform;
    }

    /**
     * Form validation through core: the rule's ranges are checked as the activity form checks them.
     *
     * Core calls the callback with the form first and the submitted data second
     * (moodleform_mod::plugin_extend_coursemodule_validation()). Regression guard:
     * the callback read its first argument as the data, so it never found the rule
     * and accepted any rate, 150% or -10% included.
     *
     * @return void
     */
    public function test_validation(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/moodleform_mod.php');
        require_once($CFG->dirroot . '/mod/assign/mod_form.php');

        $this->resetAfterTest();
        // Core's own caller; it reads no form state, so the form is not built (no constructor).
        $form = (new \ReflectionClass(\mod_assign_mod_form::class))->newInstanceWithoutConstructor();
        $validate = fn(array $values): array => (new \ReflectionMethod($form, 'plugin_extend_coursemodule_validation'))
            ->invoke($form, $values);

        $this->assertSame([], $validate(['latepenalty_enabled' => 0, 'latepenalty_daily' => 500, 'latepenalty_max' => -3]));
        $this->assertSame([], $validate(['latepenalty_enabled' => 1, 'latepenalty_daily' => 10, 'latepenalty_max' => 50]));

        $errors = $validate(['latepenalty_enabled' => 1, 'latepenalty_daily' => 150, 'latepenalty_max' => 50]);
        $this->assertSame(get_string('error_daily_range', 'local_latepenalty'), $errors['latepenalty_daily']);

        $errors = $validate(['latepenalty_enabled' => 1, 'latepenalty_daily' => 30, 'latepenalty_max' => 20]);
        $this->assertSame(get_string('error_max_less_than_daily', 'local_latepenalty'), $errors['latepenalty_max']);
        $this->assertArrayNotHasKey('latepenalty_daily', $errors);

        $errors = $validate(['latepenalty_enabled' => 1, 'latepenalty_daily' => -1, 'latepenalty_max' => 1000]);
        $this->assertSame(get_string('error_daily_range', 'local_latepenalty'), $errors['latepenalty_daily']);
        $this->assertSame(get_string('error_max_range', 'local_latepenalty'), $errors['latepenalty_max']);
    }

    /**
     * Activity settings menu: the overrides link only with an enabled rule and the capability.
     *
     * @return void
     */
    public function test_settings_navigation_link(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $student, $teacher] = $this->create_course_with_users();
        $assign = $this->create_assign_activity($course);
        [, $cm] = get_course_and_cm_from_cmid($assign->cmid, 'assign');

        $haslink = function (\stdClass $user, bool $activity = true) use ($cm, $course): bool {
            global $PAGE;
            $this->setUser($user);
            // The settings menu is built for the global page, as on a real request.
            $PAGE = new \moodle_page();
            $PAGE->set_course($course);
            if ($activity) {
                $PAGE->set_cm($cm);
                $PAGE->set_url('/mod/assign/view.php', ['id' => $cm->id]);
            } else {
                $PAGE->set_url('/course/view.php', ['id' => $course->id]);
            }
            // Initialising the menu runs every plugin's extend_settings_navigation callback.
            $settingsnav = $PAGE->settingsnav;
            $settingsnav->initialise();
            return (bool) $settingsnav->find('local_latepenalty_overrides', \navigation_node::TYPE_SETTING);
        };

        $this->assertFalse($haslink($teacher), 'Disabled rule');
        $this->enable_rule($assign->cmid);
        $this->assertTrue($haslink($teacher), 'Teacher with an enabled rule');
        $this->assertFalse($haslink($student), 'Student');
        $this->assertFalse($haslink($teacher, false), 'Course context');
    }

    /**
     * The overrides link uses a core icon that exists as an image and as a Font Awesome icon.
     *
     * Regression guard: 'i/override' exists in neither, so themes that show icons
     * in the activity administration (Classic) showed a broken image.
     *
     * @return void
     */
    public function test_settings_navigation_icon_exists(): void {
        global $CFG, $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, , $teacher] = $this->create_course_with_users();
        $assign = $this->create_assign_activity($course);
        $this->enable_rule($assign->cmid);
        [, $cm] = get_course_and_cm_from_cmid($assign->cmid, 'assign');
        $this->setUser($teacher);
        $PAGE = new \moodle_page();
        $PAGE->set_course($course);
        $PAGE->set_cm($cm);
        $PAGE->set_url('/mod/assign/view.php', ['id' => $cm->id]);
        $PAGE->settingsnav->initialise();

        $icon = $PAGE->settingsnav->find('local_latepenalty_overrides', \navigation_node::TYPE_SETTING)->icon;

        $this->assertSame('moodle', $icon->component);
        $this->assertNotEmpty(glob($CFG->dirroot . '/pix/' . $icon->pix . '.{svg,png}', GLOB_BRACE), 'Image');
        $map = \core\output\icon_system::instance(\core\output\icon_system::FONTAWESOME)->get_core_icon_map();
        $this->assertArrayHasKey('core:' . $icon->pix, $map, 'Font Awesome');
    }

    /**
     * Course navigation: the report link for teachers only.
     *
     * @return void
     */
    public function test_course_navigation_link(): void {
        $this->resetAfterTest();
        [$course, $student, $teacher] = $this->create_course_with_users();
        $context = \context_course::instance($course->id);

        foreach ([[$teacher, true], [$student, false]] as [$user, $expected]) {
            $this->setUser($user);
            $root = \navigation_node::create('root');
            local_latepenalty_extend_navigation_course($root, $course, $context);
            $this->assertSame($expected, (bool) $root->get('local_latepenalty_report'));
        }
    }

    /**
     * Form: resources and a form without a module get no section (G09).
     *
     * @return void
     */
    public function test_no_section_for_resources(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertFalse($this->section(0, 'page')->elementExists('latepenaltyheader'));
        $this->assertFalse($this->section(0, '')->elementExists('latepenaltyheader'));
        $this->assertTrue($this->section(0, 'assign')->elementExists('latepenaltyheader'));
    }

    /**
     * Form: the keep-best checkbox exists for an external tool, with its saved value and help (F15-05).
     *
     * @return void
     */
    public function test_keepbest_checkbox_on_external_tool(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$course] = $this->create_course_with_users();
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id]);
        $this->enable_rule($lti->cmid);
        $DB->set_field('local_latepenalty_rules', 'keepbest', 1, ['cmid' => $lti->cmid]);

        $form = $this->section($lti->cmid, 'lti');

        $this->assertTrue($form->elementExists('latepenalty_keepbest'));
        $this->assertNotEmpty($form->getElement('latepenalty_keepbest')->_helpbutton);
        $this->assertEquals(1, $form->_defaultValues['latepenalty_keepbest']);
        $this->assertFalse($this->section($lti->cmid, 'assign')->elementExists('latepenalty_keepbest'));
    }

    /**
     * Saving without a course module does nothing; a deleted activity is not graded without numbers.
     *
     * @return void
     */
    public function test_edge_cases(): void {
        global $DB;

        $this->resetAfterTest();
        $before = $DB->count_records('local_latepenalty_rules');
        $data = (object) ['latepenalty_enabled' => 1];

        $this->assertSame($data, local_latepenalty_coursemodule_edit_post_actions($data, get_site()));
        $this->assertSame($before, $DB->count_records('local_latepenalty_rules'));
        $this->assertFalse(local_latepenalty_without_numeric_grade(999999));
    }
}
