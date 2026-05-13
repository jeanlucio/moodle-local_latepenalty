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
 * Controller for the late penalty course report.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_latepenalty\report;

use context_course;

/**
 * Builds the template context for the late penalty report page.
 *
 * Queries grade_grades_history for rows written by this plugin and
 * returns the most recent penalty event per student+activity pair.
 *
 * @package local_latepenalty\report
 */
class controller {
    /** @var int The course id. */
    private int $courseid;

    /** @var context_course The course context. */
    private context_course $context;

    /**
     * Deadline field per module type, mirroring the observer's map.
     *
     * @var array<string,string>
     */
    private static array $deadlinefields = [
        'assign'      => 'duedate',
        'forum'       => 'duedate',
        'lesson'      => 'deadline',
        'playergroup' => 'timeclose',
        'quiz'        => 'timeclose',
        'scorm'       => 'timeclose',
        'workshop'    => 'submissionend',
    ];

    /**
     * Constructor.
     *
     * @param int            $courseid The course id.
     * @param context_course $context  The course context.
     */
    public function __construct(int $courseid, context_course $context) {
        $this->courseid = $courseid;
        $this->context  = $context;
    }

    /**
     * Returns the template context array for the report page.
     *
     * @return array Context array ready for render_from_template.
     */
    public function get_template_context(): array {
        global $DB;

        $sql = "SELECT ggh.id, ggh.userid, ggh.itemid,
                       ggh.rawgrade, ggh.finalgrade, ggh.timemodified,
                       gi.grademax, gi.itemmodule, gi.iteminstance,
                       cm.id AS cmid, cm.completionexpected,
                       u.firstname, u.lastname,
                       u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename
                  FROM {grade_grades_history} ggh
                  JOIN {grade_items} gi ON gi.id = ggh.itemid
                                       AND gi.itemtype = 'mod'
                                       AND gi.courseid = :courseid
                  JOIN {user} u ON u.id = ggh.userid AND u.deleted = 0
                  JOIN {modules} mod ON mod.name = gi.itemmodule
                  JOIN {course_modules} cm ON cm.instance = gi.iteminstance
                                          AND cm.course = :courseid2
                                          AND cm.module = mod.id
                  JOIN {local_latepenalty_rules} r ON r.cmid = cm.id AND r.enabled = 1
                 WHERE ggh.source = 'local_latepenalty'
                 ORDER BY u.lastname, u.firstname, cm.id, ggh.timemodified DESC";

        $rows = $DB->get_records_sql($sql, [
            'courseid'  => $this->courseid,
            'courseid2' => $this->courseid,
        ]);

        $modinfo = get_fast_modinfo($this->courseid);

        // Keep only the most recent penalty per student + grade item (ORDER BY DESC above).
        $seen      = [];
        $penalties = [];

        foreach ($rows as $row) {
            $key = $row->userid . '_' . $row->itemid;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $rawgrade  = (float) $row->rawgrade;
            $finalgrade = (float) $row->finalgrade;
            $discount  = ($rawgrade > 0)
                ? round((1.0 - $finalgrade / $rawgrade) * 100.0, 1)
                : 0.0;

            $fakeuser = (object) [
                'firstname'         => $row->firstname ?? '',
                'lastname'          => $row->lastname ?? '',
                'firstnamephonetic' => $row->firstnamephonetic ?? '',
                'lastnamephonetic'  => $row->lastnamephonetic ?? '',
                'middlename'        => $row->middlename ?? '',
                'alternatename'     => $row->alternatename ?? '',
            ];

            $cmname = isset($modinfo->cms[$row->cmid])
                ? format_string($modinfo->cms[$row->cmid]->name, true, ['context' => $this->context])
                : '';

            $deadline = self::resolve_deadline($row);
            $dateformat = get_string('strftimedatefullshort', 'langconfig');

            $penalties[] = [
                'fullname'           => format_string(
                    fullname($fakeuser),
                    true,
                    ['context' => $this->context]
                ),
                'activity'           => $cmname,
                'hasdeadline'        => $deadline !== null,
                'completionexpected' => $deadline !== null
                    ? userdate($deadline, $dateformat)
                    : '',
                'rawgrade'           => format_float($rawgrade, 2),
                'discount'           => format_float($discount, 1),
                'finalgrade'         => format_float($finalgrade, 2),
                'grademax'           => format_float((float) $row->grademax, 2),
                'penaltydate'        => userdate((int) $row->timemodified),
            ];
        }

        return [
            'penalties'    => $penalties,
            'haspenalties' => !empty($penalties),
        ];
    }

    /**
     * Resolve the effective deadline for a grade history row.
     *
     * Mirrors the observer logic: completionexpected takes priority; falls back
     * to the module-specific deadline field (duedate, timeclose, etc.).
     *
     * @param \stdClass $row Row from the report query containing completionexpected,
     *                       itemmodule and iteminstance.
     * @return int|null Deadline timestamp or null if not determinable.
     */
    private static function resolve_deadline(\stdClass $row): ?int {
        global $DB;

        if (!empty($row->completionexpected)) {
            return (int) $row->completionexpected;
        }

        $field = self::$deadlinefields[$row->itemmodule] ?? null;
        if (!$field) {
            return null;
        }

        $value = $DB->get_field($row->itemmodule, $field, ['id' => $row->iteminstance]);
        return ($value) ? (int) $value : null;
    }
}
