---
layout: default
title: Late Penalty Documentation
lang: en
---

![Moodle](https://img.shields.io/badge/Moodle-4.5%2B-orange?style=flat&logo=moodle&logoColor=white)
![License](https://img.shields.io/badge/License-GPLv3-blue?style=flat)
![Status](https://img.shields.io/badge/Status-Stable-green?style=flat)
[![Latest Release](https://img.shields.io/github/v/release/jeanlucio/moodle-local_latepenalty?style=flat)](https://github.com/jeanlucio/moodle-local_latepenalty/releases)
[![Author](https://img.shields.io/badge/by-Jean_Lucio-6f42c1?style=flat)](https://marketplace.moodle.com/user/984)

[![Moodle Plugin CI](https://github.com/jeanlucio/moodle-local_latepenalty/actions/workflows/ci.yml/badge.svg)](https://github.com/jeanlucio/moodle-local_latepenalty/actions/workflows/ci.yml)
[![Last Commit](https://img.shields.io/github/last-commit/jeanlucio/moodle-local_latepenalty?style=flat)](https://github.com/jeanlucio/moodle-local_latepenalty/commits)
[![Open Issues](https://img.shields.io/github/issues/jeanlucio/moodle-local_latepenalty?style=flat)](https://github.com/jeanlucio/moodle-local_latepenalty/issues)

The **Late Penalty** plugin automatically applies progressive grade deductions to any Moodle
activity when a student submits after the deadline. Unlike Moodle's native late-submission
penalty — limited to Assignments — this plugin listens to the Gradebook's `user_graded` event
and works with every activity type that records a grade.

Use the sidebar to jump to any section on this page.

Source code: [github.com/jeanlucio/moodle-local_latepenalty](https://github.com/jeanlucio/moodle-local_latepenalty)

---

<span id="features"></span>
{% include_relative en/features.md %}

<span id="educational-purpose"></span>
{% include_relative en/educational-purpose.md %}

<span id="requirements"></span>
{% include_relative en/requirements.md %}

<span id="installation"></span>
{% include_relative en/installation.md %}

<span id="configuration"></span>
{% include_relative en/configuration.md %}

<span id="usage"></span>
{% include_relative en/usage.md %}

<span id="report"></span>
{% include_relative en/report.md %}

<span id="recalculation"></span>
{% include_relative en/recalculation.md %}

<span id="testing"></span>
{% include_relative en/testing.md %}

<span id="security"></span>
{% include_relative en/security.md %}

<span id="privacy"></span>
{% include_relative en/privacy.md %}

<span id="license"></span>
{% include_relative en/license.md %}
