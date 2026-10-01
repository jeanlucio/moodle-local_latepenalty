# ✨ Features

* 📋 **Universal activity support:** Works with every activity type that uses the Moodle Gradebook, not just Assignments.
* 📅 **One deadline chain everywhere:** Late Penalty override → Late Penalty group override → the activity's own extension or override (Assignment, Quiz, Lesson) → the activity due date (Assignment, Forum, and Quiz from Moodle 5.3) → "Set reminder in Timeline". The same chain drives the grades, the course badges, the report and the form.
* 👥 **Group overrides:** Teachers can set a custom deadline, daily rate, and maximum cap for entire groups. When a student belongs to multiple groups with overrides, the most lenient value per field is applied independently (latest deadline, lowest penalty rates), mirroring Moodle's native quiz behaviour.
* 📉 **Progressive daily penalty:** Configurable percentage deducted per day late (e.g., 5% per day).
* 🔒 **Maximum penalty cap:** Deduction never exceeds the configured cap (e.g., 50% maximum), and the final grade is always ≥ 0.
* 🕒 **Lateness of the student's own action:** The attempt, post, entry or record behind the grade, or the submission date the activity reports — never the moment of grading.
* 🏆 **Highest grade respected:** When an activity keeps the highest grade, a late attempt never lowers it.
* 🔄 **Event-driven:** Reacts to `user_graded` events in real time. On Moodle 5.1+ the discount is stored in Moodle's own late-penalty field and the gradebook shows the penalty indicator; on older versions an hourly task picks up grades that changed after a penalty.
* ⚖️ **Plays safe:** Scale grades are never discounted; assignments using Moodle's own grade penalties are left to it; teacher edits and locked grades are never changed.
* 📝 **Gradebook audit trail:** Every grade modification is recorded in Moodle's standard grade history table.
* 💾 **Backup and restore:** Penalty rules travel with the activity on course backup, restore, and duplication.
* 🔔 **Dynamic status badge:** Each activity on the course page shows a contextual badge — grey with the deadline when on time, yellow with the accumulated penalty when overdue, and red when the maximum is reached. Tooltip text adapts to each state. Badge and notice disappear once the student hands the work in, whatever the activity completion settings. Teachers see a role-specific variant: for overdue activities the badge shows the penalty rate plus the number of students who have not yet submitted; when all students have submitted the badge is hidden entirely.
* 🔁 **Automatic recalculation:** Changing the deadline or the rates recalculates the students already penalised (two checkboxes, both on by default). Overrides and extensions — Late Penalty's and the activity's own — recalculate the student at once. Removing the deadline or disabling the rule gives the original grades back.
* 📊 **Penalty report:** A filterable course report of every grade adjustment, with each student's deadline and its origin, and one-click CSV and Excel export.
* 🌐 **Bilingual:** Full support for English and Brazilian Portuguese.
