# 🧪 Automated Tests

Late Penalty ships with **81 PHPUnit tests** that run on every CI push across the full matrix (Moodle 4.5 → 5.2, PostgreSQL & MariaDB):

| Test group | Scenarios covered |
|------------|------------------|
| `calculate_days_late()` | Timestamp arithmetic — on-time, exactly 1 day, fractional days rounded up |
| `apply_penalty()` | Discount formula, edge cases (0% rate, 100% cap, grade already 0) |
| `get_submission_time()` | Forum no posts; assign individual; assign no submission; assign team (userid = 0); h5pactivity returns null (event-timestamp fallback documented) |
| Observer chain — assign | No rule, disabled rule, no deadline, on-time, 1 day late, 2 days late, capped at max, deadline from module field, team submission penalty |
| Observer chain — quiz | 1 day late via `completionexpected` + `quiz_attempts.timefinish` |
| Observer chain — h5pactivity | Late (event-timestamp fallback): penalty applied; on-time: grade unchanged |
| Observer — per-user overrides | Custom deadline (shifts or removes lateness), custom daily rate, custom max cap, waived penalty (daily = 0), all-null override inherits rule |
| `get_module_user_deadline()` | Assign extension, assign user override, assign group override, quiz user override, lesson user override, unknown module → null, no override → null, full-chain integration with extension |
| Group override helper | `get_group_override()` — null when no applicable override, null when user in no group, single group, most-lenient resolution (MAX deadline, MIN rates) across multiple groups, partial null fields; `get_group_overrides_bulk()` — empty input, per-user merged values, most-lenient per user |
| Recalculation | Extended deadline reduces penalty, deadline restored on-time grade, rate change recalculates, on-time student untouched |
| Recalculation — per-user overrides | Override deadline, override daily rate, override max cap each take precedence over new rule parameters |
| Recalculation — group overrides | Group override deadline applied, user override beats group override, `recalculate_for_group()` updates all group members |
| Recalculation — h5pactivity | Rate change recalculates penalty from `grade_grades_history` timestamp |
| Recalculation — teacher override | Manually overridden grade is not touched by recalculation |
| Override controller | Render list (empty state, student name and penalties, always includes add button); render add (no students when all covered); save add rejects unenrolled user; save edit preserves original user; delete removes record on confirm, leaves record without confirm, does not affect foreign override |
| Group override controller | Render list (empty state, group name and penalties, always includes add button); render add (no groups notice when all covered); delete removes record on confirm, leaves record without confirm, does not affect foreign-CM override |
| Backup / restore | Rule travels with the activity and is remapped to the new course module (regression guard for resolving the module before its instance is linked); per-user and per-group overrides remapped with user data; source course rule unaffected by restore into a new course |

Run them locally with:

```bash
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit local/latepenalty/tests/observer_test.php
vendor/bin/phpunit local/latepenalty/tests/recalculator_test.php
vendor/bin/phpunit local/latepenalty/tests/penalty_helper_group_test.php
vendor/bin/phpunit local/latepenalty/tests/override/controller_test.php
vendor/bin/phpunit local/latepenalty/tests/group_override/controller_test.php
vendor/bin/phpunit local/latepenalty/tests/backup/restore_test.php
```
