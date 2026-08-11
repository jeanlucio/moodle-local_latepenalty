# 📊 Penalty Report

Teachers and managers with the `local/latepenalty:viewreport` capability can access a **Penalty Report** for each course through the course navigation menu (**Late penalty report** link in the secondary nav).

The report shows every grade adjustment applied by the plugin in that course:

| Column | Description |
|--------|-------------|
| **Student** | Full name of the student |
| **Activity** | Name of the graded activity |
| **Deadline** | Resolved deadline (completionexpected or module field) |
| **Raw grade** | Grade before the penalty |
| **Discount** | Percentage applied. When a user or group override zeroes the penalty, a badge (*User override* or *Group override*) appears next to the 0.0% value to explain the waiver. |
| **Final grade** | Grade after the penalty |
| **Date applied** | Date the penalty was recorded |

The report includes **filters** for student and activity. Only students and activities that have at least one recorded penalty appear in the filter dropdowns — the report is always available regardless of the course format.

## Exporting the report

Two download buttons appear in the report header whenever there is at least one row. The current student and activity filters are preserved in the export.

| Button | Format | File |
|--------|--------|------|
| **Download CSV** | Comma-separated values | `latepenalty_<shortname>_<date>.csv` |
| **Download Excel** | Excel workbook (.xlsx) | `latepenalty_<shortname>_<date>.xlsx` |

The export contains one additional column — **Override** — that shows *User override* or *Group override* (or is empty) for each row, making it easy to filter waived penalties in a spreadsheet.
