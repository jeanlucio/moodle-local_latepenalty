# 🧪 Testes Automatizados

O Late Penalty inclui **81 testes PHPUnit** executados em todo push de CI na matriz completa (Moodle 4.5 → 5.2, PostgreSQL e MariaDB):

| Grupo de testes | Cenários cobertos |
|-----------------|------------------|
| `calculate_days_late()` | Aritmética de timestamps — no prazo, exatamente 1 dia, dias fracionados arredondados para cima |
| `apply_penalty()` | Fórmula de desconto, casos extremos (taxa 0%, limite 100%, nota já em 0) |
| `get_submission_time()` | Fórum sem postagens; tarefa individual; sem entrega; tarefa em grupo (userid = 0); h5pactivity retorna null (fallback por timestamp documentado) |
| Cadeia do observer — Tarefa | Sem regra, regra desabilitada, sem prazo, no prazo, 1 dia, 2 dias, limitado ao máximo, prazo do campo do módulo, penalidade em entrega em grupo |
| Cadeia do observer — Questionário | 1 dia de atraso via `completionexpected` + `quiz_attempts.timefinish` |
| Cadeia do observer — h5pactivity | Atrasado (fallback por timestamp do evento): penalidade aplicada; no prazo: nota inalterada |
| Observer — sobreposições por aluno | Prazo customizado (desloca ou remove atraso), taxa diária customizada, teto customizado, penalidade isenta (taxa = 0), override nulo herda a regra |
| `get_module_user_deadline()` | Extensão assign, override de usuário assign, override de grupo assign, override de usuário quiz, override de usuário lesson, módulo desconhecido → null, sem override → null, integração completa com extensão |
| Helper de sobreposição de grupo | `get_group_override()` — null sem override aplicável, null sem grupo, grupo único, resolução mais favorável (MAX prazo, MIN taxas) entre múltiplos grupos, campos nulos parciais; `get_group_overrides_bulk()` — entrada vazia, valores mesclados por usuário, mais favorável por usuário |
| Recálculo | Prazo estendido reduz penalidade, prazo estendido restaura nota no prazo, mudança de taxa recalcula, aluno no prazo não é afetado |
| Recálculo — sobreposições por aluno | Override de prazo, taxa e teto têm prioridade sobre os novos parâmetros da regra |
| Recálculo — sobreposições de grupo | Override de prazo do grupo aplicado, override por aluno supera o de grupo, `recalculate_for_group()` atualiza todos os membros |
| Recálculo — h5pactivity | Mudança de taxa recalcula penalidade a partir do timestamp do `grade_grades_history` |
| Recálculo — override manual do professor | Nota sobrescrita manualmente não é alterada pelo recálculo |
| Controller de sobreposições | Exibição da lista (estado vazio, nome do aluno e penalidades, sempre exibe botão adicionar); exibição do formulário de adição (sem alunos quando todos já cobertos); salvar adição rejeita aluno não matriculado; salvar edição preserva usuário original; exclusão remove o registro com confirmação, mantém sem confirmação, não afeta override de outro aluno |
| Controller de sobreposições de grupo | Exibição da lista (estado vazio, nome do grupo e penalidades, sempre exibe botão adicionar); exibição do formulário de adição (aviso sem grupos quando todos já cobertos); exclusão remove com confirmação, mantém sem confirmação, não afeta override de outro CM |
| Backup / restauração | A regra acompanha a atividade e é remapeada para o novo módulo do curso (guarda de regressão para resolver o módulo antes de sua instância ser vinculada); sobreposições por aluno e por grupo remapeadas com dados de usuário; regra do curso de origem não afetada pela restauração em um novo curso |

Para executar localmente:

```bash
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit local/latepenalty/tests/observer_test.php
vendor/bin/phpunit local/latepenalty/tests/recalculator_test.php
vendor/bin/phpunit local/latepenalty/tests/penalty_helper_group_test.php
vendor/bin/phpunit local/latepenalty/tests/override/controller_test.php
vendor/bin/phpunit local/latepenalty/tests/group_override/controller_test.php
vendor/bin/phpunit local/latepenalty/tests/backup/restore_test.php
```
