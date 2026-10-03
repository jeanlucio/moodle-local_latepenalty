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
 * Library functions for the Late Penalty plugin.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add late penalty configuration fields to the course module form.
 *
 * @param moodleform_mod $formwrapper The moodle form wrapper object.
 * @param MoodleQuickForm $mform The actual form object.
 * @return void
 */
function local_latepenalty_coursemodule_standard_elements($formwrapper, $mform): void {
    global $CFG, $DB, $OUTPUT;

    // Skip resources — they have no submission or grade.
    $modname = $formwrapper->get_current()->modulename ?? '';
    if (!$modname) {
        return;
    }
    $archetype = plugin_supports('mod', $modname, FEATURE_MOD_ARCHETYPE, MOD_ARCHETYPE_OTHER);
    if ($archetype === MOD_ARCHETYPE_RESOURCE) {
        return;
    }

    $headerel = $mform->addElement(
        'header',
        'latepenaltyheader',
        get_string('latepenalty', 'local_latepenalty')
    );

    $enabledel = $mform->addElement(
        'advcheckbox',
        'latepenalty_enabled',
        get_string('latepenalty_enabled', 'local_latepenalty')
    );
    $mform->setType('latepenalty_enabled', PARAM_INT);
    $mform->addHelpButton('latepenalty_enabled', 'latepenalty_enabled', 'local_latepenalty');

    $dailyel = $mform->addElement(
        'text',
        'latepenalty_daily',
        get_string('latepenalty_daily', 'local_latepenalty'),
        ['size' => 10]
    );
    $mform->setType('latepenalty_daily', PARAM_FLOAT);
    $mform->setDefault('latepenalty_daily', 0.00);
    $mform->hideIf('latepenalty_daily', 'latepenalty_enabled', 'notchecked');

    $maxel = $mform->addElement(
        'text',
        'latepenalty_max',
        get_string('latepenalty_max', 'local_latepenalty'),
        ['size' => 10]
    );
    $mform->setType('latepenalty_max', PARAM_FLOAT);
    $mform->setDefault('latepenalty_max', 0.00);
    $mform->hideIf('latepenalty_max', 'latepenalty_enabled', 'notchecked');

    $keepbestel = null;
    if (local_latepenalty_offers_keepbest($modname)) {
        $keepbestel = $mform->addElement(
            'advcheckbox',
            'latepenalty_keepbest',
            get_string('latepenalty_keepbest', 'local_latepenalty')
        );
        $mform->addHelpButton('latepenalty_keepbest', 'latepenalty_keepbest', 'local_latepenalty');
        $mform->setType('latepenalty_keepbest', PARAM_INT);
        $mform->setDefault('latepenalty_keepbest', 0);
        $mform->hideIf('latepenalty_keepbest', 'latepenalty_enabled', 'notchecked');
    }

    $recalcdeadlineel = $mform->addElement(
        'advcheckbox',
        'latepenalty_recalc_deadline',
        get_string('latepenalty_recalc_deadline', 'local_latepenalty')
    );
    $mform->setType('latepenalty_recalc_deadline', PARAM_INT);
    $mform->addHelpButton('latepenalty_recalc_deadline', 'latepenalty_recalc_deadline', 'local_latepenalty');
    $mform->setDefault('latepenalty_recalc_deadline', 1);
    $mform->hideIf('latepenalty_recalc_deadline', 'latepenalty_enabled', 'notchecked');

    $recalcrateel = $mform->addElement(
        'advcheckbox',
        'latepenalty_recalc_rate',
        get_string('latepenalty_recalc_rate', 'local_latepenalty')
    );
    $mform->setType('latepenalty_recalc_rate', PARAM_INT);
    $mform->addHelpButton('latepenalty_recalc_rate', 'latepenalty_recalc_rate', 'local_latepenalty');
    $mform->setDefault('latepenalty_recalc_rate', 1);
    $mform->hideIf('latepenalty_recalc_rate', 'latepenalty_enabled', 'notchecked');

    $elements = [
        'latepenaltyheader' => $headerel,
        'latepenalty_enabled' => $enabledel,
        'latepenalty_daily' => $dailyel,
        'latepenalty_max' => $maxel,
    ];
    if ($keepbestel) {
        $elements['latepenalty_keepbest'] = $keepbestel;
    }
    $elements += [
        'latepenalty_recalc_deadline' => $recalcdeadlineel,
        'latepenalty_recalc_rate' => $recalcrateel,
    ];
    $settings = array_slice(array_keys($elements), 1);

    // The core assignment penalty ("Grade penalties") and Late Penalty never act together.
    if ($mform->elementExists('gradepenalty')) {
        $elements['latepenalty_nativewarning'] = $mform->addElement(
            'static',
            'latepenalty_nativewarning',
            '',
            $OUTPUT->notification(get_string('warning_native_penalty', 'local_latepenalty'), 'warning', false)
        );
        $mform->hideIf('latepenalty_nativewarning', 'gradepenalty', 'neq', 1);
        foreach ($settings as $setting) {
            $mform->disabledIf($setting, 'gradepenalty', 'eq', 1);
        }
    }

    $cmid = (int) ($formwrapper->get_current()->coursemodule ?? 0);
    $existing = $cmid ? $DB->get_record('local_latepenalty_rules', ['cmid' => $cmid]) : false;

    // The plugin finds its own penalties in the grade history: say when the site limits it.
    $historywarning = \local_latepenalty\penalty_helper::grade_history_warning(false);
    if ($historywarning !== '') {
        $elements['latepenalty_historywarning'] = $mform->addElement(
            'static',
            'latepenalty_historywarning',
            '',
            $OUTPUT->notification($historywarning, 'warning', false)
        );
    }

    // Disabling an active rule gives the original grades back on save, unless there is no history to find them.
    if ($existing && $existing->enabled && empty($CFG->disablegradehistory)) {
        $elements['latepenalty_disablewarning'] = $mform->addElement(
            'static',
            'latepenalty_disablewarning',
            '',
            $OUTPUT->notification(get_string('warning_disable_restores', 'local_latepenalty'), 'warning', false)
        );
        $mform->hideIf('latepenalty_disablewarning', 'latepenalty_enabled', 'checked');
    }

    // Only numeric grades are discounted: warn when the saved activity has none.
    $nonumbers = $cmid && local_latepenalty_without_numeric_grade($cmid);
    if ($nonumbers) {
        $elements['latepenalty_scalewarning'] = $mform->addElement(
            'static',
            'latepenalty_scalewarning',
            '',
            $OUTPUT->notification(get_string('warning_scale_notsupported', 'local_latepenalty'), 'info', false)
        );
    }

    // The saved deadline the rule uses, unless a warning above already says the plugin does not act.
    $cm = $cmid ? get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING) : false;
    if ($cm && !$nonumbers && !\local_latepenalty\penalty_helper::native_penalty_active($cm)) {
        $deadline = \local_latepenalty\local\deadline_resolver::activity_deadline($cm);
        $text = $deadline->exists()
            ? get_string('deadline_used', 'local_latepenalty', (object) [
                'date' => \local_latepenalty\penalty_helper::format_deadline($deadline->time),
                'origin' => \local_latepenalty\penalty_helper::deadline_origin_label($deadline),
            ])
            : get_string('deadline_none', 'local_latepenalty');
        $elements['latepenalty_deadlineused'] = $mform->addElement('static', 'latepenalty_deadlineused', '', $text);
        $mform->addHelpButton('latepenalty_deadlineused', 'deadline_used', 'local_latepenalty');
        $mform->hideIf('latepenalty_deadlineused', 'latepenalty_enabled', 'notchecked');
    }

    // Move the section to appear right after the completion section.
    // Elements are added to the end by the callback; reorder them before
    // the first anchor found (tags or competencies follow completion).
    $anchors = ['tagshdr', 'competencieshdr'];
    foreach ($anchors as $anchor) {
        if ($mform->elementExists($anchor)) {
            // The element goes to insertElementBefore() by reference: pass each array slot, never a
            // reused loop variable, or every inserted element would end up being the last one.
            foreach (array_keys($elements) as $name) {
                $mform->removeElement($name);
                $mform->insertElementBefore($elements[$name], $anchor);
            }
            break;
        }
    }

    // Load existing values if editing.
    if ($existing) {
        $mform->setDefault('latepenalty_enabled', $existing->enabled);
        $mform->setDefault('latepenalty_daily', $existing->daily_penalty);
        $mform->setDefault('latepenalty_max', $existing->max_penalty);
        $mform->setDefault('latepenalty_recalc_deadline', $existing->recalc_on_deadline ?? 1);
        $mform->setDefault('latepenalty_recalc_rate', $existing->recalc_on_rate ?? 1);
        if ($keepbestel) {
            $mform->setDefault('latepenalty_keepbest', $existing->keepbest ?? 0);
        }
    }
}

