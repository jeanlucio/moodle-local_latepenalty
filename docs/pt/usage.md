# 📖 Como Funciona

1. O professor acessa qualquer atividade do Moodle que possua nota e condições de conclusão.

2. O professor define uma **data de entrega** para a atividade, que servirá como referência para o cálculo da penalidade:
   - **Tarefa** e **Fórum**: possuem campo nativo de data de entrega (não confundir com a data limite da Tarefa, que bloqueia o envio e impede o cálculo da penalidade).
   - **Questionário, Lição, SCORM e demais atividades**: não possuem prazo que permita entrega tardia. Para essas, é **obrigatório** usar o campo **"Definir lembrete na linha do tempo"** (aba *Condições de Conclusão*) — esse campo não impede a entrega e funciona como prazo de referência exclusivamente para o cálculo da penalidade. Sem ele configurado, não há prazo e a penalidade não é aplicada.

3. Em seguida, o professor acessa a aba **Penalidade por Atraso** e marca a opção **Habilitar penalidade progressiva**.

4. O professor informa o **percentual de desconto diário** e o **desconto máximo**. Exemplo: 10% de desconto diário com limite de 50% → o sistema desconta 10% da nota alcançada por dia de atraso, até o máximo de 50%, independentemente de quantos dias se passem depois disso.

5. Ao salvar a atividade, um **badge** aparece ao lado do nome exibindo o prazo de entrega. Após o prazo, caso o aluno ainda não tenha concluído, o badge passa a mostrar a penalidade acumulada. O badge possui status contextual: cinza com o prazo quando dentro do tempo, amarelo com a penalidade acumulada quando em atraso, e vermelho ao atingir o limite máximo. O tooltip adapta o texto a cada estado. O badge e o aviso na página da atividade desaparecem após o aluno concluir a atividade. **Professores veem um badge diferente para atividades em atraso:** ele exibe a taxa de penalidade e quantos estudantes ainda não enviaram. Quando todos os estudantes já entregaram, o badge é ocultado — não há nada acionável a exibir.

6. Quando o aluno entrega após o prazo e a nota é atribuída (manualmente pelo professor ou automaticamente), o plugin calcula a penalidade e a aplica.

7. Se houver uma **sobreposição de prazo** registrada para um aluno específico, ela tem prioridade sobre as demais configurações. A ordem é:
   - **Sobreposição por aluno do plugin** — acessada em *Sobreposição de penalidades*, dentro da atividade. Tem a maior prioridade.
   - **Sobreposição de grupo do plugin** — acessada em *Sobreposições de grupo de penalidades*, dentro da atividade. Quando o aluno pertencer a múltiplos grupos, o valor mais favorável por campo é utilizado.
   - **Sobreposição nativa do módulo** — Tarefa (extensão/override), Questionário (override) e Lição (override) possuem campos próprios consultados em seguida.
   - **"Definir lembrete na linha do tempo"** — válido para qualquer tipo de atividade.
   - **Campo de prazo nativo** — apenas Tarefa e Fórum, como último recurso.

8. Os dias de atraso são calculados e o desconto é aplicado.

9. A nota ajustada é registrada de volta no Livro de Notas via API padrão de notas.

> **Observação — avaliação sem entrega:** A penalidade é baseada na data de entrega do aluno, não no momento em que o professor avalia. Se um professor atribuir nota a um aluno que nunca entregou (ex.: Fórum em que o aluno não fez nenhuma postagem), não existe registro de entrega e o plugin ignora a penalidade. Isso é intencional: sem entrega, não há atraso a medir.

> **Observação — Tarefas com entrega em grupo:** Quando uma Tarefa é configurada para entregas em grupo com *Exigir que todos os membros do grupo façam a entrega* **desativado**, o Moodle registra uma única entrega para o grupo inteiro (`userid = 0`). O plugin detecta automaticamente esse caso, identifica os grupos do aluno e usa a data de entrega do grupo como base para o cálculo da penalidade de todos os membros. Quando a opção está **ativada**, o Moodle registra uma entrega individual por membro e a data de entrega de cada aluno é usada.

