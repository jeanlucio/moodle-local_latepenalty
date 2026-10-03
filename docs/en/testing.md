# 🧪 Automated Tests

Late Penalty ships with **375 PHPUnit tests** and **14 Behat scenarios**, run on every CI push
across the full matrix: Moodle 4.5, 5.0, 5.1, 5.2 and 5.3 (`main`), each on PostgreSQL and
MariaDB. A few tests only apply where the core offers the feature they check (the quiz due date
from 5.3, deducted marks from the core late-penalty fix) and are skipped elsewhere.

Every test drives the other module through its own API — real submissions, quiz attempts,
ratings, extensions and overrides — never by writing rows into its tables, so a test cannot pass
on a wrong assumption about where a module keeps its data.

### PHPUnit (`tests/`)

| Test file | What it covers |
|-----------|----------------|
| `module_assign_test` | Assignments: latest submission, team submissions, regrading later, extensions, rescaling |
| `module_quiz_test` | Quizzes: attempt chosen by grading method, manual grading, abandoned attempts, the quiz due date (5.3) and the close date |
| `module_lesson_test` | Lessons: retakes and the "use maximum" setting |
| `module_forum_test`, `module_glossary_test`, `module_data_test` | Rated activities: each aggregation type, several raters, whole-forum grading |
| `module_workshop_test` | Workshops: only the submission grade is penalised, never the assessment grade |
| `attempt_methods_test` | H5P and SCORM by first, last and average attempt, following the quiz rules |
| `module_generic_test` | External tools (LTI 1.1 and 1.3) and H5P: the submission date the module reports, or the time the grade arrived |
| `grade_items_test` | Which grade items are penalised (numeric only; no scales, outcomes or workshop assessments) |
| `local/deadline_resolver_test` | The deadline chain: plugin overrides, extensions, activity overrides, due date, completion date, exemptions |
| `local/handed_in_test` | Who has handed an activity in, for the badges and notices: each module's own work, graded or not, never activity completion |
| `local/penalty_writer_test` | How penalties are stored, changed and removed — deducted marks or overrides — and the invariants of every write |
| `keepbest_test` | "Highest grade" activities: a late attempt never lowers a better earlier result |
| `recalculator_test`, `activity_overrides_test` | Recalculation when a rule, an override or an extension changes |
| `group_changes_test` | Students joining or leaving a group, deleted groups and course resets |
| `course_reset_test` | Course resets with a new start date: deadlines moved with the course |
| `observer_test`, `penalty_helper_group_test` | The grade event chain, per-student and per-group overrides |
| `lib_test`, `lib_callbacks_test` | The activity form section, what saving it does, validation and navigation links |
| `hook_listener_test`, `activity_notice_test` | Notices on the course page and on the activity page, for students and teachers |
| `report/controller_test`, `report/deadline_column_test` | The report: group restrictions, filters, export, each deadline origin, query count |
| `override/controller_test`, `group_override/controller_test`, `group_scope_test` | Override pages and separate-groups restrictions |
| `engine_edges_test` | Edge cases of the engine, the observers and the scheduled task |
| `privacy/provider_test` | Privacy API export and deletion |
| `backup/restore_test` | Rules and overrides through backup and restore, deadlines shifted with the course start date |
| `upgrade_test` | The upgrade from 1.1.x and the installation step |

Run the whole suite inside a Moodle with PHPUnit initialised:

```bash
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite local_latepenalty_testsuite
```

### Behat (`tests/behat/`)

* **`local_latepenalty_access.feature`** — the form section, the report link for teachers and
  its absence for students.
* **`local_latepenalty_penalties.feature`** — a late submission discounted in the gradebook,
  the core penalty indicator, the report's deadline origins, the form's "deadline used" line and
  help, scale-graded activities left alone, the keep-best option, disabling the rule and removing
  the due date giving the original grades back, stepping aside when the core assignment penalty
  is on, the quiz due date, and activity pages that never load the gradebook (quiz review,
  glossary) opening normally with a rule enabled.

```bash
php admin/tool/behat/cli/init.php
vendor/bin/behat --config /var/www/behatdata/behatrun/behat/behat.yml --tags @local_latepenalty
```

### Line coverage by class (PHPUnit + Xdebug, Moodle 5.1)

| Class | Line coverage |
|-------|:-------------:|
| `group_scope`, `local\deadline`, `observer`, `penalty_helper`, `task\reprocess_grades` | 100% |
| `local\submission_resolver` | 99% |
| `recalculator` | 99% |
| `local\penalty_writer` | 97% |
| `local\deadline_resolver` | 97% |
| `hook_listener` | 95% |
| `report\controller` | 95% |
| `privacy\provider` | 94% |
| `override\controller` | 83% |
| `group_override\controller` | 72% |
| **Overall** | **87%** |

> The two override controllers look lower than they are: their forms
> (`classes/form/override_form.php`, `classes/form/group_override_form.php`) are instantiated in
> every add/save scenario, but Xdebug fails to record line hits for a `moodleform` subclass
> instantiated across many test methods of one test class — a tool artifact confirmed by
> isolating the same form in a smaller test class. The remaining lines elsewhere are defensive
> returns for states the core does not produce (a grade item whose activity is gone, for example)
> and branches for core versions other than the one measured.
