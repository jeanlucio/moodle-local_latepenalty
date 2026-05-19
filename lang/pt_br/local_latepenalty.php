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

$string['badge_ontime'] = 'Entrega: {$a->date}';
$string['badge_penalty'] = 'Penalidade: {$a->pct}%';
$string['badge_penalty_max'] = 'Penalidade: {$a->pct}% (máx)';
$string['courseinfo_notice'] = 'Esta atividade precisa ser realizada até {$a->deadline}. Uma penalidade de {$a->daily}% será aplicada por dia de atraso até o limite de {$a->max}%.';
$string['courseinfo_notice_overdue'] = 'O prazo de entrega desta atividade venceu em {$a->deadline}. Penalidade acumulada: {$a->pct}% ({$a->daily}% por dia · limite de {$a->max}%).';
$string['courseinfo_notice_overdue_max'] = 'O prazo de entrega desta atividade venceu em {$a->deadline}. Uma penalidade de {$a->max}% (máx) está sendo aplicada.';
$string['error_daily_range'] = 'O desconto diário deve estar entre 0% e 100%';
$string['error_max_less_than_daily'] = 'O desconto diário não pode exceder o limite máximo';
$string['error_max_range'] = 'O limite máximo deve estar entre 0% e 100%';
$string['filter_activity'] = 'Atividade';
$string['filter_all_activities'] = 'Todas as atividades';
$string['filter_all_students'] = 'Todos os alunos';
$string['filter_apply'] = 'Aplicar';
$string['filter_student'] = 'Aluno';
$string['latepenalty'] = 'Penalidade por atraso';
$string['latepenalty_daily'] = 'Desconto por dia de atraso (%)';
$string['latepenalty_enabled'] = 'Habilitar penalidade progressiva?';
$string['latepenalty_max'] = 'Limite máximo de desconto (%)';
$string['latepenalty_recalc_deadline'] = 'Recalcular penalidades ao alterar o prazo de entrega';
$string['latepenalty_recalc_rate'] = 'Recalcular penalidades ao alterar a taxa diária ou o limite máximo';
$string['latepenalty:manageoverrides'] = 'Gerenciar sobreposições de penalidade por atraso por aluno';
$string['latepenalty:viewreport'] = 'Ver relatório de penalidade por atraso';
$string['override_add'] = 'Adicionar sobreposição';
$string['override_col_daily'] = 'Diário (%)';
$string['override_col_deadline'] = 'Prazo';
$string['override_col_max'] = 'Máx (%)';
$string['override_col_student'] = 'Aluno';
$string['override_confirm_delete'] = 'Tem certeza que deseja excluir a sobreposição para {$a}?';
$string['override_daily'] = 'Desconto diário (%)';
$string['override_deadline'] = 'Prazo personalizado';
$string['override_delete'] = 'Excluir';
$string['override_deleted'] = 'Sobreposição excluída com sucesso.';
$string['override_edit'] = 'Editar';
$string['override_empty'] = 'Nenhuma sobreposição foi configurada para esta atividade.';
$string['override_error_nothing_enabled'] = 'Habilite pelo menos um campo para criar uma sobreposição.';
$string['override_hint'] = 'Deixe um campo em branco para herdar o valor configurado na atividade.';
$string['override_inherit'] = 'Padrão da atividade';
$string['override_max'] = 'Limite máximo de desconto (%)';
$string['override_no_students'] = 'Todos os alunos matriculados já possuem uma sobreposição para esta atividade.';
$string['override_saved'] = 'Sobreposição salva com sucesso.';
$string['override_student'] = 'Aluno';
$string['overrides'] = 'Sobreposições de penalidade por atraso';
$string['overrides_for'] = 'Sobreposições de penalidade por atraso: {$a}';
$string['pluginname'] = 'Late Penalty';
$string['privacy:metadata'] = 'O plugin Late Penalty armazena sobreposições de penalidade por aluno na tabela local_latepenalty_overrides. Essas sobreposições podem incluir prazo personalizado, taxa diária e limite máximo configurados pelo professor para um aluno e atividade específicos.';
$string['privacy:metadata:local_latepenalty_overrides'] = 'Sobreposições de prazo e taxa de penalidade por aluno, configuradas pelos professores para atividades específicas.';
$string['privacy:metadata:local_latepenalty_overrides:cmid'] = 'O módulo do curso ao qual esta sobreposição se aplica.';
$string['privacy:metadata:local_latepenalty_overrides:daily_penalty'] = 'Percentual de desconto diário personalizado para este aluno, ou nulo para herdar a regra da atividade.';
$string['privacy:metadata:local_latepenalty_overrides:deadline'] = 'Prazo de entrega personalizado para este aluno, ou nulo para herdar o prazo da atividade.';
$string['privacy:metadata:local_latepenalty_overrides:max_penalty'] = 'Limite máximo de desconto personalizado para este aluno, ou nulo para herdar a regra da atividade.';
$string['privacy:metadata:local_latepenalty_overrides:timecreated'] = 'Data e hora em que esta sobreposição foi criada.';
$string['privacy:metadata:local_latepenalty_overrides:timemodified'] = 'Data e hora da última modificação desta sobreposição.';
$string['privacy:metadata:local_latepenalty_overrides:userid'] = 'ID do aluno ao qual esta sobreposição se aplica.';
$string['report'] = 'Relatório de penalidade por atraso';
$string['report_col_activity'] = 'Atividade';
$string['report_col_date'] = 'Penalidade aplicada';
$string['report_col_deadline'] = 'Prazo';
$string['report_col_discount'] = 'Desconto';
$string['report_col_finalgrade'] = 'Nota final';
$string['report_col_rawgrade'] = 'Nota bruta';
$string['report_col_student'] = 'Aluno';
$string['report_empty'] = 'Nenhuma penalidade por atraso foi aplicada neste curso ainda.';
