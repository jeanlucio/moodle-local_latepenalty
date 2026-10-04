# Changes

## [v1.3.0] — 2026-10-04

### Rules and deadlines

- The activity form now refuses a daily penalty or a maximum outside 0–100%, or a daily penalty above the maximum; these checks never ran before, so any value was saved
- The daily penalty and the maximum accept the decimal separator of the user's language ("2,5" in Portuguese), in the activity form and in overrides; a decimal comma used to be cut off without warning
- Saving an activity through the course API (scripts, other plugins) keeps the rule as it was saved and only follows a changed due date, instead of switching the rule off
- Restoring a course, or resetting it, with a new start date moves the override deadlines along with the activity dates; after a reset, the first save of the activity no longer recalculates the grades of the term that ended

### Groups

- A student joining or leaving a group is recalculated at once in the activities where that group changes the deadline, through a Late Penalty override or the assignment's, quiz's or lesson's own group override
- Deleting a group removes its Late Penalty overrides and recalculates the course in the background; overrides left behind by earlier versions can now be edited or deleted
- A course reset that removes groups or their members recalculates nothing

### Report and screens

- The report lists the course's current students only (graded role and active enrolment); students who left, or of a term closed by a course reset, no longer appear
- The report shows the latest penalty when several were recorded in the same second, and marks every member of an overridden group
- Names with "&" or quotes show correctly on screen and in the CSV/Excel export
- Rates are written with the language's decimal separator in notices, badges and override lists
- Pending-student counts on teacher badges leave out suspended and ended enrolments, and include custom graded roles and roles given on the category
- The student list for a new override offers only graded students with an active enrolment: no teachers, no suspended students
- The activity form and the report warn when grade history is disabled or kept for a limited time, as penalties cannot then be undone or recalculated
- The overrides link uses an icon that exists in every supported Moodle version

### Compatibility

- Requires Moodle 4.5, as already stated: the installer no longer accepts Moodle 4.4
- Works on sites where the quiz or lesson module has been uninstalled

## [v1.2.0] — 2026-10-01

### Deadlines

- Use the quiz due date of Moodle 5.3 as the penalty deadline, including the due date of student and group quiz overrides; the quiz close date is used only when a quiz has no due date, since it blocks new attempts
- The activity due date (assignment, forum, quiz) now takes priority over "Set reminder in Timeline"; this only changes activities where both dates are set and differ
- Honour assignment extensions ("Grant extension"), which were ignored, and follow the assignment's own rules for overrides: group overrides by priority, and a due date left empty on a student override exempts that student
- Recalculate a student or group at once when an override or extension is created, changed or deleted in the assignment, quiz or lesson itself

### Submission time and multiple attempts

- Measure lateness on the attempt or item that produced the grade, following the grading method (quiz, lesson, SCORM, H5P, rated forum, glossary and database posts), instead of always the latest one
- Use the time of the student's own action: grading a lesson essay, or rating a glossary entry or database record, after the deadline no longer counts as a late submission
- For other modules, use the submission date the module reports with the grade, when it does
- With "highest grade", a late attempt never lowers a better grade obtained on time; external tools and third-party activities offer an option for this, as their grading method cannot be read

### Grades

- On up-to-date Moodle 5.1 and 5.2, and on 5.3, the penalty is stored as the core deducted mark: the gradebook shows the standard late penalty indicator and a better later grade appears at once; elsewhere it stays an overridden grade, and a new hourly scheduled task brings in grades the activity changed after a penalty
- Workshops are now penalised (the submission grade only, never the assessment grade), and so are both grades of a forum with ratings and whole-forum grading
- Scale and "no grade" activities are never discounted, and the form says so
- Assignments using Moodle's own grade penalties (5.0+) are left alone, so a grade is never discounted twice
- Removing the deadline, or disabling the rule, gives the original grades back; enabling a rule for the first time leaves existing grades as they are
- Grades discounted by earlier versions stay as they are; untick *Overridden* for a grade in the grader report to have it recalculated the new way

### Screens

- The report shows each student's effective deadline and where it comes from, also in the export
- The activity form explains each setting and shows the deadline in use
- Badges and notices stay until the student hands the work in, whatever the activity completion conditions; viewing an activity no longer hides them

## [v1.1.2] — 2026-09-25

- Confirmed: tested and confirmed compatible with Moodle 5.3.
- Fix: the test suite no longer triggers a Moodle 5.2+ deprecation notice for
  `course_delete_module()`, guarded to keep using it on Moodle 4.5 where the
  replacement API does not exist yet.

## [v1.1.1] — 2026-09-07

- Restrict the teacher-facing pending-student count on course and activity badges to the caller's own group(s) in courses using separate groups, matching the same boundary already enforced on the report and override pages
- Restore `db/upgrade.php` as a no-op stub required by the Moodle Plugins Directory packaging validator
- Add the MDL Shield badge to the README and documentation

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
