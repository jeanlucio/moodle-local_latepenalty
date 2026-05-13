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
 * English language strings for the Late Penalty plugin.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
// phpcs:disable moodle.Files.LineLength

$string['courseinfo_notice'] = 'This activity must be completed by {$a->deadline}. A penalty of {$a->daily}% per day will be applied, up to a maximum of {$a->max}%.';
$string['error_daily_range'] = 'Daily penalty must be between 0% and 100%';
$string['error_max_less_than_daily'] = 'Maximum penalty cannot be less than daily penalty';
$string['error_max_range'] = 'Maximum penalty must be between 0% and 100%';
$string['filter_activity'] = 'Activity';
$string['filter_all_activities'] = 'All activities';
$string['filter_all_students'] = 'All students';
$string['filter_apply'] = 'Apply';
$string['filter_student'] = 'Student';
$string['latepenalty'] = 'Late penalty';
$string['latepenalty_daily'] = 'Daily penalty (%)';
$string['latepenalty_enabled'] = 'Enable progressive penalty?';
$string['latepenalty_max'] = 'Maximum penalty (%)';
$string['local/latepenalty:viewreport'] = 'View late penalty report';
$string['pluginname'] = 'Late Penalty';
$string['privacy:metadata'] = 'The Late Penalty plugin does not store any personal data. It only stores configuration rules associated with course activities.';
$string['report'] = 'Late penalty report';
$string['report_col_activity'] = 'Activity';
$string['report_col_date'] = 'Penalty applied';
$string['report_col_deadline'] = 'Deadline';
$string['report_col_discount'] = 'Discount';
$string['report_col_finalgrade'] = 'Final grade';
$string['report_col_rawgrade'] = 'Raw grade';
$string['report_col_student'] = 'Student';
$string['report_empty'] = 'No late penalties have been applied in this course yet.';
