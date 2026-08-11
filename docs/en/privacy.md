# 🔒 Privacy

This plugin stores personal data in one table:

| Table | Personal data |
|-------|--------------|
| `local_latepenalty_overrides` | Per-student overrides created by teachers: custom deadline, daily penalty rate, and maximum cap, keyed by `userid` and course module |

The Privacy Provider (`classes/privacy/provider.php`) implements the full Moodle Privacy API:

* **Metadata declaration** — describes the personal data stored in `local_latepenalty_overrides`.
* **Context discovery** — `get_contexts_for_userid()` locates all module contexts where a user has an override record.
* **User discovery** — `get_users_in_context()` identifies all users with override records in a given module context.
* **Data export** — `export_user_data()` exports each override record (deadline, rates, timestamps) under the activity's module context.
* **Data deletion** — supports deletion per user, per context, and per user list (`delete_data_for_user()`, `delete_data_for_all_users_in_context()`, `delete_data_for_users()`).

The following data is **not** managed by this plugin's Privacy API:

* **Penalty rule configuration** (`local_latepenalty_rules`, `local_latepenalty_group_overrides`) — stored per course module or per group, not per individual user.
* **Grade modifications** — recorded in Moodle's standard `grade_grades_history` table, which is owned and managed by Moodle core.
