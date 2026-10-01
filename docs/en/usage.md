# 📖 How It Works

1. The teacher opens any Moodle activity that records a grade.

2. The activity needs a **deadline** to measure lateness against:
   - **Assignment**, **Forum** and, from Moodle 5.3, **Quiz** have a due date that still accepts late work. Late Penalty uses it. (Do not confuse it with the Assignment cut-off date or the Quiz close date, which block submissions.)
   - **Every other activity** (Lesson, SCORM, H5P, Glossary, Database, external tools…): set **"Set reminder in Timeline"** (*Completion conditions*). It does not block anything and serves as the penalty deadline. Without it there is no deadline and no penalty.

3. The teacher opens the **Late penalty** section, ticks **Enable progressive penalty?** and enters the **Daily penalty (%)** and the **Maximum penalty (%)**. Example: 10% a day, 50% maximum.

4. When the activity already exists, the section shows the line **"Deadline used for the penalty: <date> (<origin>)"**, or warns that there is no deadline. It shows the value as saved; save the form after changing the dates.

5. A **badge** next to the activity on the course page shows the deadline, then the accumulated penalty once it has passed: grey while on time, yellow when overdue, red at the maximum. It disappears once the student completes the activity. Teachers see a variant with the number of students who have not submitted yet.

6. When the activity grades a student, the plugin measures how late the student handed in and discounts the grade.

## Calculation

1. **Days late** — counted from the deadline to the moment the student handed in. Every day started counts as a whole day: 1 minute late is already 1 day, and 25 hours are 2 days.
2. **Discount** — days late × daily rate, never above the maximum.
3. **Final grade** — the grade minus the discount percentage. A grade never goes below the item's minimum.

**Example** (grade 100, 10% a day, 50% maximum):

| Handed in | Discount | Final grade |
|---|---|---|
| On time | 0% | 100 |
| 1 day late | 10% | 90 |
| 2 days late | 20% | 80 |
| 5 days late or more | 50% (maximum) | 50 |

## Which deadline applies to each student

The first of these that is set wins:

| Order | Deadline | Where it is set |
|---|---|---|
| 1 | **Late Penalty override** for the student | *Late penalty overrides* (activity settings menu), *User overrides* tab |
| 2 | **Late Penalty group override** for one of the student's groups (most lenient value of each field across groups) | *Late penalty overrides*, *Group overrides* tab |
| 3 | The **activity's own extension or override** | Assignment: *Grant extension*, then user override, then group override by priority. Quiz: override due date (Moodle 5.3+), otherwise the override close date. Lesson: override deadline. |
| 4 | The **activity due date** | Assignment, Forum, and Quiz from Moodle 5.3 |
| 5 | **"Set reminder in Timeline"** | Any activity |

Notes:

* An assignment or quiz override that **removes** the due date for a student means that student has no deadline and is never penalised.
* The Assignment extension wins over its overrides, as in the Assignment itself.
* The workshop submission end, the quiz close date and the lesson deadline are not used: they close the activity rather than mark work as late. Use "Set reminder in Timeline" for those activities.
* The penalty report shows the deadline of each student and where it comes from.

## When the student handed in

The plugin always uses the moment of the student's own action, never the moment of grading. A teacher grading late, an essay graded later or a regrade never adds lateness.

| Activity | Moment used |
|---|---|
| Assignment | The submission (the student's own, or the group submission for team assignments) |
| Quiz | The attempt behind the grade: first, last, highest (the earliest attempt with that grade), or the last attempt for an average |
| Lesson | The attempt behind the grade: the first when retakes are off, the highest, or the last one for a mean |
| Forum, Glossary, Database with ratings | The post, entry or record behind the grade: the one with the highest rating for "Maximum", the lowest for "Minimum", the latest rated one for average, count or sum |
| Forum whole-forum grading | The student's latest post |
| Workshop | The latest change to the submission |
| Other activities (H5P, SCORM, external tools, other plugins) | The submission date the activity reports to the gradebook; otherwise the date it graded or sent the grade |

In the **Glossary** and the **Database**, the entry's creation time counts. Later edits do not count as lateness; the date of the last change is shown on the entry itself, and the teacher can take it into account when rating.

## Highest grade: a late attempt never lowers the grade

When an activity keeps the highest of several grades, each attempt is discounted by its own lateness and the best result stays. Example, 10% a day: 90 on time, then 100 two days late (100 − 20% = 80) → the grade stays **90**.

This is automatic for Quiz, Lesson, SCORM and H5P graded by highest attempt, and for Forum, Glossary and Database rated by "Maximum". For **external tools** and **activities from other plugins**, whose grading method Late Penalty cannot read, the form offers **"Do not let a new late attempt lower the grade"** (off by default). With it, earlier grades count from the time they reached the gradebook, because the gradebook history keeps no submission date.

## What is never discounted

* **Scale grades** ("Good", "Excellent"…) and activities with no grade type. A percentage of a position in a scale means nothing; the form says so.
* **Assignments that use Moodle's own grade penalties** (Moodle 5.0+, *Grade penalties: Yes* in the assignment). Late Penalty steps aside there so a grade is never discounted twice; its section in the form is disabled. Grades it discounted earlier stay as they are.
* **Grades edited by a teacher** in the gradebook, and **locked** grades or items.
* The **workshop assessment grade** (how the student assessed peers). The submission grade is discounted.

> **Grading without a submission:** if a teacher grades a student who never handed anything in (a forum where the student never posted, for example), there is no submission to measure and no penalty is applied.

## Course-page Notice Compatibility

The **course-page notice** works with any course format that uses Moodle's standard activity rendering (`[data-for="cmitem"]`), which includes the built-in **Topics**, **Weeks** and **Single Activity** formats. Formats that replace the standard activity HTML may not show it. **The penalty calculation, grade history and the Penalty Report are not affected — only the course-page notice.**
