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
 * Controller for the per-user override management page.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty\override;

use context_course;
use context_module;
use core\output\notification;
use html_writer;
use local_latepenalty\form\override_form;
use moodle_url;
use renderer_base;
use stdClass;

/**
 * Handles all actions for the override management page (list, add, edit, delete).
 *
 * @package local_latepenalty\override
 */
class controller {
    /** @var int Course module ID. */
    private int $cmid;

    /** @var stdClass Course record. */
    private stdClass $course;

    /** @var stdClass Course module record. */
    private stdClass $cm;

    /** @var context_module Module context. */
    private context_module $modcontext;

    /** @var stdClass Active penalty rule for this activity. */
    private stdClass $rule;

    /** @var string Current action: list, add, edit, or delete. */
    private string $action;

    /** @var int Override ID being edited or deleted (0 = none). */
    private int $overrideid;

    /** @var bool Whether the delete confirmation was submitted. */
    private bool $confirm;

    /** @var moodle_url URL of the override list page. */
    private moodle_url $listurl;

    /** @var override_form|null Prepared form instance. */
    private ?override_form $form = null;

    /** @var stdClass|null Override record being edited. */
    private ?stdClass $editingoverride = null;

    /** @var array Student options for the add form select (userid => fullname). */
    private array $studentoptions = [];

    /**
     * Constructor.
     *
     * @param int            $cmid       Course module ID.
     * @param stdClass       $course     Course record.
     * @param stdClass       $cm         Course module record.
     * @param context_module $modcontext Module context.
     * @param stdClass       $rule       Active penalty rule record.
     * @param string         $action     Requested action (list, add, edit, delete).
     * @param int            $overrideid Override ID (0 = none).
     * @param bool           $confirm    Whether delete confirmation was posted.
     */
    public function __construct(
        int $cmid,
        stdClass $course,
        stdClass $cm,
        context_module $modcontext,
        stdClass $rule,
        string $action,
        int $overrideid,
        bool $confirm
    ) {
        $this->cmid       = $cmid;
        $this->course     = $course;
        $this->cm         = $cm;
        $this->modcontext = $modcontext;
        $this->rule       = $rule;
        $this->action     = $action;
        $this->overrideid = $overrideid;
        $this->confirm    = $confirm;
        $this->listurl    = new moodle_url('/local/latepenalty/overrides.php', ['cmid' => $cmid]);
    }

    /**
     * Process the current action, executing any redirects before output begins.
     *
     * Must be called before $OUTPUT->header().
     *
     * @return void
     */
    public function process(): void {
        if ($this->action === 'delete') {
            $this->process_delete();
        } else if ($this->action === 'add' || $this->action === 'edit') {
            $this->process_form();
        }
    }

    /**
     * Render the page body and return the HTML string.
     *
     * Must be called after $OUTPUT->header().
     *
     * @param renderer_base $output Page renderer.
     * @return string HTML content for the page body.
     */
    public function render(renderer_base $output): string {
        if ($this->action === 'delete') {
            return $this->render_delete_confirm($output);
        }
        if ($this->action === 'add' || $this->action === 'edit') {
            return $this->render_form($output);
        }
        return $this->render_list($output);
    }

    /**
     * Process a delete confirmation POST and redirect on success.
     *
     * @return void
     */
    private function process_delete(): void {
        global $DB;

        if (!$this->overrideid || !$this->confirm || !data_submitted()) {
            return;
        }

        require_sesskey();
        $DB->delete_records(
            'local_latepenalty_overrides',
            ['id' => $this->overrideid, 'cmid' => $this->cmid]
        );
        redirect(
            $this->listurl,
            get_string('override_deleted', 'local_latepenalty'),
            null,
            notification::NOTIFY_SUCCESS
        );
    }

    /**
     * Build the form, handle submission, and redirect on success.
     *
     * @return void
     */
    private function process_form(): void {
        $this->prepare_form();

        if ($this->form->is_cancelled()) {
            redirect($this->listurl);
        }

        if ($formdata = $this->form->get_data()) {
            $this->save_override($formdata);
            redirect(
                $this->listurl,
                get_string('override_saved', 'local_latepenalty'),
                null,
                notification::NOTIFY_SUCCESS
            );
        }
    }

