# ✨ Funcionalidades

* 📋 **Suporte universal:** Funciona com qualquer tipo de atividade que use o Livro de Notas do Moodle, não apenas Tarefas.
* 📅 **Uma cadeia de prazo em todo lugar:** sobreposição do Late Penalty → sobreposição de grupo do Late Penalty → extensão ou sobreposição da própria atividade (Tarefa, Questionário, Lição) → data de entrega da atividade (Tarefa, Fórum e, a partir do Moodle 5.3, Questionário) → "Definir lembrete na linha do tempo". A mesma cadeia vale para as notas, os badges do curso, o relatório e o formulário.
* 👥 **Sobreposições de grupo:** Professores podem definir prazo, taxa diária e limite máximo customizados para grupos inteiros. Quando o estudante pertencer a múltiplos grupos com sobreposições, o valor mais favorável por campo é aplicado de forma independente (prazo mais tardio, menores taxas de penalidade), espelhando o comportamento nativo do Moodle para questionários.
* 📉 **Penalidade diária progressiva:** Percentual configurável por dia de atraso (ex.: 5% ao dia).
* 🔒 **Limite máximo de penalidade:** O desconto nunca excede o teto configurado (ex.: 50% no máximo) e a nota final é sempre ≥ 0.
* 🕒 **Atraso da ação do próprio estudante:** a tentativa, post, verbete ou registro por trás da nota, ou a data de entrega que a atividade informa — nunca o momento da correção.
* 🏆 **Nota mais alta respeitada:** quando a atividade fica com a maior nota, uma tentativa atrasada nunca a baixa.
* 🔄 **Orientado a eventos:** reage a eventos `user_graded` em tempo real. No Moodle 5.1+ o desconto fica no campo de penalidade do próprio Moodle e o livro de notas mostra o indicador de penalidade; nas versões anteriores, uma tarefa de hora em hora pega as notas que mudaram depois de uma penalidade.
* ⚖️ **Seguro:** notas por escala nunca são descontadas; tarefas que usam as penalidades de nota do próprio Moodle ficam com elas; notas editadas pelo professor e bloqueadas nunca são alteradas.
* 📝 **Histórico de notas:** Toda modificação de nota é registrada na tabela padrão de histórico do Moodle.
* 💾 **Backup e restauração:** As regras de penalidade viajam junto com a atividade no backup, restauração e duplicação de cursos.
* 🔔 **Badge de status dinâmico:** Cada atividade na página do curso exibe um badge contextual — cinza com o prazo quando dentro do tempo, amarelo com a penalidade acumulada quando em atraso, e vermelho ao atingir o limite máximo. O tooltip adapta o texto a cada estado. O badge e o aviso desaparecem quando o estudante entrega o trabalho, independentemente da conclusão da atividade. Professores veem uma variante específica por papel: para atividades em atraso o badge exibe a taxa de penalidade e a quantidade de estudantes que ainda não enviaram; quando todos os estudantes já entregaram o badge é ocultado.
* 🔁 **Recálculo automático:** mudar o prazo ou as taxas recalcula os estudantes já penalizados (duas caixas, as duas marcadas por padrão). Sobreposições e extensões — do Late Penalty e da própria atividade — recalculam o estudante na hora. Remover o prazo ou desativar a regra devolve as notas originais.
* 📊 **Relatório de penalidades:** relatório filtrável do curso com cada ajuste de nota, o prazo de cada estudante e a origem dele, e exportação para CSV e Excel com um clique.
* 🌐 **Bilíngue:** Suporte completo para inglês e português do Brasil.