/**
 * Whether the form offers "Do not let a new late attempt lower the grade" for a module.
 *
 * Only modules whose grading method cannot be read get the choice: the
 * external tool and plugins outside the core. Core modules already keep the
 * best penalised grade by their own method (quiz, lesson, SCORM, H5P, rated
 * forum/glossary/database) or have a single grade (assignment, workshop,
 * BigBlueButton).
 *
 * @param string $modname Module name.
 * @return bool
 */
function local_latepenalty_offers_keepbest(string $modname): bool {
    return $modname === 'lti' || !in_array($modname, core_plugin_manager::standard_plugins_list('mod'), true);
}

/**
 * Whether a saved activity has no numeric grade to discount (scale, "none" or no grade item at all).
 *
 * @param int $cmid Course module ID.
 * @return bool
 */
function local_latepenalty_without_numeric_grade(int $cmid): bool {
    $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
    if (!$cm) {
        return false;
    }
    return empty(\local_latepenalty\penalty_helper::get_penalisable_items($cm));
}

/**
 * Validate late penalty configuration fields.
 *
 * @param stdClass|array $data Form data object or array.
 * @param array $files Array of uploaded files.
 * @return array Array of errors (empty if validation passes).
 */
function local_latepenalty_coursemodule_validation($data, $files): array {
    $errors = [];

    // Convert object to array if needed.
    if (is_object($data)) {
        $data = (array) $data;
    }

    if (!empty($data['latepenalty_enabled'])) {
        // Validate daily penalty range.
        if (isset($data['latepenalty_daily'])) {
            $daily = (float) $data['latepenalty_daily'];
            if ($daily < 0 || $daily > 100) {
                $errors['latepenalty_daily'] = get_string('error_daily_range', 'local_latepenalty');
            }
        }

        // Validate maximum penalty range.
        if (isset($data['latepenalty_max'])) {
            $max = (float) $data['latepenalty_max'];
            if ($max < 0 || $max > 100) {
                $errors['latepenalty_max'] = get_string('error_max_range', 'local_latepenalty');
            }
        }

        // Validate that daily penalty does not exceed maximum.
        if (isset($data['latepenalty_daily']) && isset($data['latepenalty_max'])) {
            $daily = (float) $data['latepenalty_daily'];
            $max = (float) $data['latepenalty_max'];
            if ($daily > $max) {
                $errors['latepenalty_max'] = get_string('error_max_less_than_daily', 'local_latepenalty');
            }
        }
    }

    return $errors;
}

