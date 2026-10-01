# 📖 Como Funciona

1. O professor acessa qualquer atividade do Moodle que registre nota.

2. A atividade precisa de um **prazo** para medir o atraso:
   - **Tarefa**, **Fórum** e, a partir do Moodle 5.3, **Questionário** têm uma data de entrega: passada essa data, o estudante continua podendo entregar, só que com atraso. O Late Penalty usa essa data. (Não confundir com a data limite da Tarefa nem com o fechamento do Questionário, que bloqueiam a entrega.)
   - **Todas as outras atividades** (Lição, SCORM, H5P, Glossário, Base de dados, ferramentas externas…): preencha **"Definir lembrete na linha do tempo"** (*Condições de conclusão*). Esse campo não bloqueia nada e serve como prazo da penalidade. Sem ele não há prazo nem penalidade.

3. O professor abre a seção **Penalidade por atraso**, marca **Habilitar penalidade progressiva?** e informa o **Desconto por dia de atraso (%)** e o **Limite máximo de desconto (%)**. Exemplo: 10% ao dia, máximo de 50%.

4. Quando a atividade já existe, a seção mostra a linha **"Prazo usado para a penalidade: <data> (<origem>)"**, ou avisa que não há prazo. Ela mostra o valor salvo; salve o formulário depois de mudar as datas.

5. Um **badge** ao lado da atividade, na página do curso, mostra o prazo e, depois que ele passa, a penalidade acumulada: cinza dentro do prazo, amarelo em atraso, vermelho no máximo. Ele some quando o estudante entrega (envia a tarefa, finaliza uma tentativa, publica no fórum, cria o verbete…), mesmo antes da nota, e não depende da conclusão da atividade: uma condição como "ver" não o esconde. Professores veem uma variação com o número de estudantes que ainda não entregaram.

6. Quando a atividade dá nota a um estudante, o plugin mede o atraso da entrega e desconta a nota.

## Cálculo

1. **Dias de atraso** — contados do prazo até o momento em que o estudante entregou. Cada dia começado conta como um dia inteiro: 1 minuto de atraso já é 1 dia, e 25 horas são 2 dias.
2. **Desconto** — dias de atraso × desconto diário, nunca acima do máximo.
3. **Nota final** — a nota menos o percentual de desconto. A nota nunca fica abaixo do mínimo do item.

**Exemplo** (nota 100, 10% ao dia, máximo de 50%):

| Entrega | Desconto | Nota final |
|---|---|---|
| No prazo | 0% | 100 |
| 1 dia de atraso | 10% | 90 |
| 2 dias de atraso | 20% | 80 |
| 5 dias de atraso ou mais | 50% (máximo) | 50 |

## Qual prazo vale para cada estudante

Vale o primeiro destes que estiver definido:

| Ordem | Prazo | Onde é definido |
|---|---|---|
| 1 | **Sobreposição do Late Penalty** para o estudante | *Sobreposições de penalidade por atraso* (menu de configurações da atividade), aba *Sobreposições de usuário* |
| 2 | **Sobreposição de grupo do Late Penalty** de um grupo do estudante (o valor mais favorável de cada campo entre os grupos) | *Sobreposições de penalidade por atraso*, aba *Sobreposições de grupo* |
| 3 | **Extensão ou sobreposição da própria atividade** | Tarefa: *Atribuir extensão*, depois sobreposição do estudante, depois sobreposição de grupo pela prioridade. Questionário: data de entrega da sobreposição (Moodle 5.3+); senão, o fechamento da sobreposição. Lição: prazo final da sobreposição. |
| 4 | **Data de entrega da atividade** | Tarefa, Fórum e, a partir do Moodle 5.3, Questionário |
| 5 | **"Definir lembrete na linha do tempo"** | Qualquer atividade |

Observações:

* Uma sobreposição de tarefa ou de questionário que **remove** a data de entrega de um estudante significa que ele não tem prazo e nunca é penalizado.
* A extensão da Tarefa vence as sobreposições dela, como na própria Tarefa.
* O fim do envio do Workshop, o fechamento do Questionário e o prazo final da Lição não são usados: eles fecham a atividade, não marcam a entrega como atrasada. Use "Definir lembrete na linha do tempo" nessas atividades.
* O relatório de penalidades mostra o prazo de cada estudante e de onde ele vem.

