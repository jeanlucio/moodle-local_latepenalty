# 📊 Relatório de Penalidades

Professores e gestores com a capability `local/latepenalty:viewreport` podem acessar um **Relatório de Penalidades** de cada curso pelo menu de navegação do curso (link **Relatório de penalidade por atraso** no menu secundário).

O relatório exibe cada ajuste de nota aplicado pelo plugin naquele curso:

| Coluna | Descrição |
|--------|-----------|
| **Estudante** | Nome completo do estudante |
| **Atividade** | Nome da atividade avaliada |
| **Prazo** | Prazo resolvido (completionexpected ou campo do módulo) |
| **Nota bruta** | Nota antes da penalidade |
| **Desconto** | Percentual aplicado. Quando uma sobreposição de usuário ou de grupo zera a penalidade, um badge (*Sobreposição de usuário* ou *Sobreposição de grupo*) aparece ao lado do valor 0,0% para explicar a isenção. |
| **Nota final** | Nota após a penalidade |
| **Penalidade aplicada** | Data em que a penalidade foi registrada |

O relatório inclui **filtros** por estudante e por atividade. Somente estudantes e atividades com ao menos uma penalidade registrada aparecem nos filtros — o relatório está sempre disponível independentemente do formato de curso.

## Exportar o relatório

Dois botões de download aparecem no cabeçalho do relatório sempre que há ao menos uma linha. Os filtros ativos de estudante e atividade são preservados na exportação.

| Botão | Formato | Arquivo |
|-------|---------|---------|
| **Baixar CSV** | Valores separados por vírgula | `latepenalty_<sigla>_<data>.csv` |
| **Baixar Excel** | Pasta de trabalho Excel (.xlsx) | `latepenalty_<sigla>_<data>.xlsx` |

A exportação contém uma coluna adicional — **Sobreposição** — que exibe *Sobreposição de usuário* ou *Sobreposição de grupo* (ou fica vazia) para cada linha, facilitando a filtragem de penalidades isentas em uma planilha.