## Como a penalidade é calculada

1. **Dias de atraso** — contados a partir do momento da entrega. Qualquer fração de dia conta como um dia completo (arredondado para cima). Exemplo: entregou 25 horas depois do prazo = 2 dias de atraso.
2. **Desconto** — dias de atraso × percentual diário, respeitando o limite máximo.
3. **Nota final** — a nota bruta reduzida pelo percentual de desconto.

**Exemplo** (nota bruta: 100 | desconto diário: 10% | limite: 50%):

| Entrega | Desconto | Nota final |
|---|---|---|
| No prazo | 0% | 100 |
| 1 dia de atraso | 10% | 90 |
| 2 dias de atraso | 20% | 80 |
| 3 dias de atraso | 30% | 70 |
| 4 dias de atraso | 40% | 60 |
| 5+ dias de atraso | 50% (limite) | 50 |

## Cadeia de Prioridade de Prazo

Para cada aluno, o prazo efetivo é resolvido nesta ordem (o primeiro que corresponder é usado):

| Prioridade | Fonte | Aplica-se a |
|---|---|---|
| 1 | Sobreposição por aluno do plugin (`local_latepenalty_overrides`) | Todos os módulos |
| 2 | Sobreposição de grupo do plugin (`local_latepenalty_group_overrides`) — valor mais favorável por campo entre todos os grupos do aluno | Todos os módulos |
| 3 | Sobreposição nativa do módulo por usuário/grupo | Tarefa (`assign_user_flags.extensiondue`, `assign_overrides.duedate`), Questionário (`quiz_overrides.timeclose`), Lição (`lesson_overrides.deadline`) |
| 4 | `completionexpected` no módulo de curso | Todos os módulos |
| 5 | Campo de prazo do módulo | Ver tabela abaixo |

Para sobreposições nativas no nível 3, o **prazo mais favorável (mais tardio)** entre todos os grupos do aluno é utilizado, espelhando o comportamento nativo do Moodle.

Se o professor configurar tanto uma sobreposição do plugin quanto uma sobreposição nativa do módulo para o mesmo aluno, a **sobreposição do plugin tem prioridade** (foi configurada explicitamente para fins de penalidade).

## Campos de Prazo dos Módulos (fallback nível 4)

Somente atividades cujo campo de prazo é um **prazo soft** — ou seja, o módulo não bloqueia entregas após ele — são suportadas neste nível.

| Atividade | Campo de prazo   | Por que é soft?                                               |
|-----------|------------------|-----------------------------------------------------------------|
| Tarefa    | `assign.duedate` | O Moodle permite entregas tardias até a `cutoffdate`          |
| Fórum     | `forum.duedate`  | Apenas exibição no calendário; postagens nunca são bloqueadas |

Todos os demais tipos de atividade (Questionário, Lição, SCORM, Oficina, H5P, PlayerGroup, etc.) impõem um encerramento rígido que impede qualquer entrega após o prazo, portanto o campo de prazo nativo nunca é usado como prazo de penalidade. Use `completionexpected` para essas atividades.

## Compatibilidade do Aviso na Página do Curso

O **aviso na página do curso** (o lembrete exibido abaixo de cada atividade antes de o aluno começar) funciona com qualquer formato de curso que utilize a renderização padrão de atividades do Moodle (`[data-for="cmitem"]` no elemento da atividade), o que inclui os formatos nativos **Tópicos**, **Semanas** e **Atividade Única**.

Formatos de terceiros que substituem o HTML padrão dos módulos por um layout próprio (como formatos visuais de trilha ou quadro) podem não exibir o aviso por atividade na página do curso. **O cálculo da penalidade, o histórico de notas e o Relatório de Penalidades não são afetados — apenas a exibição do aviso na página do curso.**
