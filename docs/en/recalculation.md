# 🔁 Penalty Recalculation

## On Rule Change

When a teacher edits an activity and changes the **deadline** or the **daily rate / maximum cap**, the plugin can automatically recalculate and reapply late penalties for every student who was already penalised (i.e. has a record in `grade_grades_history` with `source = 'local_latepenalty'`).

Two independent checkboxes appear in the Late Penalty section of the activity form (both **enabled by default**):

| Checkbox | Behaviour |
|---|---|
| **Recalculate penalties when deadline changes** | Reapplies penalties with the new deadline whenever the resolved deadline changes |
| **Recalculate penalties when daily rate or maximum changes** | Reapplies penalties with the new rate/cap whenever either value changes |

### Note

* **Deadline shortening is not retroactive for on-time students.** If the deadline is moved earlier, students who submitted within the *original* deadline had no penalty recorded and will not be penalised retroactively. The teacher must handle those cases manually.

## On Override Save / Delete

When a teacher **creates, edits, or deletes** a per-user override, the affected student's final grade is recalculated immediately using the new effective deadline and rates.

This recalculation uses a dedicated path (`recalculate_for_student()`) that works directly from `grade_grades.rawgrade`, independently of whether the student was previously penalised by this plugin. This makes the recalculation work correctly in two additional scenarios:

| Scenario | How it is handled |
|---|---|
| **Grade set via course restore** | Restore writes `source = 'restore'` to `grade_grades_history`. `recalculate_for_student()` uses `rawgrade` from `grade_grades` directly (not from penalty history), so restored grades are updated correctly. |
| **No prior penalty history** | If the student's grade was never touched by this plugin (e.g. the activity was added to the rule after the student was graded), the method still applies or removes the penalty based on the current `rawgrade` and the new effective deadline. |

### Teacher-edit protection

If a teacher manually edits a student's grade **after** this plugin last wrote it, the subsequent override change will **not** overwrite the teacher's value. The guard compares the most recent `local_latepenalty` history timestamp against the most recent non-plugin history timestamp — the student is skipped when the teacher's edit is newer.

This protection is active only when a prior plugin write exists. When no plugin history entry is found, the grade is treated as the unmodified original and is always eligible for recalculation.
