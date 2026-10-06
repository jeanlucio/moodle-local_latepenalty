# 🧩 Optional Integration: PlayerHUD

Late Penalty works on its own. If the gamification block **PlayerHUD** (`block_playerhud`, by the same author) is also installed, teachers can turn a deadline extension into a reward: PlayerHUD offers a **Deadline Extension** item power, which students earn in the game and redeem for extra days on an activity.

* The teacher creates the item in PlayerHUD and sets the number of days and either one activity or *Any eligible activity* (the student chooses when redeeming). The option only appears when Late Penalty is installed.
* Only activities the student can see and that have an enabled Late Penalty rule accept the extension.
* The days are added to the student's current deadline for that activity, so redeeming a second item extends it again.
* The extension is saved as a **Late Penalty override** for the student (deadline only; the rates still come from the rule). Teachers see it, and can change or delete it, on *Late penalty overrides*, *User overrides* tab, and the report shows it as the deadline origin.
* The student's grade is recalculated at once, reducing or removing a penalty already applied.

Neither plugin requires the other. Without PlayerHUD, nothing in Late Penalty changes; without Late Penalty, PlayerHUD simply does not offer the item power.

👉 <https://marketplace.moodle.com/plugins/block_playerhud>
