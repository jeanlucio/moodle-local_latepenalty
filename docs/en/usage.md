# 📖 How It Works

1. The teacher opens any Moodle activity that has a grade and completion conditions.

2. The teacher sets a **submission deadline** for the activity, which serves as the reference point for penalty calculation:
   - **Assignment** and **Forum**: have a native due date field (do not confuse with the Assignment cut-off date, which blocks submissions and prevents penalty calculation).
   - **Quiz, Lesson, SCORM, and all other activities**: do not have a deadline that allows late submission. For these, the **"Set reminder on timeline"** field (*Completion conditions* tab) is **required** — it does not block submissions and serves solely as the penalty reference date. Without it configured, there is no deadline and no penalty is applied.

3. The teacher then opens the **Late Penalty** section and enables **Enable progressive penalty**.

4. The teacher enters the **daily penalty (%)** and the **maximum penalty (%)**. Example: 10% daily penalty with a 50% cap → the system deducts 10% of the student's achieved grade per day late, up to a maximum of 50%, regardless of how many days pass after that point.

5. When the activity is saved, a **badge** appears next to the activity name showing the deadline. After the deadline, if the student has not yet completed the activity, the badge switches to show the accumulated penalty. The badge has contextual status: grey with the deadline when on time, yellow with the accumulated penalty when overdue, and red when the maximum is reached. The tooltip adapts to each state. The badge and the activity-page notice disappear automatically once the student completes the activity. **Teachers see a different badge for overdue activities:** it shows the penalty rate plus how many students have not yet submitted. When all students have submitted, the badge is hidden — there is nothing actionable left to show.

6. When a student submits after the deadline and a grade is assigned (manually by the teacher or automatically), the plugin calculates and applies the penalty.

7. If a **deadline override** is set for a specific student, it takes precedence over other configurations. The priority order is:
   - **Plugin per-user override** — accessed via *Penalty overrides* inside the activity. Highest priority.
   - **Plugin group override** — accessed via *Group penalty overrides* inside the activity. When the student belongs to multiple groups, the most lenient value per field is used.
   - **Module-native override** — Assignment (extension/override), Quiz (override), and Lesson (override) have their own fields, checked next.
   - **"Set reminder on timeline"** (`completionexpected`) — applies to any activity type.
   - **Native deadline field** — Assignment and Forum only, as a final fallback.

8. Days late are calculated and the discount is applied.

9. The adjusted grade is written back to the Gradebook via the standard grade API.

> **Note — manual grading without a submission:** The penalty is based on the student's submission timestamp, not on when the teacher grades. If a teacher assigns a grade to a student who never submitted (e.g., a Forum where the student posted nothing), no submission record exists and the plugin skips the penalty entirely. This is by design: without a submission there is no lateness to measure.

> **Note — Assignment team (group) submissions:** When an Assignment is configured for team submissions with *Require all team members to submit* **disabled**, Moodle stores a single submission record for the whole group (`userid = 0`). The plugin automatically detects this case, looks up the student's groups, and uses the **group submission timestamp** as the basis for penalty calculation for every group member. When *Require all team members to submit* is **enabled**, Moodle records an individual submission per member and each student's own submission time is used.

## Calculation

1. **Days late** — counted from the moment of submission. Any fraction of a day counts as a full day (rounded up). Example: submitted 25 hours after the deadline = 2 days late.
2. **Discount** — days late × daily rate, capped at the maximum penalty.
3. **Final grade** — the raw grade reduced by the discount percentage.

**Example** (raw grade: 100 | daily penalty: 10% | cap: 50%):

| Submission | Discount | Final grade |
|---|---|---|
| On time | 0% | 100 |
| 1 day late | 10% | 90 |
| 2 days late | 20% | 80 |
| 3 days late | 30% | 70 |
| 4 days late | 40% | 60 |
| 5+ days late | 50% (cap) | 50 |

## Deadline Priority Chain

For each student, the effective deadline is resolved in this order (first match wins):

| Priority | Source | Applies to |
|---|---|---|
| 1 | Plugin per-user override (`local_latepenalty_overrides`) | All modules |
| 2 | Plugin group override (`local_latepenalty_group_overrides`) — most lenient value per field across all of the student's groups | All modules |
| 3 | Module-native user/group override | Assignment (`assign_user_flags.extensiondue`, `assign_overrides.duedate`), Quiz (`quiz_overrides.timeclose`), Lesson (`lesson_overrides.deadline`) |
| 4 | `completionexpected` on the course module | All modules |
| 5 | Module deadline field | See table below |

For module-native overrides at level 3, the **most favourable (latest) deadline** among all of the student's groups is used, mirroring Moodle's native behaviour.

If a teacher sets both a plugin override and a native module override for the same student, the **plugin override takes precedence** (it was set explicitly for penalty purposes).

## Module Deadline Fields (level 4 fallback)

Only activities whose deadline field is a **soft deadline** — meaning the module does not block submissions after it — are supported at this level.

| Activity   | Deadline field   | Why soft?                                              |
|------------|------------------|--------------------------------------------------------|
| Assignment | `assign.duedate` | Moodle allows late submissions until `cutoffdate`      |
| Forum      | `forum.duedate`  | Calendar display only; posts are never blocked         |

All other activity types (Quiz, Lesson, SCORM, Workshop, H5P, PlayerGroup, etc.) enforce a hard close that prevents any submission after the deadline, so their native deadline field is never used as the penalty deadline. Use `completionexpected` for those activities instead.

## Course-page Notice Compatibility

The **course-page notice** (the reminder displayed below each activity before a student starts) works with any course format that uses Moodle's standard activity rendering (`[data-for="cmitem"]` on the activity element), which includes the built-in **Topics**, **Weeks**, and **Single Activity** formats.

Third-party formats that replace the standard module HTML with a custom layout (such as visual trail or board formats) may not display the per-activity notice on the course page. **The penalty calculation, grade history, and the Penalty Report are not affected — only the course-page notice display.**
