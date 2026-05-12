# Changelog

All notable changes to the Late Penalty plugin will be documented in this file.

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
