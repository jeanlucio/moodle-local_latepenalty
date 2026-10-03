# 🔁 Recálculo de Penalidades

## Quando a regra ou o prazo muda

Duas caixas na seção Penalidade por atraso (as duas marcadas por padrão) controlam o que acontece quando o professor salva a atividade:

| Caixa | Ao salvar uma mudança… | Efeito |
|---|---|---|
| **Recalcular penalidades ao alterar o prazo de entrega** | no prazo da atividade | As notas já descontadas são recalculadas com o prazo novo |
| **Recalcular penalidades ao alterar a taxa diária ou o limite máximo** | na taxa diária, no máximo ou em "Não deixar uma nova tentativa atrasada baixar a nota" | As notas já descontadas são recalculadas com os valores novos |

* **Um prazo mais tarde** reduz ou remove o desconto.
* **Um prazo mais cedo não é retroativo para quem entregou no prazo:** só quem já estava atrasado recebe desconto maior. Quem entregou dentro do prazo antigo mantém a nota.
* **Remover o prazo** (sem data de entrega e sem "Definir lembrete na linha do tempo") devolve a nota original a todo estudante que ficou sem prazo. Quem tem sobreposição ou extensão própria é recalculado por ela.

## Desativar e ativar a regra

* **Desativar** a regra e salvar devolve as notas originais (o formulário avisa antes de salvar).
* **Ativar de novo** aplica a regra atual a todos os estudantes com nota, inclusive notas dadas enquanto ela estava desligada, e com o prazo como está agora.
* **Ativar pela primeira vez** não muda nenhuma nota existente: só as notas dadas a partir daí são descontadas.
* **Para perdoar um estudante**, dê um prazo mais tarde com uma sobreposição do Late Penalty, ou com a extensão ou sobreposição da própria atividade. Desativar a regra afeta todos.

## Quando muda uma sobreposição ou extensão

Salvar ou apagar qualquer uma destas recalcula na hora os estudantes afetados, tenham sido penalizados antes ou não:

* uma **sobreposição do Late Penalty** (o estudante) ou **sobreposição de grupo** (os membros);
* uma **sobreposição da atividade**: Tarefa, Questionário ou Lição, para um estudante ou grupo;
* uma **extensão da Tarefa** (*Atribuir extensão*).

Um **estudante que entra ou sai de um grupo** ganha ou perde as sobreposições desse grupo, do Late Penalty ou da atividade, e é recalculado na hora nas atividades em que elas mudam o prazo. **Apagar um grupo** recalcula as atividades do curso em segundo plano, logo depois, porque a essa altura os membros já não são conhecidos. Uma **reconfiguração do curso** (*Reconfigurar curso*) que remove grupos ou membros não recalcula nada: as notas do período encerrado ficam como estavam.

## Quando a atividade envia uma nota nova

Uma tentativa nova, uma reavaliação ou uma dissertação corrigida chegam ao plugin como nota nova e são medidas de novo pelas regras acima. Quão rápido o livro de notas mostra isso depende da versão do Moodle, por causa da forma como o desconto é guardado:

| Moodle | Como o desconto é guardado | Uma nota melhor depois de uma penalidade |
|---|---|---|
| **5.1 e 5.2 (atualizados), 5.3+** | No campo de penalidade do próprio Moodle: a nota bruta fica como a atividade enviou, o desconto fica ao lado e o livro de notas mostra *Penalidade por atraso aplicada -N pontos* | Aparece na hora |
| **4.5, 5.0 e versões antigas do 5.1/5.2** | A nota final é gravada como sobrescrita | A sobrescrita esconde a nota nova até a tarefa agendada **Reprocessar notas com penalidade por atraso alteradas pela atividade** rodar (de hora em hora) |

Um curso cujo livro de notas está **congelado** numa versão antiga de cálculo também usa a sobrescrita, porque os recálculos dele ignoram o desconto guardado.

Notas descontadas pelo Late Penalty antes da versão 1.2.0 foram gravadas como sobrescritas e ficam como estão. Para uma delas ser recalculada da forma nova, desmarque *Sobrescrito* nessa nota no relatório do avaliador.

## O que um recálculo nunca altera

* Uma nota **editada pelo professor** no livro de notas, antes ou depois da penalidade.
* Uma nota ou item de nota **bloqueado**.
* Notas **por escala** e tarefas que usam as **penalidades de nota do próprio Moodle**.

## Histórico de notas

O Late Penalty encontra as penalidades que aplicou no histórico de notas do Moodle. Duas configurações do site em *Administração do site > Servidor > Limpar* limitam o que ele consegue encontrar ali:

* **Desabilitar histórico de notas** ligado: nenhuma penalidade pode ser desfeita nem recalculada. Desativar a regra ou mudar o prazo, o desconto por dia ou o limite máximo deixa as notas descontadas como estão, e o relatório de penalidades fica vazio.
* **Tempo de vida do histórico de notas** com um número de dias: o mesmo acontece com as penalidades aplicadas antes desse período.

As configurações da atividade e o relatório de penalidades mostram um aviso enquanto qualquer uma das duas estiver em vigor. Notas editadas por professores continuam protegidas nos dois casos: o Late Penalty nunca altera uma nota que não consegue provar que é dele.
