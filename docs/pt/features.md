# ✨ Funcionalidades

* 📋 **Suporte universal:** Funciona com qualquer tipo de atividade que use o Livro de Notas do Moodle, não apenas Tarefas.
* 📅 **Resolução flexível de prazo:** Resolve o prazo efetivo por uma cadeia de prioridade: sobreposição por aluno do plugin → sobreposição de grupo do plugin → override/extensão nativo do módulo (Tarefa, Questionário, Lição) → `completionexpected` → campo de prazo do módulo (apenas Tarefa e Fórum).
* 👥 **Sobreposições de grupo:** Professores podem definir prazo, taxa diária e limite máximo customizados para grupos inteiros. Quando o aluno pertencer a múltiplos grupos com sobreposições, o valor mais favorável por campo é aplicado de forma independente (prazo mais tardio, menores taxas de penalidade), espelhando o comportamento nativo do Moodle para questionários.
* 📉 **Penalidade diária progressiva:** Percentual configurável por dia de atraso (ex.: 5% ao dia).
* 🔒 **Limite máximo de penalidade:** O desconto nunca excede o teto configurado (ex.: 50% no máximo) e a nota final é sempre ≥ 0.
* 🔄 **Orientado a eventos, sem polling:** Reage a eventos `user_graded` em tempo real — sem cron jobs ou tarefas agendadas.
* 📝 **Histórico de notas:** Toda modificação de nota é registrada na tabela padrão de histórico do Moodle.
* 💾 **Backup e restauração:** As regras de penalidade viajam junto com a atividade no backup, restauração e duplicação de cursos.
* 🔔 **Badge de status dinâmico:** Cada atividade na página do curso exibe um badge contextual — cinza com o prazo quando dentro do tempo, amarelo com a penalidade acumulada quando em atraso, e vermelho ao atingir o limite máximo. O tooltip adapta o texto a cada estado. O badge e o aviso desaparecem automaticamente após o aluno concluir a atividade. Professores veem uma variante específica por papel: para atividades em atraso o badge exibe a taxa de penalidade e a quantidade de estudantes que ainda não enviaram; quando todos os estudantes já entregaram o badge é ocultado.
* 🔁 **Recálculo automático de penalidades:** Quando o professor altera o prazo ou a taxa de penalidade de uma atividade, o plugin pode recalcular e reaplicar automaticamente as penalidades de todos os alunos já penalizados. Dois checkboxes independentes (ambos habilitados por padrão) permitem ao professor controlar se cada tipo de mudança dispara um recálculo.
* 📊 **Relatório de penalidades:** Professores acessam um relatório filtrado por curso com cada ajuste de nota aplicado pelo plugin, com exportação para CSV e Excel com um clique, sempre disponível independentemente do formato de curso.
* 🌐 **Bilíngue:** Suporte completo para inglês e português do Brasil.
