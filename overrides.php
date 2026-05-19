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

use local_latepenalty\override\controller;

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

$ctrl = new controller($cmid, $course, $cm, $modcontext, $rule, $action, $overrideid, (bool) $confirm);
$ctrl->process();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('overrides_for', 'local_latepenalty', format_string($cm->name)));
echo $ctrl->render($OUTPUT);
echo $OUTPUT->footer();
