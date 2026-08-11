# ✨ Features

* 📋 **Universal activity support:** Works with every activity type that uses the Moodle Gradebook, not just Assignments.
* 📅 **Flexible deadline resolution:** Resolves the effective deadline through a priority chain: plugin per-user override → plugin group override → module-native user/group override (Assignment extensions and overrides, Quiz overrides, Lesson overrides) → `completionexpected` → module deadline field (Assignment and Forum only).
* 👥 **Group overrides:** Teachers can set a custom deadline, daily rate, and maximum cap for entire groups. When a student belongs to multiple groups with overrides, the most lenient value per field is applied independently (latest deadline, lowest penalty rates), mirroring Moodle's native quiz behaviour.
* 📉 **Progressive daily penalty:** Configurable percentage deducted per day late (e.g., 5% per day).
* 🔒 **Maximum penalty cap:** Deduction never exceeds the configured cap (e.g., 50% maximum), and the final grade is always ≥ 0.
* 🔄 **Event-driven, zero polling:** Reacts to `user_graded` events in real time — no cron jobs, no scheduled tasks.
* 📝 **Gradebook audit trail:** Every grade modification is recorded in Moodle's standard grade history table.
* 💾 **Backup and restore:** Penalty rules travel with the activity on course backup, restore, and duplication.
* 🔔 **Dynamic status badge:** Each activity on the course page shows a contextual badge — grey with the deadline when on time, yellow with the accumulated penalty when overdue, and red when the maximum is reached. Tooltip text adapts to each state. Badge and notice disappear automatically once the student completes the activity. Teachers see a role-specific variant: for overdue activities the badge shows the penalty rate plus the number of students who have not yet submitted; when all students have submitted the badge is hidden entirely.
* 🔁 **Automatic penalty recalculation:** When a teacher changes the deadline or penalty rate of an activity, the plugin can automatically recalculate and reapply penalties for all students who were already penalised. Two independent checkboxes (both enabled by default) let the teacher control whether each type of change triggers a recalculation.
* 📊 **Penalty report:** Teachers access a filterable course report listing every grade adjustment applied by the plugin, with one-click CSV and Excel export, always available regardless of course format.
* 🌐 **Bilingual:** Full support for English and Brazilian Portuguese.
