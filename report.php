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
 * Late penalty report for a course.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_latepenalty\report\controller;

$courseid = required_param('courseid', PARAM_INT);
$filteruserid = optional_param('userid', 0, PARAM_INT);
$filtercmid   = optional_param('cmid', 0, PARAM_INT);

$course = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/latepenalty:viewreport', $context);

$PAGE->set_url(new moodle_url('/local/latepenalty/report.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('report', 'local_latepenalty'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

$controller = new controller($courseid, $context, $filteruserid, $filtercmid);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_latepenalty/report', $controller->get_template_context());
echo $OUTPUT->footer();
