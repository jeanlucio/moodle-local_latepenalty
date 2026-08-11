# Changes

## [v1.1.0] — 2026-08-11

- Fix a backup data leak, orphaned rows left behind when an activity or course is deleted, and an information leak that exposed hidden activities' deadlines and penalty rates to students
- Restrict the late penalty report and the per-user/per-group override management pages to the caller's own group(s) in courses using separate groups, including when editing or deleting an override directly by its ID
- Replace two developer-facing error messages with proper translated messages for the business rules they represent
- Show the exact deadline time, not just the date, in course notices and activity badges, formatted according to the site's language
- Add regression test coverage for all of the above, plus the group-scope resolver and the Privacy API provider
- Move the full documentation to a GitHub Pages site, keeping the README as a short overview with links

## [v1.0.3] — 2026-06-17

- Fix a fatal error when restoring a course: the restore step resolved the host course module with `MUST_EXIST` before the activity instance was linked, aborting the restore of any course whose modules carry a penalty rule. The deadline seed now degrades gracefully and is recomputed on the first save.
- Add backup/restore PHPUnit coverage (rule, per-user and per-group overrides, source course unaffected)

## [v1.0.2] — 2026-05-31

- Fix a duplicate key error when recalculating penalties: the grade history primary key is now selected first so `get_records_sql()` always receives a unique array key

## [v1.0.1] — 2026-05-23

- Fix `@package` tag in all test files to use the component root (`local_latepenalty`)
- Add CI workflow to publish releases automatically to the Moodle Plugins directory

## [v1.0.0] — 2026-05-23

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
  below the activity link with the deadline and penalty terms; teachers see
  a role-specific variant for overdue activities showing the penalty rate
  and how many students have not yet submitted (badge hidden when all
  students have submitted)
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
