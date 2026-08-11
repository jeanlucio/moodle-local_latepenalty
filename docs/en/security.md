# 🔐 Security & Compliance

* Capability-based access via Moodle's standard form API
* `require_sesskey()` protection on all POST actions
* No SQL string interpolation — parameterised queries throughout
* Grade writes use the official Moodle grade API (`update_final_grade`)
* Anti-recursion guard prevents the grade event from re-triggering the observer infinitely
