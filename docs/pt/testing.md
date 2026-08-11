# 🧪 Testes Automatizados

O Late Penalty inclui **130 testes PHPUnit** mais uma suíte Behat, executados em todo push de
CI na matriz completa (Moodle 4.5 → 5.2, PostgreSQL e MariaDB).

### PHPUnit (`tests/`)

| Grupo de testes | Cenários cobertos |
|-----------------|------------------|
| `calculate_days_late()` | Aritmética de timestamps — no prazo, exatamente 1 dia, dias fracionados arredondados para cima |
| `apply_penalty()` | Fórmula de desconto, casos extremos (taxa 0%, limite 100%, nota já em 0) |
| `format_deadline()` | Combina a data curta e a hora em 24h do próprio idioma via a string `deadline_datetime` do plugin, em vez de uma ordem de campos fixa no código; guarda estrutural garantindo que o resultado sempre contenha um componente de hora em 24h separado por traço |
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
| Controller de sobreposições | Exibição da lista (estado vazio, nome do aluno e penalidades, um prazo não nulo formatado com data e hora, sempre exibe botão adicionar); exibição do formulário de adição (sem alunos quando todos já cobertos, exclui aluno fora do grupo restrito); salvar adição rejeita aluno não matriculado; salvar edição preserva usuário original; exclusão remove o registro com confirmação, mantém sem confirmação, não afeta override de outro aluno; listagem/edição/exclusão por ID também excluem um override fora do grupo restrito do chamador |
| Controller de sobreposições de grupo | Exibição da lista (estado vazio, nome do grupo e penalidades, um prazo não nulo formatado com data e hora, sempre exibe botão adicionar); exibição do formulário de adição (aviso sem grupos quando todos já cobertos, exclui grupo fora da restrição); exclusão remove com confirmação, mantém sem confirmação, não afeta override de outro CM; listagem exclui um override fora da restrição do chamador |
| Controller de relatório — restrição de grupo | `resolve_group_restriction()`: professor não-editor sem accessallgroups restrito ao próprio grupo, professor editor vê tudo, curso sem grupos separados fica irrestrito, professor sem nenhum grupo restrito a conjunto vazio, modo de grupo no nível da atividade sobrepõe configuração de curso não forçada; a tabela do relatório, seu filtro de estudante e os dados de exportação CSV/Excel respeitam a mesma restrição |
| Resolução de escopo de grupo | `resolve_activity_restriction()` — null quando não está em grupos separados, null para VISIBLEGROUPS, null para chamador com `moodle/site:accessallgroups`, restrito aos próprios grupos do chamador, restrito a um conjunto vazio para chamador sem grupo, `groupmodeforce` no nível do curso sobrepõe a configuração da própria atividade, modo de grupo no nível da atividade se aplica quando o curso não força o próprio |
| Avisos na página do curso | O cmid, prazo e taxa de penalidade de uma atividade oculta são excluídos do payload AMD enviado ao aluno, mesmo a consulta subjacente não filtrando por visibilidade; um professor com `local/latepenalty:viewreport` continua vendo avisos de atividades ocultas; o payload do aviso carrega o prazo formatado com data e hora |
| Privacy provider | Declaração de metadados; `get_contexts_for_userid()` para aluno com e sem override; `get_users_in_context()` incluindo um contexto que não é de módulo; `export_user_data()`; exclusão por contexto, por usuário e por lista de usuários |
| Backup / restauração | A regra acompanha a atividade e é remapeada para o novo módulo do curso (guarda de regressão para resolver o módulo antes de sua instância ser vinculada); sobreposições por aluno e por grupo remapeadas com dados de usuário; regra do curso de origem não afetada pela restauração em um novo curso |

Para executar localmente:

```bash
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit local/latepenalty/tests/observer_test.php
vendor/bin/phpunit local/latepenalty/tests/recalculator_test.php
vendor/bin/phpunit local/latepenalty/tests/penalty_helper_group_test.php
vendor/bin/phpunit local/latepenalty/tests/override/controller_test.php
vendor/bin/phpunit local/latepenalty/tests/group_override/controller_test.php
vendor/bin/phpunit local/latepenalty/tests/report/controller_test.php
vendor/bin/phpunit local/latepenalty/tests/group_scope_test.php
vendor/bin/phpunit local/latepenalty/tests/hook_listener_test.php
vendor/bin/phpunit local/latepenalty/tests/privacy/provider_test.php
vendor/bin/phpunit local/latepenalty/tests/backup/restore_test.php
```

### Behat (`tests/behat/local_latepenalty_access.feature`)

Três cenários comprovam o controle de acesso por capability do plugin de ponta a ponta, em uma
sessão real de navegador:

* um professor editor vê a seção **Late penalty**, incluindo o campo **Enable progressive
  penalty?**, ao editar as configurações de uma tarefa;
* um professor vê o link **Late penalty report** na navegação secundária do curso;
* um estudante **não** vê esse link — o relatório é exclusivo de professores/gestores.

```bash
php admin/tool/behat/cli/init.php
vendor/bin/behat --config /var/www/behatdata/behatrun/behat/behat.yml \
  --tags @local_latepenalty
```

### Cobertura de linhas por classe (PHPUnit + Xdebug, via a ferramenta `moodle-coverage`)

| Classe | Cobertura de linhas |
|--------|:-------------:|
| `group_scope` | 100% |
| `privacy\provider` | 94% |
| `report\controller` | 89% |
| `observer` | 86% |
| `override\controller` | 83% |
| `recalculator` | 82% |
| `group_override\controller` | 72% |
| `hook_listener` | 59% |
| `penalty_helper` | 44% |
| **Total** | **63%** |

> `classes/form/override_form.php` e `classes/form/group_override_form.php` são exercitados
> pelos testes de controller acima (todo cenário de `render_add()`/salvamento os instancia), mas
> não aparecem nesta tabela: o driver de cobertura do Xdebug consistentemente não registra
> nenhum hit de linha para uma subclasse de `moodleform` quando ela é instanciada em um grande
> número de métodos de teste irmãos dentro de uma mesma classe de teste (16 em
> `override\controller_test`, 13 em `group_override\controller_test`) — um artefato da
> ferramenta confirmado ao isolar o mesmo formulário em uma classe de teste menor, não uma
> lacuna real no que os testes exercitam. O número mais baixo de `penalty_helper` é uma lacuna
> genuína e já revisada: seus dois helpers de carregamento em lote (`get_submission_times_bulk()`,
> `get_module_user_deadlines_bulk()`), usados pelo caminho de recálculo em grupo de
> `recalculator.php`, têm apenas o ramo `assign` exercitado pelos testes de
> `recalculate_for_group()` acima — os ramos `quiz`/`workshop`/`forum` e os caminhos de fallback
> por id ausente dentro desses dois grandes `switch` ainda não estão cobertos.
