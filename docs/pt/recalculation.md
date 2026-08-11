# 🔁 Recálculo de Penalidades

## Ao Alterar Regra

Quando o professor edita uma atividade e altera o **prazo** ou a **taxa diária / limite máximo**, o plugin pode recalcular e reaplicar automaticamente as penalidades de todos os alunos já penalizados (ou seja, com registro em `grade_grades_history` com `source = 'local_latepenalty'`).

Dois checkboxes independentes aparecem na seção Late Penalty do formulário da atividade (ambos **habilitados por padrão**):

| Checkbox | Comportamento |
|---|---|
| **Recalcular penalidades ao alterar o prazo** | Reaplicar penalidades com o novo prazo sempre que o prazo resolvido mudar |
| **Recalcular penalidades ao alterar a taxa ou limite** | Reaplicar penalidades com os novos valores sempre que a taxa diária ou o limite máximo mudarem |

### Nota

* **Redução de prazo não penaliza alunos que entregaram no prazo original.** Se o prazo for antecipado, alunos que entregaram dentro do prazo *anterior* não tinham penalidade registrada e não serão penalizados retroativamente. O professor deve gerenciar esses casos manualmente.

## Ao Salvar ou Excluir Sobreposição

Quando o professor **cria, edita ou exclui** uma sobreposição por aluno, a nota final do aluno afetado é recalculada imediatamente com o novo prazo efetivo e as novas taxas.

Esse recálculo utiliza um caminho dedicado (`recalculate_for_student()`) que trabalha diretamente com `grade_grades.rawgrade`, independentemente de o aluno já ter sido penalizado pelo plugin. Isso garante o funcionamento correto em dois cenários adicionais:

| Cenário | Como é tratado |
|---|---|
| **Nota definida via restauração de curso** | A restauração grava `source = 'restore'` em `grade_grades_history`. O `recalculate_for_student()` usa o `rawgrade` diretamente de `grade_grades` (não do histórico de penalidades), por isso notas restauradas são atualizadas corretamente. |
| **Sem histórico de penalidade anterior** | Se a nota do aluno nunca foi tocada pelo plugin (por exemplo, a atividade foi adicionada à regra depois que o aluno já havia sido avaliado), o método ainda aplica ou remove a penalidade com base no `rawgrade` atual e no novo prazo efetivo. |

### Proteção contra edição manual do professor

Se o professor editar manualmente a nota de um aluno **após** o plugin ter gravado a penalidade, uma alteração posterior na sobreposição **não** sobrescreverá o valor definido pelo professor. A verificação compara o timestamp mais recente de `local_latepenalty` no histórico com o timestamp mais recente de outras origens — o aluno é ignorado quando a edição do professor for mais recente.

Essa proteção só é ativada quando existe uma gravação anterior do plugin. Quando não há nenhum registro do plugin no histórico, a nota é tratada como o original inalterado e sempre poderá ser recalculada.
