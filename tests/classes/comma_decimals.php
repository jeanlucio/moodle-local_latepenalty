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

namespace local_latepenalty\tests;

/**
 * Switches the current language to one whose decimal separator is a comma.
 *
 * Only English is installed in a test site, and the decimal separator comes
 * from langconfig.php, so a language pack holding just that string is written
 * to dataroot, as core's own float element test does (lib/form/tests/float_test.php).
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait comma_decimals {
    /**
     * Use a language with "," as the decimal separator for the rest of the test.
     *
     * @return void
     */
    protected function use_comma_decimals(): void {
        global $CFG, $SESSION;

        $langfolder = $CFG->dataroot . '/lang/xx';
        check_dir_exists($langfolder);
        file_put_contents($langfolder . '/langconfig.php', "<?php\n\$string['decsep'] = ',';\n");
        get_string_manager()->reset_caches();
        $SESSION->lang = 'xx';
        $this->assertSame(',', get_string('decsep', 'langconfig'));
    }
}
