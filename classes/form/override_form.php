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
 * Form for adding or editing a per-user late penalty override.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Moodle form for a per-user penalty override.
 *
 * Custom data keys:
 *  - cmid           int     Course module ID.
 *  - overrideid     int     0 for add, existing ID for edit.
 *  - studentoptions array   [userid => fullname] for the student select (add only).
 *  - studentname    string  Display name for the student (edit only).
 *  - userid         int     User ID (edit only).
 *  - rule           stdClass Activity rule record (for placeholder hints).
 */
class override_form extends \moodleform {
    /**
     * Define the form fields.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $data  = $this->_customdata;

        $mform->addElement('hidden', 'cmid', (int) $data['cmid']);
        $mform->setType('cmid', PARAM_INT);

        $mform->addElement('hidden', 'overrideid', (int) ($data['overrideid'] ?? 0));
        $mform->setType('overrideid', PARAM_INT);

        if (empty($data['overrideid'])) {
            $mform->addElement(
                'select',
                'userid',
                get_string('override_student', 'local_latepenalty'),
                $data['studentoptions'] ?? []
            );
            $mform->addRule('userid', null, 'required', null, 'client');
            $mform->setType('userid', PARAM_INT);
        } else {
            $mform->addElement(
                'static',
                'studentname',
                get_string('override_student', 'local_latepenalty'),
                $data['studentname'] ?? ''
            );
            $mform->addElement('hidden', 'userid', (int) ($data['userid'] ?? 0));
            $mform->setType('userid', PARAM_INT);
        }

        $mform->addElement(
            'date_time_selector',
            'deadline',
            get_string('override_deadline', 'local_latepenalty'),
            ['optional' => true]
        );
        $mform->setType('deadline', PARAM_INT);

        $rule             = $data['rule'] ?? null;
        $dailyplaceholder = $rule ? (string) $rule->daily_penalty : '';
        $maxplaceholder   = $rule ? (string) $rule->max_penalty : '';
        $enablelabel      = get_string('enable', 'moodle');

        // Daily penalty: checkbox + text, mirroring the optional date_time_selector pattern.
        $dailygroup   = [];
        $dailygroup[] = $mform->createElement('advcheckbox', 'enable', '', $enablelabel);
        $dailygroup[] = $mform->createElement('text', 'value', '', ['size' => 10, 'placeholder' => $dailyplaceholder]);
        $mform->addGroup($dailygroup, 'daily_grp', get_string('override_daily', 'local_latepenalty'), ' ');
        $mform->disabledIf('daily_grp[value]', 'daily_grp[enable]', 'notchecked');
        $mform->setType('daily_grp[value]', PARAM_RAW);

        // Max penalty: checkbox + text, same pattern.
        $maxgroup   = [];
        $maxgroup[] = $mform->createElement('advcheckbox', 'enable', '', $enablelabel);
        $maxgroup[] = $mform->createElement('text', 'value', '', ['size' => 10, 'placeholder' => $maxplaceholder]);
        $mform->addGroup($maxgroup, 'max_grp', get_string('override_max', 'local_latepenalty'), ' ');
        $mform->disabledIf('max_grp[value]', 'max_grp[enable]', 'notchecked');
        $mform->setType('max_grp[value]', PARAM_RAW);

        $mform->addElement(
            'static',
            'override_hint',
            '',
            get_string('override_hint', 'local_latepenalty')
        );

        $this->add_action_buttons();
    }

    /**
     * Validate the form data.
     *
     * @param array $data  Submitted form data.
     * @param array $files Uploaded files.
     * @return array Validation errors keyed by field name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $dailygrp    = $data['daily_grp'] ?? [];
        $maxgrp      = $data['max_grp'] ?? [];
        $enabledaily = !empty($dailygrp['enable']);
        $enablemax = !empty($maxgrp['enable']);
        $daily = $enabledaily ? trim((string) ($dailygrp['value'] ?? '')) : '';
        $max = $enablemax ? trim((string) ($maxgrp['value'] ?? '')) : '';

        if ($enabledaily && $daily === '') {
            $errors['daily_grp'] = get_string('required');
        } else if ($daily !== '' && (!is_numeric($daily) || (float) $daily < 0 || (float) $daily > 100)) {
            $errors['daily_grp'] = get_string('error_daily_range', 'local_latepenalty');
        }

        if ($enablemax && $max === '') {
            $errors['max_grp'] = get_string('required');
        } else if ($max !== '' && (!is_numeric($max) || (float) $max < 0 || (float) $max > 100)) {
            $errors['max_grp'] = get_string('error_max_range', 'local_latepenalty');
        }

        if ($daily !== '' && $max !== '' && is_numeric($daily) && is_numeric($max)) {
            if ((float) $daily > (float) $max) {
                $errors['max_grp'] = get_string('error_max_less_than_daily', 'local_latepenalty');
            }
        }

        return $errors;
    }
}
