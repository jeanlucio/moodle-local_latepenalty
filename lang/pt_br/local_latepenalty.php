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
$string['courseinfo_teacher_overdue'] = 'O prazo de entrega desta atividade venceu em {$a->deadline}. Penalidade acumulada: {$a->pct}% ({$a->daily}% por dia · limite de {$a->max}%). {$a->pending} estudante(s) ainda não entregaram.';
$string['courseinfo_teacher_overdue_max'] = 'O prazo de entrega desta atividade venceu em {$a->deadline}. Uma penalidade de {$a->max}% (máx) está sendo aplicada. {$a->pending} estudante(s) ainda não entregaram.';
$string['deadline_datetime'] = '{$a->date} - {$a->time}';
$string['deadline_none'] = 'Sem prazo: nenhuma penalidade por atraso é aplicada. Defina uma data de entrega ou "Definir lembrete na linha do tempo".';
$string['deadline_origin_activity_close'] = 'Fechamento da sobreposição da atividade';
$string['deadline_origin_activity_override'] = 'Sobreposição da atividade';
$string['deadline_origin_duedate'] = 'Data de entrega';
$string['deadline_origin_exempt'] = '{$a}: sem data de entrega';
$string['deadline_origin_extension'] = 'Extensão';
$string['deadline_origin_none'] = 'Sem prazo';
$string['deadline_origin_plugin_group'] = 'Sobreposição de grupo do Late Penalty';
$string['deadline_origin_plugin_user'] = 'Sobreposição do Late Penalty';
$string['deadline_origin_reminder'] = 'Definir lembrete na linha do tempo';
$string['deadline_used'] = 'Prazo usado para a penalidade: {$a->date} ({$a->origin})';
$string['deadline_used_help'] = 'O prazo que o Late Penalty aplica a esta atividade, como está salvo: a data de entrega dela, quando existe (tarefa, fórum e, a partir do Moodle 5.3, questionário); senão, a data de "Definir lembrete na linha do tempo". Salve o formulário para atualizar esta linha depois de mudar essas datas. Um estudante pode ter outro prazo por sobreposição ou extensão; o relatório do Late Penalty mostra o prazo de cada estudante.';
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
$string['invaliddataformat'] = 'O formato de exportação solicitado "{$a}" não está disponível neste site.';
$string['latepenalty'] = 'Penalidade por atraso';
$string['latepenalty:manageoverrides'] = 'Gerenciar sobreposições de penalidade por atraso por estudante';
$string['latepenalty:viewreport'] = 'Ver relatório de penalidade por atraso';
$string['latepenalty_daily'] = 'Desconto por dia de atraso (%)';
$string['latepenalty_enabled'] = 'Habilitar penalidade progressiva?';
$string['latepenalty_enabled_help'] = 'Desconta uma porcentagem da nota por dia de atraso, até o máximo. Cada dia começado conta como um dia inteiro: 1 minuto depois do prazo já desconta 1 dia, e 1 dia e 2 horas de atraso descontam 2 dias.<br><br>O prazo de cada estudante é o primeiro destes que estiver definido:<ol><li>Sobreposição do Late Penalty para o estudante.</li><li>Sobreposição do Late Penalty para um grupo do estudante.</li><li>Extensão ou sobreposição da própria atividade (tarefa, questionário, lição).</li><li>Data de entrega da atividade (tarefa, fórum e, a partir do Moodle 5.3, questionário).</li><li>Data de "Definir lembrete na linha do tempo".</li></ol>Só notas numéricas são descontadas. Uma nota editada pelo professor nunca é alterada.';
$string['latepenalty_keepbest'] = 'Não deixar uma nova tentativa atrasada baixar a nota';
$string['latepenalty_keepbest_help'] = 'Para atividades com várias tentativas. Marcada, a penalidade de uma nova tentativa atrasada nunca deixa a nota abaixo da melhor nota que o estudante já tinha, já descontada; a nota também nunca passa da que a atividade enviou.<br><br>Conforme o método de avaliação da atividade (exemplos com 10% ao dia):<ul><li><strong>Nota mais alta: marque.</strong> 90 no prazo e depois 95 com dois dias de atraso (95 - 20% = 76): fica 90. Desmarcada, fica 76.</li><li><strong>Última tentativa ou média: deixe desmarcada.</strong> Marcada, uma tentativa atrasada pior que uma anterior escapa do desconto: 90 no prazo e depois 60 com dois dias de atraso fica 60, e não 48.</li><li><strong>Uma só tentativa:</strong> não faz diferença.</li></ul>';
$string['latepenalty_max'] = 'Limite máximo de desconto (%)';
$string['latepenalty_recalc_deadline'] = 'Recalcular penalidades ao alterar o prazo de entrega';
$string['latepenalty_recalc_deadline_help'] = 'Quando o prazo da atividade muda, recalcula as notas já descontadas. Um prazo mais tarde reduz ou remove o desconto; um prazo mais cedo só aumenta o desconto de quem já estava atrasado e nunca penaliza quem entregou no prazo. Remover o prazo devolve a nota original a quem ficou sem prazo. Sobreposições e extensões sempre recalculam o estudante a que se aplicam.';
$string['latepenalty_recalc_rate'] = 'Recalcular penalidades ao alterar a taxa diária ou o limite máximo';
$string['latepenalty_recalc_rate_help'] = 'Quando a penalidade diária, o máximo ou a opção de manter a melhor nota muda, recalcula as notas já descontadas com os valores novos. Desativar a regra devolve as notas originais; ativá-la aplica a regra a todos os estudantes com nota. Para perdoar um estudante, dê um prazo mais tarde por sobreposição ou extensão.';
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
$string['override_notfound'] = 'Esta sobreposição não existe mais ou está fora dos seus grupos.';
$string['override_saved'] = 'Sobreposição salva com sucesso.';
$string['override_student'] = 'Estudante';
$string['overrides'] = 'Sobreposições de penalidade por atraso';
$string['overrides_for'] = 'Sobreposições de penalidade por atraso: {$a}';
$string['overrides_mode_group'] = 'Sobreposições de grupo';
$string['overrides_mode_user'] = 'Sobreposições de usuário';
$string['overrides_rule_disabled'] = 'A regra de penalidade por atraso desta atividade está atualmente desabilitada.';
$string['percent'] = '{$a}%';
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
$string['report_col_deadline_origin'] = 'Origem do prazo';
$string['report_col_discount'] = 'Desconto';
$string['report_col_finalgrade'] = 'Nota final';
$string['report_col_rawgrade'] = 'Nota bruta';
$string['report_col_student'] = 'Estudante';
$string['report_download_csv'] = 'Baixar CSV';
$string['report_download_excel'] = 'Baixar Excel';
$string['report_empty'] = 'Nenhuma penalidade por atraso foi aplicada neste curso ainda.';
$string['report_export_grademax'] = 'Nota máxima';
$string['report_export_override'] = 'Sobreposição';
$string['report_history_disabled'] = 'O histórico de notas está desabilitado neste site (Administração do site > Servidor > Limpar > Desabilitar histórico de notas), então este relatório não consegue listar as penalidades aplicadas.';
$string['report_history_lifetime'] = 'Este site apaga o histórico de notas com mais de {$a} dias (Administração do site > Servidor > Limpar > Tempo de vida do histórico de notas), então este relatório só lista as penalidades aplicadas nos últimos {$a} dias.';
$string['report_override_group'] = 'Sobreposição de grupo';
$string['report_override_user'] = 'Sobreposição de usuário';
$string['task_reprocess_grades'] = 'Reprocessar notas com penalidade por atraso alteradas pela atividade';
$string['unknown'] = 'Desconhecido';
$string['warning_disable_restores'] = 'Ao salvar, as notas descontadas por esta regra voltam ao valor original.';
$string['warning_history_disabled'] = 'O histórico de notas está desabilitado neste site (Administração do site > Servidor > Limpar > Desabilitar histórico de notas). O Late Penalty encontra nesse histórico as penalidades que aplicou, então desativar esta regra ou mudar o prazo, o desconto por dia ou o limite máximo não altera as notas já descontadas.';
$string['warning_history_lifetime'] = 'Este site apaga o histórico de notas com mais de {$a} dias (Administração do site > Servidor > Limpar > Tempo de vida do histórico de notas). O Late Penalty encontra nesse histórico as penalidades que aplicou, então desativar esta regra ou mudar o prazo, o desconto por dia ou o limite máximo não altera as notas descontadas há mais de {$a} dias.';
$string['warning_native_penalty'] = 'Esta tarefa aplica as penalidades de nota do Moodle (Penalidades de nota: Sim), então o Late Penalty não age nela. Notas que o Late Penalty descontou antes ficam como estão.';
$string['warning_scale_notsupported'] = 'O Late Penalty só desconta notas numéricas. Esta atividade é avaliada por escala ou não tem nota, então nenhuma penalidade é aplicada.';
