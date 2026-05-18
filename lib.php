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
    global $DB;

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

    $recalcdeadlineel = $mform->addElement(
        'advcheckbox',
        'latepenalty_recalc_deadline',
        get_string('latepenalty_recalc_deadline', 'local_latepenalty')
    );
    $mform->setType('latepenalty_recalc_deadline', PARAM_INT);
    $mform->setDefault('latepenalty_recalc_deadline', 1);
    $mform->hideIf('latepenalty_recalc_deadline', 'latepenalty_enabled', 'notchecked');

    $recalcrateel = $mform->addElement(
        'advcheckbox',
        'latepenalty_recalc_rate',
        get_string('latepenalty_recalc_rate', 'local_latepenalty')
    );
    $mform->setType('latepenalty_recalc_rate', PARAM_INT);
    $mform->setDefault('latepenalty_recalc_rate', 1);
    $mform->hideIf('latepenalty_recalc_rate', 'latepenalty_enabled', 'notchecked');

    // Move the section to appear right after the completion section.
    // Elements are added to the end by the callback; reorder them before
    // the first anchor found (tags or competencies follow completion).
    $anchors = ['tagshdr', 'competencieshdr'];
    foreach ($anchors as $anchor) {
        if ($mform->elementExists($anchor)) {
            $mform->removeElement('latepenaltyheader');
            $mform->removeElement('latepenalty_enabled');
            $mform->removeElement('latepenalty_daily');
            $mform->removeElement('latepenalty_max');
            $mform->removeElement('latepenalty_recalc_deadline');
            $mform->removeElement('latepenalty_recalc_rate');
            $mform->insertElementBefore($headerel, $anchor);
            $mform->insertElementBefore($enabledel, $anchor);
            $mform->insertElementBefore($dailyel, $anchor);
            $mform->insertElementBefore($maxel, $anchor);
            $mform->insertElementBefore($recalcdeadlineel, $anchor);
            $mform->insertElementBefore($recalcrateel, $anchor);
            break;
        }
    }

    // Load existing values if editing.
    if (!empty($formwrapper->get_current()->coursemodule)) {
        $cmid = $formwrapper->get_current()->coursemodule;
        $existing = $DB->get_record('local_latepenalty_rules', ['cmid' => $cmid]);

        if ($existing) {
            $mform->setDefault('latepenalty_enabled', $existing->enabled);
            $mform->setDefault('latepenalty_daily', $existing->daily_penalty);
            $mform->setDefault('latepenalty_max', $existing->max_penalty);
            $mform->setDefault('latepenalty_recalc_deadline', $existing->recalc_on_deadline ?? 1);
            $mform->setDefault('latepenalty_recalc_rate', $existing->recalc_on_rate ?? 1);
        }
    }
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

    $existing = $DB->get_record('local_latepenalty_rules', ['cmid' => $cmid]);

    // Resolve the current (post-save) deadline from the module.
    $cm          = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
    $newdeadline = \local_latepenalty\penalty_helper::get_deadline($cm) ?? 0;

    if ($existing && $record->enabled && $existing->enabled) {
        $deadlinechanged = $newdeadline && (int) $existing->last_deadline !== $newdeadline;
        $ratechanged = (
            abs((float) $existing->daily_penalty - $record->daily_penalty) > 0.001 ||
            abs((float) $existing->max_penalty - $record->max_penalty) > 0.001
        );

        $shouldrecalc = (
            ($deadlinechanged && $record->recalc_on_deadline) ||
            ($ratechanged     && $record->recalc_on_rate)
        );

        if ($shouldrecalc) {
            \local_latepenalty\recalculator::recalculate(
                $cmid,
                $newdeadline ?: (int) $existing->last_deadline,
                $record->daily_penalty,
                $record->max_penalty
            );
        }
    }

    $record->last_deadline = $newdeadline;

    if ($existing) {
        $record->id = $existing->id;
        $DB->update_record('local_latepenalty_rules', $record);
    } else {
        $DB->insert_record('local_latepenalty_rules', $record);
    }

    return $data;
}
