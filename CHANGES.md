# Changelog

All notable changes to the Late Penalty plugin will be documented in this file.

## [1.2.0] - 2026-05-19

### Added
- Per-student penalty overrides: teachers can set a custom deadline, daily rate,
  and maximum cap for individual students via a new Overrides page
- New capability `local/latepenalty:manageoverrides` controls access to overrides
- Penalty recalculator: when a rule's deadline, daily rate, or maximum changes,
  the plugin re-applies the penalty to all students already graded under the old rule
- Module-native override support: the effective deadline now considers user and group
  overrides set directly in the activity (assign, quiz, lesson), giving the latest
  date across all sources
- Full Privacy API implementation: the plugin now declares personal data stored in
  `local_latepenalty_overrides` and supports GDPR export and deletion

### Fixed
- Moodle 5.2 compatibility: individual assignment extensions now read from
  `assign_overrides` (the `assign_user_flags.extensiondue` column was removed in 5.2)
- Locked grade guard: the observer and recalculator skip activities where the
  grade item or the student's grade record is locked
- Grademin floor: penalties are no longer applied when the raw grade is already at
  `grademin`; calculated grades are floored to `grademin` rather than zero

## [1.1.0] - 2026-05-13

### Added
- Per-student penalty overrides: teachers can set a custom deadline, daily rate,
  and maximum cap for individual students via a new Overrides page
- Penalty recalculator triggered when a rule's deadline, daily rate, or max changes
- Module-native override support: the effective deadline considers user and group
  overrides from assign, quiz, and lesson activities

## [1.0.2] - 2026-05-12

### Added
- Late penalty report: teachers and non-editing teachers can now access a
  per-course report (via the course navigation menu) showing every student
  who received a late penalty, with raw grade, discount applied and final
  grade for each activity
- New capability `local/latepenalty:viewreport` controls access to the report

## [1.0.1] - 2026-05-12

### Added
- Course page penalty notice: activities with an enabled rule now display a
  short reminder below the activity link showing the deadline and penalty
  terms, visible to all enrolled users

## [1.0.0] - 2026-05-12

### Added
- Backup and restore support: penalty rules travel with activities on course
  backup, restore, and duplication

## [1.0.0-alpha] - 2026-01-01

### Added
- Initial plugin structure
- Version metadata and compatibility declaration (Moodle 4.5+)
- English and Brazilian Portuguese language strings
- Plugin foundation ready for database schema and observer implementation
- Database schema for `local_latepenalty_rules` table
- Form injection callbacks for activity configuration
- Validation logic for penalty values (0-100%, daily ≤ max)
- Automatic save/load of penalty rules when editing activities
- Upgrade script structure for future versions
- Event observer for `\core\event\user_graded`
- Automatic late penalty calculation and application
- Progressive penalty formula (days × daily rate, capped at maximum)
- Fallback date logic (completionexpected → timeclose)
- Loop prevention mechanism for grade updates
- Privacy API implementation (null_provider)
