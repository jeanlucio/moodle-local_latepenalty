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
 * Controller for the per-group override management page.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty\group_override;

use context_module;
use core\output\notification;
use html_writer;
use local_latepenalty\form\group_override_form;
use local_latepenalty\recalculator;
use moodle_url;
use renderer_base;
use stdClass;

/**
 * Handles all actions for the group override management page (list, add, edit, delete).
 *
 * @package local_latepenalty\group_override
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

    /** @var moodle_url URL of the group override list page. */
    private moodle_url $listurl;

    /** @var group_override_form|null Prepared form instance. */
    private ?group_override_form $form = null;

    /** @var stdClass|null Override record being edited. */
    private ?stdClass $editingoverride = null;

    /** @var array Group options for the add form select (groupid => name). */
    private array $groupoptions = [];

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
        $this->listurl    = new moodle_url('/local/latepenalty/overrides.php', ['cmid' => $cmid, 'mode' => 'group']);
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

        $overriderow = $DB->get_record(
            'local_latepenalty_group_overrides',
            ['id' => $this->overrideid, 'cmid' => $this->cmid],
            'groupid'
        );

        $DB->delete_records(
            'local_latepenalty_group_overrides',
            ['id' => $this->overrideid, 'cmid' => $this->cmid]
        );

        if ($overriderow) {
            recalculator::recalculate_for_group(
                $this->cmid,
                (int) $overriderow->groupid,
                (float) $this->rule->daily_penalty,
                (float) $this->rule->max_penalty
            );
        }

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
     * Instantiate the group override form with the correct options for the current action.
     *
     * @return void
     */
    private function prepare_form(): void {
        global $DB;

        $groupoptions = [];
        $groupname    = '';
        $existinggroupid = 0;

        if ($this->action === 'edit' && $this->overrideid) {
            $this->editingoverride = $DB->get_record(
                'local_latepenalty_group_overrides',
                ['id' => $this->overrideid, 'cmid' => $this->cmid],
                '*',
                MUST_EXIST
            );
            $group           = $DB->get_record('groups', ['id' => $this->editingoverride->groupid], '*', MUST_EXIST);
            $groupname       = format_string($group->name, true, ['context' => $this->modcontext]);
            $existinggroupid = (int) $this->editingoverride->groupid;
        } else {
            $groupoptions = $this->build_group_options();
        }

        $this->groupoptions = $groupoptions;

        $formurl = new moodle_url(
            '/local/latepenalty/overrides.php',
            ['cmid' => $this->cmid, 'mode' => 'group', 'action' => $this->action, 'overrideid' => $this->overrideid]
        );

        $this->form = new group_override_form($formurl, [
            'cmid'         => $this->cmid,
            'overrideid'   => $this->overrideid,
            'groupoptions' => $groupoptions,
            'groupname'    => $groupname,
            'groupid'      => $existinggroupid,
            'rule'         => $this->rule,
        ]);
    }

    /**
     * Build the group select options, excluding groups that already have an override.
     *
     * @return array Associative array of groupid => group name.
     */
    private function build_group_options(): array {
        global $DB;

        $allgroups = groups_get_all_groups($this->course->id);

        $existinggroupids = array_map(
            'intval',
            array_column(
                $DB->get_records('local_latepenalty_group_overrides', ['cmid' => $this->cmid], '', 'groupid'),
                'groupid'
            )
        );

        $options = [];
        foreach ($allgroups as $group) {
            if (!in_array((int) $group->id, $existinggroupids)) {
                $options[$group->id] = format_string($group->name, true, ['context' => $this->modcontext]);
            }
        }
        return $options;
    }

    /**
     * Persist a group override record (insert or update).
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
        $record->groupid       = $this->resolve_override_groupid($formdata);
        $record->deadline      = empty($formdata->deadline) ? null : (int) $formdata->deadline;
        $record->daily_penalty = ($dailyraw === '') ? null : (float) $dailyraw;
        $record->max_penalty   = ($maxraw === '') ? null : (float) $maxraw;
        $record->timemodified  = time();

        if ($this->overrideid) {
            $record->id = $this->overrideid;
            $DB->update_record('local_latepenalty_group_overrides', $record);
        } else {
            $record->timecreated = time();
            $DB->insert_record('local_latepenalty_group_overrides', $record);
        }

        recalculator::recalculate_for_group(
            $this->cmid,
            (int) $record->groupid,
            (float) $this->rule->daily_penalty,
            (float) $this->rule->max_penalty
        );
    }

    /**
     * Resolve and validate the group ID that an override may affect.
     *
     * @param stdClass $formdata Validated form data.
     * @return int Group ID for this override.
     */
    private function resolve_override_groupid(stdClass $formdata): int {
        global $DB;

        if ($this->overrideid) {
            $override = $DB->get_record(
                'local_latepenalty_group_overrides',
                ['id' => $this->overrideid, 'cmid' => $this->cmid],
                'id, groupid',
                MUST_EXIST
            );
            return (int) $override->groupid;
        }

        $groupid = (int) ($formdata->groupid ?? 0);
        if (!$groupid || !$DB->record_exists('groups', ['id' => $groupid, 'courseid' => $this->course->id])) {
            throw new \moodle_exception('invalidrecord');
        }

        if ($DB->record_exists('local_latepenalty_group_overrides', ['cmid' => $this->cmid, 'groupid' => $groupid])) {
            throw new \moodle_exception('group_override_error_duplicate', 'local_latepenalty');
        }

        return $groupid;
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
            'local_latepenalty_group_overrides',
            ['id' => $this->overrideid, 'cmid' => $this->cmid],
            '*',
            MUST_EXIST
        );
        $group = $DB->get_record('groups', ['id' => $override->groupid], '*', MUST_EXIST);

        return $output->confirm(
            get_string(
                'group_override_confirm_delete',
                'local_latepenalty',
                format_string($group->name, true, ['context' => $this->modcontext])
            ),
            new moodle_url('/local/latepenalty/overrides.php', [
                'cmid'       => $this->cmid,
                'mode'       => 'group',
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
     * @return string HTML content (form or "no groups" notice).
     */
    private function render_form(renderer_base $output): string {
        if (!$this->form) {
            $this->prepare_form();
        }

        if ($this->action === 'add' && empty($this->groupoptions)) {
            return $output->notification(get_string('group_override_no_groups', 'local_latepenalty'), 'info')
                . html_writer::link($this->listurl, get_string('back'));
        }

        if ($this->editingoverride) {
            $this->form->set_data((object) [
                'cmid'       => $this->cmid,
                'overrideid' => $this->overrideid,
                'groupid'    => $this->editingoverride->groupid,
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
     * Render the group override list page.
     *
     * @param renderer_base $output Page renderer.
     * @return string HTML content (table or empty notice, plus add button).
     */
    private function render_list(renderer_base $output): string {
        global $DB;

        $overrides = $DB->get_records(
            'local_latepenalty_group_overrides',
            ['cmid' => $this->cmid],
            'groupid ASC'
        );
        $addurl    = new moodle_url(
            '/local/latepenalty/overrides.php',
            ['cmid' => $this->cmid, 'mode' => 'group', 'action' => 'add']
        );
        $addbutton = $output->single_button($addurl, get_string('group_override_add', 'local_latepenalty'), 'get');

        if (empty($overrides)) {
            return $output->notification(get_string('group_override_empty', 'local_latepenalty'), 'info')
                . $addbutton;
        }

        $context = $this->build_list_context($overrides);
        return $output->render_from_template('local_latepenalty/group_overrides_list', $context)
            . $addbutton;
    }

    /**
     * Build the template context array for the group override list.
     *
     * @param array $overrides Indexed array of group override records from the DB.
     * @return array Template context.
     */
    private function build_list_context(array $overrides): array {
        global $DB;

        $groupids   = array_map('intval', array_column($overrides, 'groupid'));
        [$insql, $inparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'grp');
        $groups     = $DB->get_records_sql("SELECT id, name FROM {groups} WHERE id $insql", $inparams);

        $dateformat = get_string('strftimedatefullshort', 'langconfig');
        $inherit    = get_string('override_inherit', 'local_latepenalty');

        $rows = [];
        foreach ($overrides as $override) {
            $group  = $groups[$override->groupid] ?? null;
            $rows[] = [
                'groupname'     => $group
                    ? format_string($group->name, true, ['context' => $this->modcontext])
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
                    ['cmid' => $this->cmid, 'mode' => 'group', 'action' => 'edit', 'overrideid' => $override->id]
                ))->out(false),
                'deleteurl'     => (new moodle_url(
                    '/local/latepenalty/overrides.php',
                    ['cmid' => $this->cmid, 'mode' => 'group', 'action' => 'delete', 'overrideid' => $override->id]
                ))->out(false),
            ];
        }

        return [
            'rows'    => $rows,
            'hasrows' => !empty($rows),
        ];
    }
}