## Quando o estudante entregou

O plugin usa sempre o momento da ação do próprio estudante, nunca o momento da correção. Professor corrigindo tarde, dissertação corrigida depois ou reavaliação nunca aumentam o atraso.

| Atividade | Momento usado |
|---|---|
| Tarefa | O envio (do próprio estudante, ou o envio do grupo nas tarefas em grupo) |
| Questionário | A tentativa que gerou a nota: primeira, última, mais alta (a mais antiga com essa nota), ou a última tentativa na média |
| Lição | A tentativa que gerou a nota: a primeira sem novas tentativas, a mais alta, ou a última na média |
| Fórum, Glossário, Base de dados com avaliações | O post, verbete ou registro por trás da nota: o de maior avaliação em "Máximo", o de menor em "Mínimo", o último avaliado em média, contagem ou soma |
| Fórum com nota do fórum inteiro | O último post do estudante |
| Workshop | A última alteração do envio |
| Outras atividades (H5P, SCORM, ferramentas externas, outros plugins) | A data de entrega que a atividade informa ao livro de notas; senão, a data em que ela avaliou ou enviou a nota |

No **Glossário** e na **Base de dados**, vale a hora em que o item foi criado. Edições posteriores não contam como atraso; a data da última modificação aparece no próprio item, e o professor pode levá-la em conta ao avaliar.

## Nota mais alta: uma tentativa atrasada nunca baixa a nota

Quando a atividade fica com a maior de várias notas, cada tentativa é descontada pelo seu próprio atraso e fica o melhor resultado. Exemplo, 10% ao dia: 90 no prazo e depois 100 com dois dias de atraso (100 − 20% = 80) → a nota continua **90**.

Isso é automático no Questionário, Lição, SCORM e H5P avaliados pela tentativa mais alta, e no Fórum, Glossário e Base de dados avaliados por "Máximo". Nas **ferramentas externas** e nas **atividades de outros plugins**, cujo método de avaliação o Late Penalty não consegue ler, o formulário oferece **"Não deixar uma nova tentativa atrasada baixar a nota"** (desmarcado por padrão). Com ela, as notas anteriores contam a partir da hora em que chegaram ao livro de notas, porque o histórico do livro de notas não guarda a data de entrega, e a nota nunca passa da que a atividade enviou. Marque quando a atividade fica com a nota mais alta. Se ela usa a última tentativa ou a média, deixe desmarcada: marcada, uma tentativa atrasada pior que uma anterior escaparia do desconto (90 no prazo e depois 60 com dois dias de atraso ficaria 60, e não 48).

## O que nunca é descontado

* **Notas por escala** ("Bom", "Ótimo"…) e atividades sem tipo de nota. Uma porcentagem de uma posição numa escala não tem sentido; o formulário avisa.
* **Tarefas que usam as penalidades de nota do próprio Moodle** (Moodle 5.0+, *Penalidades de nota: Sim* na tarefa). O Late Penalty não age nelas, para a nota nunca ser descontada duas vezes; a seção dele no formulário fica desativada. Notas que ele descontou antes ficam como estão.
* **Notas editadas pelo professor** no livro de notas, e notas ou itens **bloqueados**.
* A **nota de avaliação do Workshop** (como o estudante avaliou os colegas). A nota do envio é descontada.

> **Nota sem entrega:** se o professor der nota a um estudante que nunca entregou nada (um fórum em que ele nunca postou, por exemplo), não há entrega para medir e nenhuma penalidade é aplicada.

## Compatibilidade do aviso na página do curso

O **aviso na página do curso** funciona com qualquer formato de curso que use a renderização padrão de atividades do Moodle (`[data-for="cmitem"]`), o que inclui os formatos nativos **Tópicos**, **Semanal** e **Atividade única**. Formatos que substituem o HTML padrão das atividades podem não exibi-lo. **O cálculo da penalidade, o histórico de notas e o Relatório de Penalidades não são afetados — só o aviso na página do curso.**
