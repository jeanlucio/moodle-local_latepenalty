# Changes

## [1.0.0] - 2026-05-23

- Penalty rule configuration per activity: teachers can enable a daily
  late penalty rate (%) and a maximum cap (%) on any supported activity
- Automatic penalty calculation triggered when a student's grade is saved,
  applying the progressive formula (days late × daily rate, capped at maximum)
- Effective deadline resolution: considers user and group overrides set
  directly in the activity (assign, quiz, lesson), giving the latest date
  across all sources
- Per-student overrides: teachers can set a custom deadline, daily rate,
  and maximum cap for individual students via a dedicated Overrides page
- Group overrides: teachers can set a custom deadline, daily rate, and
  maximum cap for entire groups via a dedicated Group Overrides page;
  when a student belongs to multiple groups with overrides, the most lenient
  value per field is applied (latest deadline, lowest penalty rates)
- Penalty recalculator: when a rule's deadline, daily rate, or maximum cap
  changes, the plugin re-applies the penalty to all already-graded students
- Course page notice: activities with an active rule show a short reminder
  below the activity link with the deadline and penalty terms
- Late penalty report: per-course report showing every student who received
  a penalty, with raw grade, discount applied, and final grade
- Backup and restore: penalty rules and per-user/group overrides travel with
  activities on course backup, restore, and duplication
- Privacy API: declares personal data stored in overrides and supports GDPR
  export and deletion
- Capabilities: `local/latepenalty:manageoverrides` and
  `local/latepenalty:viewreport`
- Locked grade guard: skips activities where the grade item or student grade
  is locked
- Grademin floor: penalties never push a grade below the activity's minimum
- Moodle 4.5–5.2 compatible; English and Brazilian Portuguese included
