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
 * Brazilian Portuguese strings for Late Penalty.
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
$string['badge_teacher_pending'] = 'Penalidade: {$a->pct}% · {$a->pending} pendentes';
$string['badge_teacher_pending_max'] = 'Penalidade: {$a->pct}% (máx) · {$a->pending} pendentes';
$string['courseinfo_notice'] = 'Esta atividade precisa ser realizada até {$a->deadline}. Uma penalidade de {$a->daily}% será aplicada por dia de atraso até o limite de {$a->max}%.';
$string['courseinfo_notice_overdue'] = 'O prazo de entrega desta atividade venceu em {$a->deadline}. Penalidade acumulada: {$a->pct}% ({$a->daily}% por dia · limite de {$a->max}%).';
$string['courseinfo_notice_overdue_max'] = 'O prazo de entrega desta atividade venceu em {$a->deadline}. Uma penalidade de {$a->max}% (máx) está sendo aplicada.';
$string['courseinfo_teacher_overdue'] = 'O prazo de entrega desta atividade venceu em {$a->deadline}. Penalidade acumulada: {$a->pct}% ({$a->daily}% por dia · limite de {$a->max}%). {$a->pending} estudante(s) ainda não concluíram.';
$string['courseinfo_teacher_overdue_max'] = 'O prazo de entrega desta atividade venceu em {$a->deadline}. Uma penalidade de {$a->max}% (máx) está sendo aplicada. {$a->pending} estudante(s) ainda não concluíram.';
$string['error_daily_range'] = 'O desconto diário deve estar entre 0% e 100%';
$string['error_max_less_than_daily'] = 'O desconto diário não pode exceder o limite máximo';
$string['error_max_range'] = 'O limite máximo deve estar entre 0% e 100%';
$string['filter_activity'] = 'Atividade';
$string['filter_all_activities'] = 'Todas as atividades';
$string['filter_all_students'] = 'Todos os estudantes';
$string['filter_apply'] = 'Aplicar';
$string['filter_student'] = 'Estudante';
$string['group_override_add'] = 'Adicionar sobreposição de grupo';
$string['group_override_col_group'] = 'Grupo';
$string['group_override_confirm_delete'] = 'Tem certeza que deseja excluir a sobreposição para o grupo {$a}?';
$string['group_override_empty'] = 'Nenhuma sobreposição de grupo foi configurada para esta atividade.';
$string['group_override_error_duplicate'] = 'Este grupo já possui uma sobreposição para esta atividade.';
$string['group_override_group'] = 'Grupo';
$string['group_override_no_groups'] = 'Todos os grupos já possuem uma sobreposição, ou não há grupos neste curso.';
$string['latepenalty'] = 'Penalidade por atraso';
$string['latepenalty:manageoverrides'] = 'Gerenciar sobreposições de penalidade por atraso por estudante';
$string['latepenalty:viewreport'] = 'Ver relatório de penalidade por atraso';
$string['latepenalty_daily'] = 'Desconto por dia de atraso (%)';
$string['latepenalty_enabled'] = 'Habilitar penalidade progressiva?';
$string['latepenalty_max'] = 'Limite máximo de desconto (%)';
$string['latepenalty_recalc_deadline'] = 'Recalcular penalidades ao alterar o prazo de entrega';
$string['latepenalty_recalc_rate'] = 'Recalcular penalidades ao alterar a taxa diária ou o limite máximo';
$string['override_add'] = 'Adicionar sobreposição';
$string['override_col_daily'] = 'Diário (%)';
$string['override_col_deadline'] = 'Prazo';
$string['override_col_max'] = 'Máx (%)';
$string['override_col_student'] = 'Estudante';
$string['override_confirm_delete'] = 'Tem certeza que deseja excluir a sobreposição para {$a}?';
$string['override_daily'] = 'Desconto diário (%)';
$string['override_deadline'] = 'Prazo personalizado';
$string['override_delete'] = 'Excluir';
$string['override_deleted'] = 'Sobreposição excluída com sucesso.';
$string['override_edit'] = 'Editar';
$string['override_empty'] = 'Nenhuma sobreposição foi configurada para esta atividade.';
$string['override_error_duplicate'] = 'Este estudante já possui uma sobreposição para esta atividade.';
$string['override_error_nothing_enabled'] = 'Habilite pelo menos um campo para criar uma sobreposição.';
$string['override_hint'] = 'Deixe um campo em branco para herdar o valor configurado na atividade.';
$string['override_inherit'] = 'Padrão da atividade';
$string['override_max'] = 'Limite máximo de desconto (%)';
$string['override_no_students'] = 'Todos os estudantes matriculados já possuem uma sobreposição para esta atividade.';
$string['override_saved'] = 'Sobreposição salva com sucesso.';
$string['override_student'] = 'Estudante';
$string['overrides'] = 'Sobreposições de penalidade por atraso';
$string['overrides_for'] = 'Sobreposições de penalidade por atraso: {$a}';
$string['overrides_mode_group'] = 'Sobreposições de grupo';
$string['overrides_mode_user'] = 'Sobreposições de usuário';
$string['pluginname'] = 'Penalidade por Atraso';
$string['privacy:metadata'] = 'O plugin Penalidade por Atraso armazena sobreposições de penalidade por estudante na tabela local_latepenalty_overrides. Essas sobreposições podem incluir prazo personalizado, taxa diária e limite máximo configurados pelo professor para um estudante e atividade específicos.';
$string['privacy:metadata:local_latepenalty_overrides'] = 'Sobreposições de prazo e taxa de penalidade por estudante, configuradas pelos professores para atividades específicas.';
$string['privacy:metadata:local_latepenalty_overrides:cmid'] = 'O módulo do curso ao qual esta sobreposição se aplica.';
$string['privacy:metadata:local_latepenalty_overrides:daily_penalty'] = 'Percentual de desconto diário personalizado para este estudante, ou nulo para herdar a regra da atividade.';
$string['privacy:metadata:local_latepenalty_overrides:deadline'] = 'Prazo de entrega personalizado para este estudante, ou nulo para herdar o prazo da atividade.';
$string['privacy:metadata:local_latepenalty_overrides:max_penalty'] = 'Limite máximo de desconto personalizado para este estudante, ou nulo para herdar a regra da atividade.';
$string['privacy:metadata:local_latepenalty_overrides:timecreated'] = 'Data e hora em que esta sobreposição foi criada.';
$string['privacy:metadata:local_latepenalty_overrides:timemodified'] = 'Data e hora da última modificação desta sobreposição.';
$string['privacy:metadata:local_latepenalty_overrides:userid'] = 'ID do estudante ao qual esta sobreposição se aplica.';
$string['report'] = 'Relatório de penalidade por atraso';
$string['report_col_activity'] = 'Atividade';
$string['report_col_date'] = 'Penalidade aplicada';
$string['report_col_deadline'] = 'Prazo';
$string['report_col_discount'] = 'Desconto';
$string['report_col_finalgrade'] = 'Nota final';
$string['report_col_rawgrade'] = 'Nota bruta';
$string['report_col_student'] = 'Estudante';
$string['report_empty'] = 'Nenhuma penalidade por atraso foi aplicada neste curso ainda.';
