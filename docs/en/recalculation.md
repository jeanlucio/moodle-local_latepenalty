# 🔁 Penalty Recalculation

## When the rule or the deadline changes

Two checkboxes in the Late penalty section (both on by default) control what happens when the teacher saves the activity:

| Checkbox | When saving changes… | Effect |
|---|---|---|
| **Recalculate penalties when deadline changes** | the activity deadline | Grades already discounted are recalculated with the new deadline |
| **Recalculate penalties when daily rate or maximum changes** | the daily rate, the maximum, or "Do not let a new late attempt lower the grade" | Grades already discounted are recalculated with the new values |

* **A later deadline** reduces or removes the discount.
* **An earlier deadline is not retroactive for students who were on time:** only students who were already late get a bigger discount. Students who handed in within the old deadline keep their grade.
* **Removing the deadline** (no due date and no "Set reminder in Timeline") gives back the original grade to every student left without a deadline. Students with an override or extension of their own are recalculated with it.

## Disabling and enabling the rule

* **Disabling** the rule and saving gives back the original grades (the form warns about it before saving).
* **Enabling it again** applies the current rule to every student with a grade, including grades given while it was off, and with the deadline as it is now.
* **Enabling it for the first time** changes no existing grade: only grades given from then on are discounted.
* **To forgive one student**, give a later deadline with a Late Penalty override, or with the activity's own extension or override. Disabling the rule affects everybody.

## When an override or extension changes

Saving or deleting any of these recalculates the affected students at once, whether or not they were penalised before:

* a **Late Penalty override** (student) or **group override** (its members);
* an **activity override**: Assignment, Quiz or Lesson, for a student or a group;
* an **Assignment extension** (*Grant extension*).

## When the activity sends a new grade

A new attempt, a regrade or a corrected essay reaches the plugin as a new grade and is measured again with the rules above. How fast the gradebook shows it depends on the Moodle version, because of how the discount is stored:

| Moodle | How the discount is stored | A better grade after a penalty |
|---|---|---|
| **5.1 and 5.2 (updated), 5.3+** | Moodle's own late-penalty field: the raw grade stays as the activity sent it, the discount is stored beside it and the gradebook shows *Late penalty applied -N points* | Shown immediately |
| **4.5, 5.0 and older 5.1/5.2 builds** | The final grade is written as an override | The override hides the new grade until the scheduled task **Reprocess late-penalised grades changed by the activity** runs (hourly) |

A course whose gradebook calculations are **frozen** at an old version also uses the override storage, because its regrades ignore the stored discount.

Grades discounted by Late Penalty before version 1.2.0 were stored as overrides and stay as they are. To have one recalculated in the new way, untick *Overridden* for that grade in the grader report.

## What a recalculation never touches

* A grade **edited by a teacher** in the gradebook, before or after the penalty.
* A **locked** grade or grade item.
* **Scale** grades and assignments using **Moodle's own grade penalties**.