/**
 * Add a Late Penalty Overrides link to the activity settings navigation.
 *
 * Appears in the gear/settings menu of any activity whose penalty rule is
 * enabled, visible only to users who hold manageoverrides in that context.
 *
 * @param settings_navigation $settingsnav The settings navigation tree.
 * @param context             $context     Current page context.
 * @return void
 */
function local_latepenalty_extend_settings_navigation(
    settings_navigation $settingsnav,
    context $context
): void {
    global $DB;

    if (!$context instanceof context_module) {
        return;
    }

    if (!has_capability('local/latepenalty:manageoverrides', $context)) {
        return;
    }

    $cmid = $context->instanceid;
    if (!$DB->record_exists('local_latepenalty_rules', ['cmid' => $cmid, 'enabled' => 1])) {
        return;
    }

    $node = $settingsnav->find('modulesettings', navigation_node::TYPE_SETTING);
    if (!$node) {
        return;
    }

    $url = new moodle_url('/local/latepenalty/overrides.php', ['cmid' => $cmid]);
    $node->add(
        get_string('overrides', 'local_latepenalty'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'local_latepenalty_overrides',
        new pix_icon('i/override', '')
    );
}

/**
 * Extend the course navigation to add the late penalty report link.
 *
 * The link appears in the course secondary navigation and is visible only to
 * users who hold the local/latepenalty:viewreport capability in the course.
 *
 * @param navigation_node $navigation The course navigation node.
 * @param stdClass        $course     The current course.
 * @param context_course  $context    The course context.
 * @return void
 */
function local_latepenalty_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context
): void {
    if (!has_capability('local/latepenalty:viewreport', $context)) {
        return;
    }

    $url = new moodle_url('/local/latepenalty/report.php', ['courseid' => $course->id]);

    $navigation->add(
        get_string('report', 'local_latepenalty'),
        $url,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_latepenalty_report',
        new pix_icon('i/report', '')
    );
}

