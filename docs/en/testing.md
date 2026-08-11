# 🧪 Automated Tests

Late Penalty ships with **125 PHPUnit tests** plus a Behat suite, run on every CI push across
the full matrix (Moodle 4.5 → 5.2, PostgreSQL & MariaDB).

### PHPUnit (`tests/`)

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
| Override controller | Render list (empty state, student name and penalties, always includes add button); render add (no students when all covered, excludes a student outside a restricted group); save add rejects unenrolled user; save edit preserves original user; delete removes record on confirm, leaves record without confirm, does not affect foreign override; render list/edit/delete-by-ID all exclude an override outside the caller's restricted group |
| Group override controller | Render list (empty state, group name and penalties, always includes add button); render add (no groups notice when all covered, excludes a group outside the restriction); delete removes record on confirm, leaves record without confirm, does not affect foreign-CM override; render list excludes an override outside the caller's restriction |
| Report controller — group restriction | `resolve_group_restriction()`: non-editing teacher without accessallgroups restricted to their own group, editing teacher sees all, non-separate-groups course is unrestricted, teacher in no group restricted to an empty set, activity-level groupmode overrides an unforced course setting; the report table, its student filter, and the CSV/Excel export data all honour the same restriction |
| Group scope resolution | `resolve_activity_restriction()` — null when not in separate groups, null for VISIBLEGROUPS, null for a caller with `moodle/site:accessallgroups`, restricted to the caller's own groups, restricted to an empty set for a caller in no group, course-level `groupmodeforce` overrides the activity's own setting, activity-level groupmode applies when the course does not force its own |
| Course notices | A hidden activity's cmid, deadline and penalty rate are excluded from the AMD payload sent to a student, even though the underlying query does not itself filter by visibility; a teacher with `local/latepenalty:viewreport` still sees notices for hidden activities |
| Privacy provider | Metadata declaration; `get_contexts_for_userid()` for a student with and without an override; `get_users_in_context()` including a non-module context; `export_user_data()`; deletion per context, per user, and per user list |
| Backup / restore | Rule travels with the activity and is remapped to the new course module (regression guard for resolving the module before its instance is linked); per-user and per-group overrides remapped with user data; source course rule unaffected by restore into a new course |

Run them locally with:

```bash
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit local/latepenalty/tests/observer_test.php
vendor/bin/phpunit local/latepenalty/tests/recalculator_test.php
vendor/bin/phpunit local/latepenalty/tests/penalty_helper_group_test.php
vendor/bin/phpunit local/latepenalty/tests/override/controller_test.php
vendor/bin/phpunit local/latepenalty/tests/group_override/controller_test.php
vendor/bin/phpunit local/latepenalty/tests/report/controller_test.php
vendor/bin/phpunit local/latepenalty/tests/group_scope_test.php
vendor/bin/phpunit local/latepenalty/tests/hook_listener_test.php
vendor/bin/phpunit local/latepenalty/tests/privacy/provider_test.php
vendor/bin/phpunit local/latepenalty/tests/backup/restore_test.php
```

### Behat (`tests/behat/local_latepenalty_access.feature`)

Three scenarios prove the plugin's capability-based access end to end, in a real browser
session:

* an editing teacher sees the **Late penalty** section, including the **Enable progressive
  penalty?** field, in an assignment's editing settings;
* a teacher sees the **Late penalty report** link in the course's secondary navigation;
* a student does **not** see that link — the report is teacher/manager-only.

```bash
php admin/tool/behat/cli/init.php
vendor/bin/behat --config /var/www/behatdata/behatrun/behat/behat.yml \
  --tags @local_latepenalty
```

### Line coverage by class (PHPUnit + Xdebug, via the `moodle-coverage` tool)

| Class | Line coverage |
|-------|:-------------:|
| `group_scope` | 100% |
| `privacy\provider` | 94% |
| `report\controller` | 89% |
| `observer` | 86% |
| `override\controller` | 83% |
| `recalculator` | 82% |
| `group_override\controller` | 72% |
| `hook_listener` | 57% |
| `penalty_helper` | 42% |
| **Overall** | **62%** |

> `classes/form/override_form.php` and `classes/form/group_override_form.php` are exercised by
> the controller tests above (every `render_add()`/save scenario instantiates them) but are not
> reflected in this table at all: Xdebug's coverage driver reliably fails to record any line
> hits for a `moodleform` subclass once it is instantiated across a large number of sibling test
> methods within one test class (16 in `override\controller_test`, 13 in
> `group_override\controller_test`) — a tool artifact confirmed by isolating the same form in a
> smaller test class, not a real gap in what the tests exercise. `penalty_helper`'s lower figure
> is a genuine, reviewed gap: its two bulk-loading helpers (`get_submission_times_bulk()`,
> `get_module_user_deadlines_bulk()`), used by `recalculator.php`'s group-recalculation path,
> only have their `assign` branch exercised by the `recalculate_for_group()` tests above — the
> `quiz`/`workshop`/`forum` branches and the missing-id fallback paths inside those two large
> `switch` statements are not yet covered.