    /**
     * Instantiate the override form with the correct options for the current action.
     *
     * @return void
     */
    private function prepare_form(): void {
        global $DB;

        $studentoptions = [];
        $studentname    = '';
        $existinguserid = 0;

        if ($this->action === 'edit' && $this->overrideid) {
            $this->editingoverride = $DB->get_record(
                'local_latepenalty_overrides',
                ['id' => $this->overrideid, 'cmid' => $this->cmid],
                '*',
                MUST_EXIST
            );
            $user           = $DB->get_record('user', ['id' => $this->editingoverride->userid], '*', MUST_EXIST);
            $studentname    = fullname($user);
            $existinguserid = (int) $this->editingoverride->userid;
        } else {
            $studentoptions = $this->build_student_options();
        }

        $this->studentoptions = $studentoptions;

        $formurl = new moodle_url(
            '/local/latepenalty/overrides.php',
            ['cmid' => $this->cmid, 'action' => $this->action, 'overrideid' => $this->overrideid]
        );

        $this->form = new override_form($formurl, [
            'cmid'           => $this->cmid,
            'overrideid'     => $this->overrideid,
            'studentoptions' => $studentoptions,
            'studentname'    => $studentname,
            'userid'         => $existinguserid,
            'rule'           => $this->rule,
        ]);
    }

    /**
     * Build the enrolled-students select options, excluding students who already have an override.
     *
     * @return array Associative array of userid => fullname.
     */
    private function build_student_options(): array {
        global $DB;

        $coursecontext = context_course::instance($this->course->id);
        $namefields    = 'u.id, ' . implode(', ', array_map(
            static fn(string $f): string => "u.$f",
            \core_user\fields::get_name_fields()
        ));
        $enrolled = get_enrolled_users(
            $coursecontext,
            '',
            0,
            $namefields,
            'u.lastname ASC, u.firstname ASC'
        );

        $existinguserids = array_map(
            'intval',
            array_column(
                $DB->get_records('local_latepenalty_overrides', ['cmid' => $this->cmid], '', 'userid'),
                'userid'
            )
        );

        $options = [];
        foreach ($enrolled as $enrolleduser) {
            if (!in_array((int) $enrolleduser->id, $existinguserids)) {
                $options[$enrolleduser->id] = fullname($enrolleduser);
            }
        }
        return $options;
    }

    /**
     * Persist an override record (insert or update).
     *
     * @param stdClass $formdata Validated form data.
     * @return void
     */
    private function save_override(stdClass $formdata): void {
        global $DB;

        $dailygrp = (array) ($formdata->daily_grp ?? []);
        $maxgrp   = (array) ($formdata->max_grp ?? []);
        $dailyraw = !empty($dailygrp['enable']) ? trim((string) ($dailygrp['value'] ?? '')) : '';
        $maxraw   = !empty($maxgrp['enable']) ? trim((string) ($maxgrp['value'] ?? '')) : '';

        $record                = new stdClass();
        $record->cmid          = $this->cmid;
        $record->userid        = $this->resolve_override_userid($formdata);
        $record->deadline      = empty($formdata->deadline) ? null : (int) $formdata->deadline;
        $record->daily_penalty = ($dailyraw === '') ? null : (float) $dailyraw;
        $record->max_penalty   = ($maxraw === '') ? null : (float) $maxraw;
        $record->timemodified  = time();

        if ($this->overrideid) {
            $record->id = $this->overrideid;
            $DB->update_record('local_latepenalty_overrides', $record);
        } else {
            $record->timecreated = time();
            $DB->insert_record('local_latepenalty_overrides', $record);
        }
    }

    /**
     * Resolve and validate the user ID that an override may affect.
     *
     * @param stdClass $formdata Validated form data.
     * @return int User ID that belongs to this override.
     */
    private function resolve_override_userid(stdClass $formdata): int {
        global $DB;

        if ($this->overrideid) {
            $override = $DB->get_record(
                'local_latepenalty_overrides',
                ['id' => $this->overrideid, 'cmid' => $this->cmid],
                'id, userid',
                MUST_EXIST
            );
            return (int) $override->userid;
        }

        $userid = (int) ($formdata->userid ?? 0);
        if (!$userid || !$this->is_user_enrolled($userid)) {
            throw new \moodle_exception('invaliduser');
        }

        if ($DB->record_exists('local_latepenalty_overrides', ['cmid' => $this->cmid, 'userid' => $userid])) {
            throw new \moodle_exception('override_error_duplicate', 'local_latepenalty');
        }

        return $userid;
    }

    /**
     * Check whether a user is actively enrolled in this controller's course.
     *
     * @param int $userid User ID to check.
     * @return bool True when the user is enrolled.
     */
    private function is_user_enrolled(int $userid): bool {
        $coursecontext = context_course::instance($this->course->id);
        return is_enrolled($coursecontext, $userid, '', true);
    }

