# Late Penalty

A Moodle local plugin that applies progressive late penalties to activity grades universally across all activity types.

## Overview

The Late Penalty plugin automatically deducts points from student grades based on how many days late they submit their work. Unlike Moodle's native late penalty feature (which only works with Assignments), this plugin works with **any activity type** that generates grades: Quizzes, SCORM, H5P, Forums, and more.

## Key Features

- **Universal compatibility**: Works with all activity types that use the Gradebook
- **Progressive penalties**: Configurable daily penalty rate (e.g., 5% per day)
- **Maximum cap**: Set a maximum penalty limit (e.g., 50% maximum deduction)
- **Automatic calculation**: Uses `completionexpected` date or activity `timeclose` as deadline
- **Transparent auditing**: All grade modifications are logged in the Gradebook history
- **Bilingual**: Full support for English and Brazilian Portuguese

## Requirements

- Moodle 4.5 or higher
- PHP 8.1 or higher

## Installation

1. Download the plugin and extract to `/path/to/moodle/local/latepenalty`
2. Visit Site Administration → Notifications to complete the installation
3. Configure late penalties when creating or editing any activity

## Configuration

When editing any activity, you'll see a new section called "Late Penalty" with:

- **Enable progressive penalty?** (checkbox)
- **Daily penalty (%)** (0-100)
- **Maximum penalty (%)** (0-100)

## How It Works

1. Teacher configures penalty rules when creating/editing an activity
2. Student completes the activity after the deadline
3. Plugin automatically calculates days late and applies the penalty
4. Final grade appears in the Gradebook with the penalty already applied

### Calculation Formula

```
Days Late = ceil((Submission Time - Deadline) / 86400)
Discount = min(Days Late × Daily Rate, Maximum Cap)
Final Grade = Raw Grade × (1 - Discount / 100)
```

## Privacy

This plugin does not store any personal user data. It only stores configuration rules associated with course activities.

## License

GNU GPL v3 or later

## Author

Jean Lúcio © 2026

## Support

For issues, feature requests, or contributions, please refer to the project documentation.
