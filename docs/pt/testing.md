# 🧪 Testes Automatizados

O Late Penalty inclui **329 testes PHPUnit** e **14 cenários Behat**, executados em todo push de
CI na matriz completa: Moodle 4.5, 5.0, 5.1, 5.2 e 5.3 (`main`), cada um com PostgreSQL e
MariaDB. Alguns testes só se aplicam onde o core oferece o recurso que verificam (o prazo final
do questionário a partir do 5.3, as notas com dedução a partir da correção da penalidade por
atraso do core) e são pulados nas demais versões.

Todo teste movimenta o outro módulo pela própria API dele — envios, tentativas de questionário,
avaliações, extensões e sobreposições reais —, nunca gravando linhas direto nas tabelas, de
modo que um teste não passa com uma suposição errada sobre onde o módulo guarda os dados.

### PHPUnit (`tests/`)

| Arquivo de teste | O que cobre |
|------------------|-------------|
| `module_assign_test` | Tarefas: envio mais recente, envios em equipe, nova avaliação depois, extensões, reescala |
| `module_quiz_test` | Questionários: tentativa escolhida pelo método de avaliação, avaliação manual, tentativas abandonadas, prazo final (5.3) e data de fechamento |
| `module_lesson_test` | Lições: novas tentativas e a opção "usar a nota máxima" |
| `module_forum_test`, `module_glossary_test`, `module_data_test` | Atividades com avaliação: cada tipo de agregação, vários avaliadores, avaliação do fórum inteiro |
| `module_workshop_test` | Laboratórios de avaliação: só a nota do envio é penalizada, nunca a nota da avaliação |
| `attempt_methods_test` | H5P e SCORM por primeira, última e média das tentativas, com as mesmas regras do questionário |
| `module_generic_test` | Ferramentas externas (LTI 1.1 e 1.3) e H5P: a data de envio informada pelo módulo, ou o momento em que a nota chegou |
| `grade_items_test` | Quais itens de nota são penalizados (só numéricos; nunca escalas, resultados de aprendizagem ou a avaliação do laboratório) |
| `local/deadline_resolver_test` | A cadeia de prazos: sobreposições do plugin, extensões, sobreposições da atividade, data de entrega, conclusão esperada, isenções |
| `local/penalty_writer_test` | Como as penalidades são gravadas, alteradas e removidas — nota com dedução ou nota sobreposta — e as invariantes de toda gravação |
| `keepbest_test` | Atividades com "nota mais alta": uma tentativa atrasada nunca rebaixa um resultado anterior melhor |
| `recalculator_test`, `activity_overrides_test` | Recálculo quando muda uma regra, uma sobreposição ou uma extensão |
| `observer_test`, `penalty_helper_group_test` | A cadeia do evento de nota, sobreposições por estudante e por grupo |
| `lib_test`, `lib_callbacks_test` | A seção do formulário da atividade, o que salvá-la faz, validação e links de navegação |
| `hook_listener_test`, `activity_notice_test` | Avisos na página do curso e na página da atividade, para estudantes e professores |
| `report/controller_test`, `report/deadline_column_test` | O relatório: restrição por grupos, filtros, exportação, cada origem de prazo, número de consultas |
| `override/controller_test`, `group_override/controller_test`, `group_scope_test` | Páginas de sobreposição e restrição por grupos separados |
| `engine_edges_test` | Casos de borda do motor, dos observadores e da tarefa agendada |
| `privacy/provider_test` | Exportação e exclusão da API de Privacidade |
| `backup/restore_test` | Regras e sobreposições no backup e na restauração |
| `upgrade_test` | A atualização a partir da 1.1.x e o passo de instalação |

Rode a suíte inteira num Moodle com o PHPUnit inicializado:

```bash
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite local_latepenalty_testsuite
```

### Behat (`tests/behat/`)

* **`local_latepenalty_access.feature`** — a seção do formulário, o link do relatório para
  professores e a ausência dele para estudantes.
* **`local_latepenalty_penalties.feature`** — um envio atrasado descontado no livro de notas, o
  indicador de penalidade do core, as origens de prazo no relatório, a linha "prazo usado" e a
  ajuda do formulário, atividades avaliadas por escala deixadas de lado, a opção de manter a
  melhor nota, desativar a regra e remover a data de entrega devolvendo as notas originais, o
  plugin se retirando quando a penalidade de tarefas do core está ligada, o prazo final do
  questionário, e páginas de atividade que nunca carregam o livro de notas (revisão do
  questionário, glossário) abrindo normalmente com uma regra ativa.

```bash
php admin/tool/behat/cli/init.php
vendor/bin/behat --config /var/www/behatdata/behatrun/behat/behat.yml --tags @local_latepenalty
```

### Cobertura de linhas por classe (PHPUnit + Xdebug, Moodle 5.1)

| Classe | Cobertura de linhas |
|--------|:-------------------:|
| `group_scope`, `local\deadline`, `observer`, `penalty_helper`, `task\reprocess_grades` | 100% |
| `local\submission_resolver` | 99% |
| `recalculator` | 99% |
| `local\penalty_writer` | 97% |
| `local\deadline_resolver` | 97% |
| `hook_listener` | 95% |
| `report\controller` | 95% |
| `privacy\provider` | 94% |
| `override\controller` | 83% |
| `group_override\controller` | 72% |
| **Total** | **87%** |

> Os dois controllers de sobreposição parecem menos cobertos do que estão: seus formulários
> (`classes/form/override_form.php`, `classes/form/group_override_form.php`) são instanciados em
> todo cenário de adição/salvamento, mas o Xdebug não registra hits de linha de uma subclasse de
> `moodleform` instanciada em muitos métodos de teste da mesma classe de teste — um artefato da
> ferramenta, confirmado ao isolar o mesmo formulário numa classe de teste menor. As linhas
> restantes nas demais classes são retornos defensivos para estados que o core não produz (um
> item de nota cuja atividade não existe mais, por exemplo) e ramos para versões do core
> diferentes da medida.