    /**
     * Render the delete confirmation page.
     *
     * @param renderer_base $output Page renderer.
     * @return string HTML confirmation widget.
     */
    private function render_delete_confirm(renderer_base $output): string {
        global $DB;

        if (!$this->overrideid) {
            return '';
        }

        $override = $DB->get_record(
            'local_latepenalty_overrides',
            ['id' => $this->overrideid, 'cmid' => $this->cmid],
            '*',
            MUST_EXIST
        );
        $user = $DB->get_record('user', ['id' => $override->userid], '*', MUST_EXIST);

        return $output->confirm(
            get_string('override_confirm_delete', 'local_latepenalty', fullname($user)),
            new moodle_url('/local/latepenalty/overrides.php', [
                'cmid'       => $this->cmid,
                'action'     => 'delete',
                'overrideid' => $this->overrideid,
                'confirm'    => 1,
            ]),
            $this->listurl
        );
    }

    /**
     * Render the add or edit form.
     *
     * @param renderer_base $output Page renderer.
     * @return string HTML content (form or "no students" notice).
     */
    private function render_form(renderer_base $output): string {
        if (!$this->form) {
            $this->prepare_form();
        }

        if ($this->action === 'add' && empty($this->studentoptions)) {
            return $output->notification(get_string('override_no_students', 'local_latepenalty'), 'info')
                . html_writer::link($this->listurl, get_string('back'));
        }

        if ($this->editingoverride) {
            $this->form->set_data((object) [
                'cmid'       => $this->cmid,
                'overrideid' => $this->overrideid,
                'userid'     => $this->editingoverride->userid,
                'deadline'   => $this->editingoverride->deadline ?? 0,
                'daily_grp'  => [
                    'enable' => ($this->editingoverride->daily_penalty !== null) ? 1 : 0,
                    'value'  => ($this->editingoverride->daily_penalty !== null)
                        ? (string) $this->editingoverride->daily_penalty : '',
                ],
                'max_grp'    => [
                    'enable' => ($this->editingoverride->max_penalty !== null) ? 1 : 0,
                    'value'  => ($this->editingoverride->max_penalty !== null)
                        ? (string) $this->editingoverride->max_penalty : '',
                ],
            ]);
        }

        ob_start();
        $this->form->display();
        return (string) ob_get_clean();
    }

    /**
     * Render the override list page.
     *
     * @param renderer_base $output Page renderer.
     * @return string HTML content (table or empty notice, plus add button).
     */
    private function render_list(renderer_base $output): string {
        global $DB;

        $overrides = $DB->get_records('local_latepenalty_overrides', ['cmid' => $this->cmid], 'userid ASC');
        $addurl    = new moodle_url('/local/latepenalty/overrides.php', ['cmid' => $this->cmid, 'action' => 'add']);
        $addbutton = $output->single_button($addurl, get_string('override_add', 'local_latepenalty'), 'get');

        if (empty($overrides)) {
            return $output->notification(get_string('override_empty', 'local_latepenalty'), 'info')
                . $addbutton;
        }

        $context = $this->build_list_context($overrides);
        return $output->render_from_template('local_latepenalty/overrides_list', $context)
            . $addbutton;
    }

    /**
     * Build the template context array for the override list.
     *
     * @param array $overrides Indexed array of override records from the DB.
     * @return array Template context.
     */
    private function build_list_context(array $overrides): array {
        global $DB;

        $userids    = array_map('intval', array_column($overrides, 'userid'));
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        $namefields = implode(', ', \core_user\fields::get_name_fields());
        $users      = $DB->get_records_sql(
            "SELECT id, $namefields FROM {user} WHERE id $insql",
            $inparams
        );

        $dateformat = get_string('strftimedatefullshort', 'langconfig');
        $inherit    = get_string('override_inherit', 'local_latepenalty');

        $rows = [];
        foreach ($overrides as $override) {
            $user   = $users[$override->userid] ?? null;
            $rows[] = [
                'fullname'      => $user
                    ? format_string(fullname($user), true, ['context' => $this->modcontext])
                    : '?',
                'deadline'      => ($override->deadline !== null)
                    ? userdate((int) $override->deadline, $dateformat)
                    : $inherit,
                'daily_penalty' => ($override->daily_penalty !== null)
                    ? (string) $override->daily_penalty . '%'
                    : $inherit,
                'max_penalty'   => ($override->max_penalty !== null)
                    ? (string) $override->max_penalty . '%'
                    : $inherit,
                'editurl'       => (new moodle_url(
                    '/local/latepenalty/overrides.php',
                    ['cmid' => $this->cmid, 'action' => 'edit', 'overrideid' => $override->id]
                ))->out(false),
                'deleteurl'     => (new moodle_url(
                    '/local/latepenalty/overrides.php',
                    ['cmid' => $this->cmid, 'action' => 'delete', 'overrideid' => $override->id]
                ))->out(false),
            ];
        }

        return [
            'rows'    => $rows,
            'hasrows' => !empty($rows),
        ];
    }
}