/**
 * Save late penalty configuration after course module is created or updated.
 *
 * @param stdClass $data Form data object.
 * @param stdClass $course Course object.
 * @return stdClass The modified data object.
 */
function local_latepenalty_coursemodule_edit_post_actions(stdClass $data, stdClass $course): stdClass {
    global $DB;

    if (!isset($data->coursemodule) || empty($data->coursemodule)) {
        return $data;
    }

    $cmid = (int) $data->coursemodule;

    $record = new stdClass();
    $record->cmid = $cmid;
    $record->enabled = !empty($data->latepenalty_enabled) ? 1 : 0;
    $record->daily_penalty = isset($data->latepenalty_daily) ? (float) $data->latepenalty_daily : 0.00;
    $record->max_penalty = isset($data->latepenalty_max) ? (float) $data->latepenalty_max : 0.00;
    $record->recalc_on_deadline = !empty($data->latepenalty_recalc_deadline) ? 1 : 0;
    $record->recalc_on_rate = !empty($data->latepenalty_recalc_rate) ? 1 : 0;
    $record->keepbest = !empty($data->latepenalty_keepbest) ? 1 : 0;

    $existing = $DB->get_record('local_latepenalty_rules', ['cmid' => $cmid]);

    // Resolve the current (post-save) deadline from the module.
    $cm          = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
    $newdeadline = \local_latepenalty\local\deadline_resolver::activity_deadline($cm)->time;

    // With the core assignment penalty on, the section is disabled and its fields are not
    // submitted: keep the rule as it was and leave the grades it already discounted alone.
    if ($existing && \local_latepenalty\penalty_helper::native_penalty_active($cm)) {
        return $data;
    }

    // Store the new rule first: the recalculations below read it (keep-best option).
    $record->last_deadline = $newdeadline;
    if ($existing) {
        $record->id = $existing->id;
        $DB->update_record('local_latepenalty_rules', $record);
    } else {
        $DB->insert_record('local_latepenalty_rules', $record);
    }

    if ($existing && $existing->enabled && !$record->enabled) {
        // Disabling the rule gives back the original grades.
        \local_latepenalty\recalculator::restore($cmid);
    } else if ($existing && !$existing->enabled && $record->enabled) {
        // Enabling again re-applies the rule to every graded student, including grades given while it
        // was off. Enabling for the first time (the plugin never discounted anything here) leaves the
        // grades that already exist as they are; only grades arriving afterwards are penalised.
        if (\local_latepenalty\recalculator::has_penalised($cmid)) {
            \local_latepenalty\recalculator::recalculate_all($cmid, $record->daily_penalty, $record->max_penalty);
        }
    } else if ($existing && $record->enabled) {
        // A removed deadline (0) counts as a change: it gives back the grades of students left without one.
        $deadlinechanged = (int) $existing->last_deadline !== $newdeadline;
        // Changing the keep-best option recalculates like a rate change.
        $ratechanged = (
            abs((float) $existing->daily_penalty - $record->daily_penalty) > 0.001 ||
            abs((float) $existing->max_penalty - $record->max_penalty) > 0.001 ||
            (int) ($existing->keepbest ?? 0) !== $record->keepbest
        );

        $shouldrecalc = (
            ($deadlinechanged && $record->recalc_on_deadline) ||
            ($ratechanged     && $record->recalc_on_rate)
        );

        if ($shouldrecalc) {
            // Without the deadline box ticked, a rate change keeps the deadline already applied.
            $deadline = ($deadlinechanged && !$record->recalc_on_deadline) ? (int) $existing->last_deadline : $newdeadline;
            \local_latepenalty\recalculator::recalculate($cmid, $deadline, $record->daily_penalty, $record->max_penalty);
        }
    }

    return $data;
}
