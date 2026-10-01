<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_latepenalty\local;

/**
 * The deadline and rates that apply to one student in one activity.
 *
 * Produced only by {@see deadline_resolver}. A time of 0 means there is no
 * deadline: either nothing in the chain is set, or an override explicitly
 * removed the due date for the student (the origin then names that override).
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class deadline {
    /**
     * Constructor.
     *
     * @param int $time Deadline timestamp, 0 when there is none.
     * @param string $origin One of the deadline_resolver::ORIGIN_* constants.
     * @param float|null $daily Daily penalty from a Late Penalty override, null to use the rule's.
     * @param float|null $max Penalty cap from a Late Penalty override, null to use the rule's.
     */
    public function __construct(
        /** @var int Deadline timestamp, 0 when there is none. */
        public readonly int $time,
        /** @var string One of the deadline_resolver::ORIGIN_* constants. */
        public readonly string $origin,
        /** @var float|null Daily penalty from a Late Penalty override, null to use the rule's. */
        public readonly ?float $daily = null,
        /** @var float|null Penalty cap from a Late Penalty override, null to use the rule's. */
        public readonly ?float $max = null
    ) {
    }

    /**
     * Whether the student has a deadline at all.
     *
     * @return bool
     */
    public function exists(): bool {
        return $this->time > 0;
    }

    /**
     * Daily penalty that applies, falling back to the rule's.
     *
     * @param \stdClass $rule Rule record (daily_penalty).
     * @return float
     */
    public function daily_for(\stdClass $rule): float {
        return $this->daily ?? (float) $rule->daily_penalty;
    }

    /**
     * Penalty cap that applies, falling back to the rule's.
     *
     * @param \stdClass $rule Rule record (max_penalty).
     * @return float
     */
    public function max_for(\stdClass $rule): float {
        return $this->max ?? (float) $rule->max_penalty;
    }
}
