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
 * Per-user late penalty override management page.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_latepenalty\form\override_form;

$cmid       = required_param('cmid', PARAM_INT);
$action     = optional_param('action', 'list', PARAM_ALPHA);
$overrideid = optional_param('overrideid', 0, PARAM_INT);
$confirm    = optional_param('confirm', 0, PARAM_BOOL);

$cm     = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);

require_login($course, true, $cm);

$modcontext = context_module::instance($cmid);
require_capability('local/latepenalty:manageoverrides', $modcontext);

$rule = $DB->get_record('local_latepenalty_rules', ['cmid' => $cmid, 'enabled' => 1], '*', MUST_EXIST);

$listurl = new moodle_url('/local/latepenalty/overrides.php', ['cmid' => $cmid]);

$PAGE->set_url($listurl);
$PAGE->set_context($modcontext);
$PAGE->set_pagelayout('standard');
$PAGE->set_heading($course->fullname);
$PAGE->set_title(get_string('overrides_for', 'local_latepenalty', format_string($cm->name)));

$PAGE->navbar->add(
    $cm->name,
    new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cmid])
);
$PAGE->navbar->add(get_string('overrides', 'local_latepenalty'));

// Delete action.

if ($action === 'delete' && $overrideid) {
    $override = $DB->get_record(
        'local_latepenalty_overrides',
        ['id' => $overrideid, 'cmid' => $cmid],
        '*',
        MUST_EXIST
    );

    if ($confirm && data_submitted()) {
        require_sesskey();
        $DB->delete_records('local_latepenalty_overrides', ['id' => $overrideid]);
        redirect(
            $listurl,
            get_string('override_deleted', 'local_latepenalty'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    $user = $DB->get_record('user', ['id' => $override->userid], '*', MUST_EXIST);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('overrides_for', 'local_latepenalty', format_string($cm->name)));
    echo $OUTPUT->confirm(
        get_string('override_confirm_delete', 'local_latepenalty', fullname($user)),
        new moodle_url('/local/latepenalty/overrides.php', [
            'cmid'       => $cmid,
            'action'     => 'delete',
            'overrideid' => $overrideid,
            'confirm'    => 1,
        ]),
        $listurl
    );
    echo $OUTPUT->footer();
    exit;
}

// Add / edit action.

if ($action === 'add' || $action === 'edit') {
    $editingoverride = null;
    if ($action === 'edit' && $overrideid) {
        $editingoverride = $DB->get_record(
            'local_latepenalty_overrides',
            ['id' => $overrideid, 'cmid' => $cmid],
            '*',
            MUST_EXIST
        );
    }

    // Build student options for add mode.
    $studentoptions = [];
    $studentname    = '';
    $existinguserid = 0;

    if ($action === 'add') {
        $coursecontext = context_course::instance($cm->course);
        $namefields = 'u.id, ' . implode(', ', array_map(
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
                $DB->get_records('local_latepenalty_overrides', ['cmid' => $cmid], '', 'userid'),
                'userid'
            )
        );

        foreach ($enrolled as $enrolleduser) {
            if (!in_array((int) $enrolleduser->id, $existinguserids)) {
                $studentoptions[$enrolleduser->id] = fullname($enrolleduser);
            }
        }
    } else {
        $user           = $DB->get_record('user', ['id' => $editingoverride->userid], '*', MUST_EXIST);
        $studentname    = fullname($user);
        $existinguserid = (int) $editingoverride->userid;
    }

    $formurl = new moodle_url(
        '/local/latepenalty/overrides.php',
        ['cmid' => $cmid, 'action' => $action, 'overrideid' => $overrideid]
    );

    $form = new override_form($formurl, [
        'cmid'           => $cmid,
        'overrideid'     => $overrideid,
        'studentoptions' => $studentoptions,
        'studentname'    => $studentname,
        'userid'         => $existinguserid,
        'rule'           => $rule,
    ]);

    if ($form->is_cancelled()) {
        redirect($listurl);
    }

    if ($formdata = $form->get_data()) {
        $dailyraw = trim((string) ($formdata->daily_penalty ?? ''));
        $maxraw   = trim((string) ($formdata->max_penalty ?? ''));

        $record               = new stdClass();
        $record->cmid         = $cmid;
        $record->userid       = (int) $formdata->userid;
        $record->deadline     = empty($formdata->deadline) ? null : (int) $formdata->deadline;
        $record->daily_penalty = ($dailyraw === '') ? null : (float) $dailyraw;
        $record->max_penalty  = ($maxraw === '') ? null : (float) $maxraw;
        $record->timemodified = time();

        if ($overrideid) {
            $record->id = $overrideid;
            $DB->update_record('local_latepenalty_overrides', $record);
        } else {
            $record->timecreated = time();
            $DB->insert_record('local_latepenalty_overrides', $record);
        }

        redirect(
            $listurl,
            get_string('override_saved', 'local_latepenalty'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    if ($editingoverride) {
        $dailydisplay = ($editingoverride->daily_penalty !== null)
            ? (string) $editingoverride->daily_penalty
            : '';
        $maxdisplay = ($editingoverride->max_penalty !== null)
            ? (string) $editingoverride->max_penalty
            : '';
        $form->set_data((object) [
            'cmid'          => $cmid,
            'overrideid'    => $overrideid,
            'userid'        => $editingoverride->userid,
            'deadline'      => $editingoverride->deadline ?? 0,
            'daily_penalty' => $dailydisplay,
            'max_penalty'   => $maxdisplay,
        ]);
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('overrides_for', 'local_latepenalty', format_string($cm->name)));

    if ($action === 'add' && empty($studentoptions)) {
        echo $OUTPUT->notification(
            get_string('override_no_students', 'local_latepenalty'),
            'info'
        );
        echo html_writer::link($listurl, get_string('back'));
    } else {
        $form->display();
    }

    echo $OUTPUT->footer();
    exit;
}

// Default: list all overrides for this activity.

$overrides = $DB->get_records('local_latepenalty_overrides', ['cmid' => $cmid], 'userid ASC');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('overrides_for', 'local_latepenalty', format_string($cm->name)));

if (empty($overrides)) {
    echo $OUTPUT->notification(get_string('override_empty', 'local_latepenalty'), 'info');
} else {
    // Load all user records in a single query.
    $userids = array_map('intval', array_column($overrides, 'userid'));
    [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
    $namefields = implode(', ', \core_user\fields::get_name_fields());
    $users = $DB->get_records_sql(
        "SELECT id, $namefields FROM {user} WHERE id $insql",
        $inparams
    );

    $dateformat = get_string('strftimedatefullshort', 'langconfig');
    $inherit    = get_string('override_inherit', 'local_latepenalty');

    $table                    = new html_table();
    $table->head              = [
        get_string('override_col_student', 'local_latepenalty'),
        get_string('override_col_deadline', 'local_latepenalty'),
        get_string('override_col_daily', 'local_latepenalty'),
        get_string('override_col_max', 'local_latepenalty'),
        '',
    ];
    $table->attributes['class'] = 'generaltable';

    foreach ($overrides as $override) {
        $user        = $users[$override->userid] ?? null;
        $studentname = $user ? fullname($user) : '?';

        $deadlinecell = ($override->deadline !== null)
            ? userdate((int) $override->deadline, $dateformat)
            : $inherit;
        $dailycell = ($override->daily_penalty !== null)
            ? s((string) $override->daily_penalty) . '%'
            : $inherit;
        $maxcell = ($override->max_penalty !== null)
            ? s((string) $override->max_penalty) . '%'
            : $inherit;

        $editurl   = new moodle_url(
            '/local/latepenalty/overrides.php',
            ['cmid' => $cmid, 'action' => 'edit', 'overrideid' => $override->id]
        );
        $deleteurl = new moodle_url(
            '/local/latepenalty/overrides.php',
            ['cmid' => $cmid, 'action' => 'delete', 'overrideid' => $override->id]
        );

        $actions = html_writer::link($editurl, get_string('override_edit', 'local_latepenalty'))
            . ' &middot; '
            . html_writer::link($deleteurl, get_string('override_delete', 'local_latepenalty'));

        $table->data[] = [s($studentname), $deadlinecell, $dailycell, $maxcell, $actions];
    }

    echo html_writer::table($table);
}

$addurl = new moodle_url('/local/latepenalty/overrides.php', ['cmid' => $cmid, 'action' => 'add']);
echo $OUTPUT->single_button($addurl, get_string('override_add', 'local_latepenalty'), 'get');

echo $OUTPUT->footer();
