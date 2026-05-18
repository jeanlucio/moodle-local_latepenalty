# Comparativo: `gradepenalty_duedate` (core) × `local_latepenalty`

Análise realizada em maio de 2026 com base no código-fonte do Moodle 5.2.

---

## Arquitetura

| Aspecto | Core (`gradepenalty_duedate`) | `local_latepenalty` |
|---|---|---|
| Tipo | Framework extensível (tipo de plugin `gradepenalty`) | Plugin local único |
| Trigger | Módulo chama `penalty_manager::apply_grade_penalty_to_user()` explicitamente ao corrigir | Evento `user_graded` automático — funciona em qualquer módulo |
| Extensível | Sim — múltiplos plugins `gradepenalty` podem empilhar penalidades | Não |

---

## Cobertura de módulos

| Módulo | Core | `local_latepenalty` |
|---|---|---|
| Assign | ✅ (único com `FEATURE_GRADE_HAS_PENALTY`) | ✅ |
| Quiz | ❌ | ✅ |
| Workshop | ❌ | ✅ |
| Forum | ❌ | ✅ |
| Lesson | ❌ | ✅ |
| Scorm | ❌ | ✅ |
| Qualquer módulo com `completionexpected` | ❌ | ✅ |

---

## Lógica de cálculo

| Aspecto | Core | `local_latepenalty` |
|---|---|---|
| Modelo | **Faixas por segundos de atraso** — tabela de regras `(overdueby_seg, penalty%)` | **Taxa diária progressiva** — `dias × daily%`, com teto em `max%` |
| Base do desconto | Percentual do **grademax** (pontos fixos independente da nota do aluno) | Percentual da **nota atual do aluno** |
| Exemplo (nota 60/100, 20% de penalidade) | Desconta 20 pts (100 × 20%) → nota final 40 | Desconta 12 pts (60 × 20%) → nota final 48 |
| Granularidade das regras | Faixas arbitrárias (horas, dias, semanas) por nível de contexto | Um par `daily%` / `max%` por atividade |

---

## Hierarquia de configuração

| Nível | Core | `local_latepenalty` |
|---|---|---|
| Sistema (padrão global) | ✅ Admin define regras que valem para todo o site | ❌ |
| Curso (override) | ✅ Coordenador sobrescreve para o curso | ❌ |
| Atividade (override) | ✅ | ✅ (único nível disponível) |

---

## Casos especiais — gaps do `local_latepenalty`

| Situação | Core | `local_latepenalty` | Risco |
|---|---|---|---|
| **Prazo individual via override** (professor dá extensão a um aluno específico no Assign) | ✅ Lê `override_exists()` e `extensionduedate` | ❌ Ignora — penaliza com prazo geral | Alto — injustiça com o aluno |
| **Nota manualmente sobrescrita** pelo professor no livro de notas | ✅ Pula (`grade->overridden`) | ❌ Aplica penalidade sobre a nota sobrescrita | Médio |
| **Nota bloqueada** (`locked`) | ✅ Pula | ❌ Tenta aplicar | Baixo — grade_item recusa o update |
| **Submissão em grupo** (team submission do Assign) | ✅ Usa `get_group_submission()` | ❌ Não tratado | Médio (se usado) |
| **Nota zero** (grademin) | ✅ Pula | ⚠️ Aplica, mas 0 × X = 0 (inócuo) | Nenhum |
| **Preview** (calcular sem aplicar) | ✅ | ❌ | Funcionalidade ausente |

---

## Experiência do professor e do aluno

| | Core | `local_latepenalty` |
|---|---|---|
| Badge dinâmico no curso | ❌ | ✅ Neutro / amarelo / vermelho conforme estado |
| Aviso contextual na atividade | ❌ | ✅ Com prazo, taxa e acumulado |
| Ocultação após conclusão | ❌ | ✅ Badge e aviso somem após entrega |
| Indicador de penalidade no livro de notas | ✅ (`penalty_indicator`) | ❌ |
| Relatório de penalidades | ❌ | ✅ Com filtros por aluno e atividade |
| Recálculo ao mudar prazo | ✅ Task assíncrona | ✅ Síncrono ao salvar o formulário |
| Recálculo ao mudar taxa diária / teto | ❌ | ✅ |

---

## Integridade da nota

| | Core | `local_latepenalty` |
|---|---|---|
| Método de gravação | `update_raw_grade()` — modifica rawgrade | `update_final_grade()` — modifica apenas finalgrade |
| Restauração ao tornar-se pontual | ❌ | ✅ Rawgrade recuperado pelo recalculator |
| Guarda o valor descontado explicitamente | ✅ `grade_grades.deductedmark` | ❌ |

---

## Síntese

O sistema do core foi projetado para **robustez institucional**: hierarquia de regras, respeito a overrides individuais e integridade de grade para notas sobrescritas ou bloqueadas. Em contrapartida, cobre apenas o Assign e exige que cada módulo implemente o contrato `FEATURE_GRADE_HAS_PENALTY` explicitamente.

`local_latepenalty` cobre um espectro muito maior de módulos, oferece feedback visual rico ao aluno e um relatório para o professor, com um modelo de cálculo progressivo mais intuitivo. Os gaps relevantes são:

1. **Overrides individuais** — o mais crítico; pode penalizar alunos que receberam extensão pelo sistema de overrides do Assign.
2. **Notas manualmente sobrescritas** — penalidade aplicada sobre nota que o professor já ajustou manualmente.
3. **Submissões em grupo** — não tratado; risco proporcional ao uso de team submissions.
