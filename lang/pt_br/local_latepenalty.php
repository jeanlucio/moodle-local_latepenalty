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

/**
 * Strings de idioma em Português Brasileiro para o plugin Late Penalty.
 *
 * @package    local_latepenalty
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
// phpcs:disable moodle.Files.LineLength

$string['courseinfo_notice'] = 'Esta atividade precisa ser realizada até {$a->deadline}. Uma penalidade de {$a->daily}% será aplicada por dia de atraso até o limite de {$a->max}%.';
$string['error_daily_range'] = 'O desconto diário deve estar entre 0% e 100%';
$string['error_max_less_than_daily'] = 'O desconto diário não pode exceder o limite máximo';
$string['error_max_range'] = 'O limite máximo deve estar entre 0% e 100%';
$string['latepenalty'] = 'Penalidade por atraso';
$string['latepenalty_daily'] = 'Desconto por dia de atraso (%)';
$string['latepenalty_enabled'] = 'Habilitar penalidade progressiva?';
$string['latepenalty_max'] = 'Limite máximo de desconto (%)';
$string['pluginname'] = 'Late Penalty';
$string['privacy:metadata'] = 'O plugin Late Penalty não armazena dados pessoais. Ele armazena apenas regras de configuração associadas a atividades do curso.';
