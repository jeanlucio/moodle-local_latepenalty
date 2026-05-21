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

$string['badge_ontime'] = 'Deadline: {$a->date}';
$string['badge_penalty'] = 'Penalty: {$a->pct}%';
$string['badge_penalty_max'] = 'Penalty: {$a->pct}% (max)';
$string['courseinfo_notice'] = 'This activity must be completed by {$a->deadline}. A penalty of {$a->daily}% per day will be applied, up to a maximum of {$a->max}%.';
$string['courseinfo_notice_overdue'] = 'The deadline for this activity was {$a->deadline}. Accumulated penalty: {$a->pct}% ({$a->daily}% per day · maximum of {$a->max}%).';
$string['courseinfo_notice_overdue_max'] = 'The deadline for this activity was {$a->deadline}. A penalty of {$a->max}% (max) is being applied.';
$string['error_daily_range'] = 'Daily penalty must be between 0% and 100%';
$string['error_max_less_than_daily'] = 'Maximum penalty cannot be less than daily penalty';
$string['error_max_range'] = 'Maximum penalty must be between 0% and 100%';
$string['filter_activity'] = 'Activity';
$string['filter_all_activities'] = 'All activities';
$string['filter_all_students'] = 'All students';
$string['filter_apply'] = 'Apply';
$string['filter_student'] = 'Student';
$string['latepenalty'] = 'Late penalty';
$string['latepenalty:manageoverrides'] = 'Manage late penalty overrides per student';
$string['latepenalty:viewreport'] = 'View late penalty report';
$string['latepenalty_daily'] = 'Daily penalty (%)';
$string['latepenalty_enabled'] = 'Enable progressive penalty?';
$string['latepenalty_max'] = 'Maximum penalty (%)';
$string['latepenalty_recalc_deadline'] = 'Recalculate penalties when deadline changes';
$string['latepenalty_recalc_rate'] = 'Recalculate penalties when daily rate or maximum changes';
$string['override_add'] = 'Add override';
$string['override_col_daily'] = 'Daily (%)';
$string['override_col_deadline'] = 'Deadline';
$string['override_col_max'] = 'Max (%)';
$string['override_col_student'] = 'Student';
$string['override_confirm_delete'] = 'Are you sure you want to delete the override for {$a}?';
$string['override_daily'] = 'Daily penalty (%)';
$string['override_deadline'] = 'Custom deadline';
$string['override_delete'] = 'Delete';
$string['override_deleted'] = 'Override deleted successfully.';
$string['override_edit'] = 'Edit';
$string['override_empty'] = 'No overrides have been set for this activity.';
$string['override_error_duplicate'] = 'This student already has an override for this activity.';
$string['override_error_nothing_enabled'] = 'Enable at least one field to create an override.';
$string['override_hint'] = 'Leave a field blank to inherit the activity\'s configured value.';
$string['override_inherit'] = 'Activity default';
$string['override_max'] = 'Maximum penalty (%)';
$string['override_no_students'] = 'All enrolled students already have an override for this activity.';
$string['override_saved'] = 'Override saved successfully.';
$string['override_student'] = 'Student';
$string['overrides'] = 'Late penalty overrides';
$string['overrides_for'] = 'Late penalty overrides: {$a}';
$string['pluginname'] = 'Late Penalty';
$string['privacy:metadata'] = 'The Late Penalty plugin stores per-student penalty overrides in the local_latepenalty_overrides table. These overrides may include a custom deadline, daily penalty rate, and maximum penalty cap configured by a teacher for a specific student and activity.';
$string['privacy:metadata:local_latepenalty_overrides'] = 'Per-student deadline and penalty rate overrides configured by teachers for specific activities.';
$string['privacy:metadata:local_latepenalty_overrides:cmid'] = 'The course module this override applies to.';
$string['privacy:metadata:local_latepenalty_overrides:daily_penalty'] = 'Custom daily penalty percentage for this student, or null to inherit the activity rule.';
$string['privacy:metadata:local_latepenalty_overrides:deadline'] = 'Custom submission deadline for this student, or null to inherit the activity deadline.';
$string['privacy:metadata:local_latepenalty_overrides:max_penalty'] = 'Custom maximum penalty cap for this student, or null to inherit the activity rule.';
$string['privacy:metadata:local_latepenalty_overrides:timecreated'] = 'The time this override was created.';
$string['privacy:metadata:local_latepenalty_overrides:timemodified'] = 'The time this override was last modified.';
$string['privacy:metadata:local_latepenalty_overrides:userid'] = 'The ID of the student this override applies to.';
$string['report'] = 'Late penalty report';
$string['report_col_activity'] = 'Activity';
$string['report_col_date'] = 'Penalty applied';
$string['report_col_deadline'] = 'Deadline';
$string['report_col_discount'] = 'Discount';
$string['report_col_finalgrade'] = 'Final grade';
$string['report_col_rawgrade'] = 'Raw grade';
$string['report_col_student'] = 'Student';
$string['report_empty'] = 'No late penalties have been applied in this course yet.';
