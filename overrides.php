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
 * Late penalty override management page — user and group overrides.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_latepenalty\group_override\controller as group_controller;
use local_latepenalty\override\controller as user_controller;

$cmid       = required_param('cmid', PARAM_INT);
$mode       = optional_param('mode', 'user', PARAM_ALPHA);
$action     = optional_param('action', 'list', PARAM_ALPHA);
$overrideid = optional_param('overrideid', 0, PARAM_INT);
$confirm    = optional_param('confirm', 0, PARAM_BOOL);

if (!in_array($mode, ['user', 'group'])) {
    $mode = 'user';
}

$cm     = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);

require_login($course, true, $cm);

$modcontext = context_module::instance($cmid);
require_capability('local/latepenalty:manageoverrides', $modcontext);

$rule = $DB->get_record('local_latepenalty_rules', ['cmid' => $cmid, 'enabled' => 1], '*', MUST_EXIST);

$listurl = new moodle_url('/local/latepenalty/overrides.php', ['cmid' => $cmid, 'mode' => $mode]);

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

if ($mode === 'group') {
    $ctrl = new group_controller($cmid, $course, $cm, $modcontext, $rule, $action, $overrideid, (bool) $confirm);
} else {
    $ctrl = new user_controller($cmid, $course, $cm, $modcontext, $rule, $action, $overrideid, (bool) $confirm);
}

$ctrl->process();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('overrides_for', 'local_latepenalty', format_string($cm->name)));

if ($action === 'list') {
    $usermodeurl  = new moodle_url('/local/latepenalty/overrides.php', ['cmid' => $cmid, 'mode' => 'user']);
    $groupmodeurl = new moodle_url('/local/latepenalty/overrides.php', ['cmid' => $cmid, 'mode' => 'group']);

    $modeselectorhtml  = html_writer::start_tag('ul', ['class' => 'nav nav-pills mb-3']);
    $modeselectorhtml .= html_writer::tag(
        'li',
        html_writer::link(
            $usermodeurl,
            get_string('overrides_mode_user', 'local_latepenalty'),
            ['class' => 'nav-link' . ($mode === 'user' ? ' active' : '')]
        ),
        ['class' => 'nav-item']
    );
    $modeselectorhtml .= html_writer::tag(
        'li',
        html_writer::link(
            $groupmodeurl,
            get_string('overrides_mode_group', 'local_latepenalty'),
            ['class' => 'nav-link' . ($mode === 'group' ? ' active' : '')]
        ),
        ['class' => 'nav-item']
    );
    $modeselectorhtml .= html_writer::end_tag('ul');

    echo $modeselectorhtml;
}

echo $ctrl->render($OUTPUT);
echo $OUTPUT->footer();
