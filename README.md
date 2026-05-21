# Moodle Local Late Penalty

[![Moodle Plugin CI](https://github.com/jeanlucio/moodle-local_latepenalty/actions/workflows/ci.yml/badge.svg)](https://github.com/jeanlucio/moodle-local_latepenalty/actions/workflows/ci.yml)
![Moodle](https://img.shields.io/badge/Moodle-4.5%2B-orange?style=flat-square&logo=moodle&logoColor=white)
![License](https://img.shields.io/badge/License-GPLv3-blue?style=flat-square)
![Status](https://img.shields.io/badge/Status-Stable-green?style=flat-square)

[English](#english) | [Português](#português)

---

## English

The **Late Penalty** plugin automatically applies progressive grade deductions to any Moodle activity when a student submits after the deadline.

Unlike Moodle's native late-submission penalty — which is limited to Assignments — this plugin listens to the Gradebook's `user_graded` event and works with **every activity type** that records a grade: Assignments, Quizzes, SCORM, Forums, Lessons, Workshops, and more.

---

### ✨ Features

* 📋 **Universal activity support:** Works with every activity type that uses the Moodle Gradebook, not just Assignments.
* 📅 **Flexible deadline resolution:** Resolves the effective deadline through a priority chain: plugin per-user override → module-native user/group override (Assignment extensions and overrides, Quiz overrides, Lesson overrides) → `completionexpected` → module deadline field (Assignment and Forum only).
* 📉 **Progressive daily penalty:** Configurable percentage deducted per day late (e.g., 5% per day).
* 🔒 **Maximum penalty cap:** Deduction never exceeds the configured cap (e.g., 50% maximum), and the final grade is always ≥ 0.
* 🔄 **Event-driven, zero polling:** Reacts to `user_graded` events in real time — no cron jobs, no scheduled tasks.
* 📝 **Gradebook audit trail:** Every grade modification is recorded in Moodle's standard grade history table.
* 💾 **Backup and restore:** Penalty rules travel with the activity on course backup, restore, and duplication.
* 🔔 **Dynamic status badge:** Each activity on the course page shows a contextual badge — grey with the deadline when on time, yellow with the accumulated penalty when overdue, and red when the maximum is reached. Tooltip text adapts to each state. Badge and notice disappear automatically once the student completes the activity.
* 🔁 **Automatic penalty recalculation:** When a teacher changes the deadline or penalty rate of an activity, the plugin can automatically recalculate and reapply penalties for all students who were already penalised. Two independent checkboxes (both enabled by default) let the teacher control whether each type of change triggers a recalculation.
* 📊 **Penalty report:** Teachers access a filterable course report listing every grade adjustment applied by the plugin, always available regardless of course format.
* 🌐 **Bilingual:** Full support for English and Brazilian Portuguese.

---

### 🎓 Educational Purpose

The Late Penalty plugin is designed to:

* Encourage students to meet deadlines across all activity types
* Give teachers consistent and automated enforcement of late-submission policies
* Provide transparent, auditable grade adjustments visible in the Gradebook history
* Inform students of consequences upfront with the course-page notice

Suitable for:

* Any course with assignment deadlines
* Blended and fully online learning environments
* Courses using a mix of activity types (Quiz + SCORM + Forum, etc.)
* Institutions with a formal late-work policy

---

### 📦 Requirements

| Component | Version |
|-----------|---------|
| Moodle    | 4.5+    |
| PHP       | 8.1+    |

---

### 🛠️ Installation

1. Download the `.zip` file or clone this repository.
2. Extract the folder into your Moodle `local/` directory.
3. Rename the folder to `latepenalty` (if necessary).
   Final path: `your-moodle/local/latepenalty/`
4. Visit **Site administration > Notifications** to complete installation.

---

### ⚙️ Configuration

When creating or editing any activity, a **Late Penalty** section appears in the settings form with three fields:

| Field | Description |
|-------|-------------|
| **Enable progressive penalty** | Activates the rule for this activity |
| **Daily penalty (%)** | Percentage deducted per day late (0–100) |
| **Maximum penalty (%)** | Upper bound for the total deduction (0–100) |

The maximum penalty must be greater than or equal to the daily penalty.

---

### 📖 How It Works

1. Teacher enables the penalty rule and sets the daily rate and cap when creating/editing the activity.
2. Student submits the activity after the deadline.
3. Moodle fires a `user_graded` event; the plugin observer intercepts it.
4. The plugin resolves the **effective deadline** for that student through the following priority chain:
   1. Plugin per-user override (`local_latepenalty_overrides.deadline`)
   2. Module-native user or group override (Assignment extension/override, Quiz override, Lesson override)
   3. `completionexpected` on the course module
   4. Module deadline field (`assign.duedate` or `forum.duedate`) — Assignment and Forum only
5. Days late are calculated and the discount is applied.
6. The adjusted grade is written back to the Gradebook via the standard grade API.
7. On the course page, each affected activity shows a contextual status badge: grey with the deadline when on time, yellow with the accumulated penalty when overdue, or red when the maximum is reached. The tooltip adapts accordingly. Both badge and activity-page notice disappear once the student completes the activity.

> **Note — hard-deadline modules (Quiz, Lesson, SCORM, Workshop, H5P, etc.):** These modules enforce their own close time and physically prevent submissions after it. Using their native close field as a penalty deadline would be meaningless — no student can ever be late relative to it. For these activities, set `completionexpected` (the "Set reminder on timeline" field) to an earlier date: that date becomes the soft penalty deadline, and students can still complete the activity up to the module's hard close time. For universal support, every activity type — including H5P and any future module — can receive a penalty as long as `completionexpected` is configured.

> **Note — manual grading without a submission:** The penalty is based on the student's **submission timestamp**, not on when the teacher grades. If a teacher assigns a grade to a student who never submitted (e.g., a Forum where the student posted nothing), no submission record exists and the plugin skips the penalty entirely. This is by design: without a submission there is no lateness to measure.

> **Note — Assignment team (group) submissions:** When an Assignment is configured for team submissions with *Require all team members to submit* **disabled**, Moodle stores a single submission record for the whole group (`userid = 0`). The plugin automatically detects this case, looks up the student's groups, and uses the **group submission timestamp** as the basis for penalty calculation for every group member. When *Require all team members to submit* is **enabled**, Moodle records an individual submission per member and each student's own submission time is used.

#### Calculation Formula

```
Days Late = ceil((Submission Time − Deadline) / 86400)
Discount   = min(Days Late × Daily Rate, Maximum Cap)
Final Grade = Raw Grade × (1 − Discount / 100)
```

#### Deadline Priority Chain

For each student, the effective deadline is resolved in this order (first match wins):

| Priority | Source | Applies to |
|---|---|---|
| 1 | Plugin per-user override (`local_latepenalty_overrides`) | All modules |
| 2 | Module-native user override | Assignment (`assign_user_flags.extensiondue`, `assign_overrides.duedate`), Quiz (`quiz_overrides.timeclose`), Lesson (`lesson_overrides.deadline`) |
| 3 | `completionexpected` on the course module | All modules |
| 4 | Module deadline field | See table below |

For group overrides at level 2, the **most favourable (latest) deadline** among all of the student's groups is used, mirroring Moodle's native behaviour.

If a teacher sets both a plugin override and a native module override for the same student, the **plugin override takes precedence** (it was set explicitly for penalty purposes).

#### Module Deadline Fields (level 4 fallback)

Only activities whose deadline field is a **soft deadline** — meaning the module does not block submissions after it — are supported at this level.

| Activity   | Deadline field   | Why soft?                                              |
|------------|------------------|--------------------------------------------------------|
| Assignment | `assign.duedate` | Moodle allows late submissions until `cutoffdate`      |
| Forum      | `forum.duedate`  | Calendar display only; posts are never blocked         |

All other activity types (Quiz, Lesson, SCORM, Workshop, H5P, PlayerGroup, etc.) enforce a hard close that prevents any submission after the deadline, so their native deadline field is never used as the penalty deadline. Use `completionexpected` for those activities instead.

---

### 📊 Penalty Report

Teachers and managers with the `local/latepenalty:viewreport` capability can access a **Penalty Report** for each course through the course navigation menu (**Late penalty report** link in the secondary nav).

The report shows every grade adjustment applied by the plugin in that course:

| Column | Description |
|--------|-------------|
| **Student** | Full name of the student |
| **Activity** | Name of the graded activity |
| **Deadline** | Resolved deadline (completionexpected or module field) |
| **Raw grade** | Grade before the penalty |
| **Discount** | Percentage applied |
| **Final grade** | Grade after the penalty |
| **Date applied** | Date the penalty was recorded |

The report includes **filters** for student and activity. Only students and activities that have at least one recorded penalty appear in the filter dropdowns — the report is always available regardless of the course format.

---

### 🔁 Penalty Recalculation on Rule Change

When a teacher edits an activity and changes the **deadline** or the **daily rate / maximum cap**, the plugin can automatically recalculate and reapply late penalties for every student who was already penalised (i.e. has a record in `grade_grades_history` with `source = 'local_latepenalty'`).

Two independent checkboxes appear in the Late Penalty section of the activity form (both **enabled by default**):

| Checkbox | Behaviour |
|---|---|
| **Recalculate penalties when deadline changes** | Reapplies penalties with the new deadline whenever the resolved deadline changes |
| **Recalculate penalties when daily rate or maximum changes** | Reapplies penalties with the new rate/cap whenever either value changes |

#### Note

* **Deadline shortening is not retroactive for on-time students.** If the deadline is moved earlier, students who submitted within the *original* deadline had no penalty recorded and will not be penalised retroactively. The teacher must handle those cases manually.

---

### 🔁 Penalty Recalculation on Override Save / Delete

When a teacher **creates, edits, or deletes** a per-user override, the affected student's final grade is recalculated immediately using the new effective deadline and rates.

This recalculation uses a dedicated path (`recalculate_for_student()`) that works directly from `grade_grades.rawgrade`, independently of whether the student was previously penalised by this plugin. This makes the recalculation work correctly in two additional scenarios:

| Scenario | How it is handled |
|---|---|
| **Grade set via course restore** | Restore writes `source = 'restore'` to `grade_grades_history`. `recalculate_for_student()` uses `rawgrade` from `grade_grades` directly (not from penalty history), so restored grades are updated correctly. |
| **No prior penalty history** | If the student's grade was never touched by this plugin (e.g. the activity was added to the rule after the student was graded), the method still applies or removes the penalty based on the current `rawgrade` and the new effective deadline. |

#### Teacher-edit protection

If a teacher manually edits a student's grade **after** this plugin last wrote it, the subsequent override change will **not** overwrite the teacher's value. The guard compares the most recent `local_latepenalty` history timestamp against the most recent non-plugin history timestamp — the student is skipped when the teacher's edit is newer.

This protection is active only when a prior plugin write exists. When no plugin history entry is found, the grade is treated as the unmodified original and is always eligible for recalculation.

---

The **course-page notice** (the reminder displayed below each activity before a student starts) works with any course format that uses Moodle's standard activity rendering (`[data-for="cmitem"]` on the activity element), which includes the built-in **Topics**, **Weeks**, and **Single Activity** formats.

Third-party formats that replace the standard module HTML with a custom layout (such as visual trail or board formats) may not display the per-activity notice on the course page. **The penalty calculation, grade history, and the Penalty Report are not affected — only the course-page notice display.**

---

### 🧪 Automated Tests

Late Penalty ships with **60 PHPUnit tests** that run on every CI push across the full matrix (Moodle 4.5 → 5.2, PostgreSQL & MariaDB):

| Test group | Scenarios covered |
|------------|------------------|
| `calculate_days_late()` | Timestamp arithmetic — on-time, exactly 1 day, fractional days rounded up |
| `apply_penalty()` | Discount formula, edge cases (0% rate, 100% cap, grade already 0) |
| `get_submission_time()` | Forum no posts; assign individual; assign no submission; assign team (userid = 0); h5pactivity returns null (event-timestamp fallback documented) |
| Observer chain — assign | No rule, disabled rule, no deadline, on-time, 1 day late, 2 days late, capped at max, deadline from module field, team submission penalty |
| Observer chain — quiz | 1 day late via `completionexpected` + `quiz_attempts.timefinish` |
| Observer chain — h5pactivity | Late (event-timestamp fallback): penalty applied; on-time: grade unchanged |
| Observer — per-user overrides | Custom deadline (shifts or removes lateness), custom daily rate, custom max cap, waived penalty (daily = 0), all-null override inherits rule |
| `get_module_user_deadline()` | Assign extension, assign user override, assign group override, quiz user override, lesson user override, unknown module → null, no override → null, full-chain integration with extension |
| Recalculation | Extended deadline reduces penalty, deadline restored on-time grade, rate change recalculates, on-time student untouched |
| Recalculation — per-user overrides | Override deadline, override daily rate, override max cap each take precedence over new rule parameters |
| Recalculation — h5pactivity | Rate change recalculates penalty from `grade_grades_history` timestamp |
| Recalculation — teacher override | Manually overridden grade is not touched by recalculation |
| Override controller | Render list (empty state, student name and penalties, always includes add button); render add (no students when all covered); save add rejects unenrolled user; save edit preserves original user; delete removes record on confirm, leaves record without confirm, does not affect foreign override |

Run them locally with:

```bash
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit local/latepenalty/tests/observer_test.php
vendor/bin/phpunit local/latepenalty/tests/recalculator_test.php
vendor/bin/phpunit local/latepenalty/tests/override/controller_test.php
```

---

### 🔐 Security & Compliance

* Capability-based access via Moodle's standard form API
* `require_sesskey()` protection on all POST actions
* No SQL string interpolation — parameterised queries throughout
* Grade writes use the official Moodle grade API (`update_final_grade`)
* Anti-recursion guard prevents the grade event from re-triggering the observer infinitely

---

### 🔒 Privacy

This plugin does **not** store any personal user data.

The only data written is:

* **Penalty rule configuration** — stored per course-module (activity), not per user.
* **Grade modifications** — recorded in Moodle's standard `grade_grades_history` table, which is managed by Moodle core.

The Privacy Provider (`classes/privacy/provider.php`) declares this plugin as data-free for user exports and deletions.

---

## 📄 License

This project is licensed under the **GNU General Public License v3 (GPLv3)**.

**Copyright:** 2026 Jean Lúcio

---

## Português

O plugin **Late Penalty** aplica automaticamente descontos progressivos na nota de qualquer atividade do Moodle quando o aluno entrega após o prazo.

Ao contrário da penalidade de entrega tardia nativa do Moodle — restrita apenas a Tarefas — este plugin escuta o evento `user_graded` do Livro de Notas e funciona com **qualquer tipo de atividade** que registra nota: Tarefas, Questionários, SCORM, Fóruns, Lições, Oficinas e muito mais.

---

### ✨ Funcionalidades

* 📋 **Suporte universal:** Funciona com qualquer tipo de atividade que use o Livro de Notas do Moodle, não apenas Tarefas.
* 📅 **Resolução flexível de prazo:** Resolve o prazo efetivo por uma cadeia de prioridade: sobreposição por aluno do plugin → override/extensão nativo do módulo (Tarefa, Questionário, Lição) → `completionexpected` → campo de prazo do módulo (apenas Tarefa e Fórum).
* 📉 **Penalidade diária progressiva:** Percentual configurável por dia de atraso (ex.: 5% ao dia).
* 🔒 **Limite máximo de penalidade:** O desconto nunca excede o teto configurado (ex.: 50% no máximo) e a nota final é sempre ≥ 0.
* 🔄 **Orientado a eventos, sem polling:** Reage a eventos `user_graded` em tempo real — sem cron jobs ou tarefas agendadas.
* 📝 **Histórico de notas:** Toda modificação de nota é registrada na tabela padrão de histórico do Moodle.
* 💾 **Backup e restauração:** As regras de penalidade viajam junto com a atividade no backup, restauração e duplicação de cursos.
* 🔔 **Badge de status dinâmico:** Cada atividade na página do curso exibe um badge contextual — cinza com o prazo quando dentro do tempo, amarelo com a penalidade acumulada quando em atraso, e vermelho ao atingir o limite máximo. O tooltip adapta o texto a cada estado. O badge e o aviso desaparecem automaticamente após o aluno concluir a atividade.
* 🔁 **Recálculo automático de penalidades:** Quando o professor altera o prazo ou a taxa de penalidade de uma atividade, o plugin pode recalcular e reaplicar automaticamente as penalidades de todos os alunos já penalizados. Dois checkboxes independentes (ambos habilitados por padrão) permitem ao professor controlar se cada tipo de mudança dispara um recálculo.
* 📊 **Relatório de penalidades:** Professores acessam um relatório filtrado por curso com cada ajuste de nota aplicado pelo plugin, sempre disponível independentemente do formato de curso.
* 🌐 **Bilíngue:** Suporte completo para inglês e português do Brasil.

---

### 🎓 Finalidade Educacional

O plugin Late Penalty foi projetado para:

* Estimular os alunos a cumprirem prazos em qualquer tipo de atividade
* Dar aos professores uma aplicação consistente e automatizada de políticas de entrega tardia
* Fornecer ajustes de nota transparentes e auditáveis, visíveis no histórico do Livro de Notas
* Informar os alunos antecipadamente sobre as consequências por meio do aviso na página do curso

Indicado para:

* Qualquer curso com prazos de atividades
* Ambientes de aprendizado semipresencial e totalmente online
* Cursos com combinação de tipos de atividade (Questionário + SCORM + Fórum, etc.)
* Instituições com política formal de trabalhos entregues com atraso

---

### 📦 Requisitos

| Componente | Versão |
|------------|--------|
| Moodle     | 4.5+   |
| PHP        | 8.1+   |

---

### 🛠️ Instalação

1. Baixe o arquivo `.zip` ou clone este repositório.
2. Extraia na pasta `local/` do seu Moodle.
3. Renomeie para `latepenalty` (se necessário).
   Caminho final: `seu-moodle/local/latepenalty/`
4. Acesse **Administração do site > Notificações** para concluir a instalação.

---

### ⚙️ Configuração

Ao criar ou editar qualquer atividade, uma seção **Penalidade por Atraso** aparece no formulário com três campos:

| Campo | Descrição |
|-------|-----------|
| **Habilitar penalidade progressiva** | Ativa a regra para esta atividade |
| **Desconto diário (%)** | Percentual descontado por dia de atraso (0–100) |
| **Desconto máximo (%)** | Limite superior para o desconto total (0–100) |

O desconto máximo deve ser maior ou igual ao desconto diário.

---

### 📖 Como Funciona

1. O professor ativa a regra e define o desconto diário e o limite máximo ao criar/editar a atividade.
2. O aluno entrega a atividade após o prazo.
3. O Moodle dispara um evento `user_graded`; o observer do plugin o intercepta.
4. O plugin resolve o **prazo efetivo** para aquele aluno pela seguinte cadeia de prioridade:
   1. Sobreposição por aluno do plugin (`local_latepenalty_overrides.deadline`)
   2. Override/extensão nativo do módulo por usuário ou grupo (extensão/override de Tarefa, override de Questionário, override de Lição)
   3. `completionexpected` no módulo de curso
   4. Campo de prazo do módulo (`assign.duedate` ou `forum.duedate`) — apenas Tarefa e Fórum
5. Os dias de atraso são calculados e o desconto é aplicado.
6. A nota ajustada é registrada de volta no Livro de Notas via API padrão de notas.
7. Na página do curso, cada atividade afetada exibe um badge de status contextual: cinza com o prazo quando no tempo, amarelo com a penalidade acumulada quando em atraso, ou vermelho ao atingir o máximo. O tooltip adapta o texto a cada estado. O badge e o aviso na página da atividade desaparecem após o aluno concluir a atividade.

> **Observação — módulos com prazo rígido (Questionário, Lição, SCORM, Oficina, H5P, etc.):** Esses módulos encerram o acesso no horário configurado e impedem fisicamente qualquer entrega após esse momento. Usar o campo de prazo nativo deles como prazo de penalidade seria inútil — nenhum aluno consegue entregar com atraso em relação a ele. Para essas atividades, configure `completionexpected` (o campo "Definir lembrete na linha do tempo") com uma data anterior: essa data se torna o prazo soft de penalidade, e o aluno ainda pode concluir a atividade até o encerramento rígido do módulo. Para suporte universal, qualquer tipo de atividade — incluindo H5P e qualquer módulo futuro — pode receber penalidade desde que `completionexpected` esteja configurado.

> **Observação — avaliação sem entrega:** A penalidade é baseada no **timestamp de entrega do aluno**, não no momento em que o professor avalia. Se um professor atribuir nota a um aluno que nunca entregou (ex.: Fórum em que o aluno não fez nenhuma postagem), não existe registro de entrega e o plugin ignora a penalidade. Isso é intencional: sem entrega, não há atraso a medir.

> **Observação — Tarefas com entrega em grupo:** Quando uma Tarefa é configurada para entregas em grupo com *Exigir que todos os membros do grupo façam a entrega* **desativado**, o Moodle registra uma única entrega para o grupo inteiro (`userid = 0`). O plugin detecta automaticamente esse caso, identifica os grupos do aluno e usa o **timestamp de entrega do grupo** como base para o cálculo da penalidade de todos os membros. Quando a opção está **ativada**, o Moodle registra uma entrega individual por membro e o timestamp de cada aluno é usado.

#### Fórmula de Cálculo

```
Dias de Atraso = ceil((Hora da Entrega − Prazo) / 86400)
Desconto       = min(Dias de Atraso × Taxa Diária, Limite Máximo)
Nota Final     = Nota Bruta × (1 − Desconto / 100)
```

#### Cadeia de Prioridade de Prazo

Para cada aluno, o prazo efetivo é resolvido nesta ordem (o primeiro que corresponder é usado):

| Prioridade | Fonte | Aplica-se a |
|---|---|---|
| 1 | Sobreposição por aluno do plugin (`local_latepenalty_overrides`) | Todos os módulos |
| 2 | Override nativo do módulo por usuário | Tarefa (`assign_user_flags.extensiondue`, `assign_overrides.duedate`), Questionário (`quiz_overrides.timeclose`), Lição (`lesson_overrides.deadline`) |
| 3 | `completionexpected` no módulo de curso | Todos os módulos |
| 4 | Campo de prazo do módulo | Ver tabela abaixo |

Para overrides de grupo no nível 2, o **prazo mais favorável (mais tardio)** entre todos os grupos do aluno é utilizado, espelhando o comportamento nativo do Moodle.

Se o professor configurar tanto um override do plugin quanto um override nativo do módulo para o mesmo aluno, o **override do plugin tem prioridade** (foi configurado explicitamente para fins de penalidade).

#### Campos de Prazo dos Módulos (fallback nível 4)

Somente atividades cujo campo de prazo é um **prazo soft** — ou seja, o módulo não bloqueia entregas após ele — são suportadas neste nível.

| Atividade | Campo de prazo   | Por que é soft?                                               |
|-----------|------------------|---------------------------------------------------------------|
| Tarefa    | `assign.duedate` | O Moodle permite entregas tardias até a `cutoffdate`          |
| Fórum     | `forum.duedate`  | Apenas exibição no calendário; postagens nunca são bloqueadas |

Todos os demais tipos de atividade (Questionário, Lição, SCORM, Oficina, H5P, PlayerGroup, etc.) impõem um encerramento rígido que impede qualquer entrega após o prazo, portanto o campo de prazo nativo nunca é usado como prazo de penalidade. Use `completionexpected` para essas atividades.

---

### 📊 Relatório de Penalidades

Professores e gestores com a capability `local/latepenalty:viewreport` podem acessar um **Relatório de Penalidades** de cada curso pelo menu de navegação do curso (link **Relatório de penalidade por atraso** no menu secundário).

O relatório exibe cada ajuste de nota aplicado pelo plugin naquele curso:

| Coluna | Descrição |
|--------|-----------|
| **Aluno** | Nome completo do aluno |
| **Atividade** | Nome da atividade avaliada |
| **Prazo** | Prazo resolvido (completionexpected ou campo do módulo) |
| **Nota bruta** | Nota antes da penalidade |
| **Desconto** | Percentual aplicado |
| **Nota final** | Nota após a penalidade |
| **Data aplicada** | Data em que a penalidade foi registrada |

O relatório inclui **filtros** por aluno e por atividade. Somente alunos e atividades com ao menos uma penalidade registrada aparecem nos filtros — o relatório está sempre disponível independentemente do formato de curso.

---

### 🔁 Recálculo de Penalidades ao Alterar Regra

Quando o professor edita uma atividade e altera o **prazo** ou a **taxa diária / limite máximo**, o plugin pode recalcular e reaplicar automaticamente as penalidades de todos os alunos já penalizados (ou seja, com registro em `grade_grades_history` com `source = 'local_latepenalty'`).

Dois checkboxes independentes aparecem na seção Late Penalty do formulário da atividade (ambos **habilitados por padrão**):

| Checkbox | Comportamento |
|---|---|
| **Recalcular penalidades ao alterar o prazo** | Reaplicar penalidades com o novo prazo sempre que o prazo resolvido mudar |
| **Recalcular penalidades ao alterar a taxa ou limite** | Reaplicar penalidades com os novos valores sempre que a taxa diária ou o limite máximo mudarem |

#### Nota

* **Redução de prazo não penaliza alunos que entregaram no prazo original.** Se o prazo for antecipado, alunos que entregaram dentro do prazo *anterior* não tinham penalidade registrada e não serão penalizados retroativamente. O professor deve gerenciar esses casos manualmente.

---

### 🔁 Recálculo de Penalidades ao Salvar ou Excluir Sobreposição

Quando o professor **cria, edita ou exclui** uma sobreposição por aluno, a nota final do aluno afetado é recalculada imediatamente com o novo prazo efetivo e as novas taxas.

Esse recálculo utiliza um caminho dedicado (`recalculate_for_student()`) que trabalha diretamente com `grade_grades.rawgrade`, independentemente de o aluno já ter sido penalizado pelo plugin. Isso garante o funcionamento correto em dois cenários adicionais:

| Cenário | Como é tratado |
|---|---|
| **Nota definida via restauração de curso** | A restauração grava `source = 'restore'` em `grade_grades_history`. O `recalculate_for_student()` usa o `rawgrade` diretamente de `grade_grades` (não do histórico de penalidades), por isso notas restauradas são atualizadas corretamente. |
| **Sem histórico de penalidade anterior** | Se a nota do aluno nunca foi tocada pelo plugin (por exemplo, a atividade foi adicionada à regra depois que o aluno já havia sido avaliado), o método ainda aplica ou remove a penalidade com base no `rawgrade` atual e no novo prazo efetivo. |

#### Proteção contra edição manual do professor

Se o professor editar manualmente a nota de um aluno **após** o plugin ter gravado a penalidade, uma alteração posterior na sobreposição **não** sobrescreverá o valor definido pelo professor. A verificação compara o timestamp mais recente de `local_latepenalty` no histórico com o timestamp mais recente de outras origens — o aluno é ignorado quando a edição do professor for mais recente.

Essa proteção só é ativada quando existe uma gravação anterior do plugin. Quando não há nenhum registro do plugin no histórico, a nota é tratada como o original inalterado e sempre poderá ser recalculada.

---

O **aviso na página do curso** (o lembrete exibido abaixo de cada atividade antes de o aluno começar) funciona com qualquer formato de curso que utilize a renderização padrão de atividades do Moodle (`[data-for="cmitem"]` no elemento da atividade), o que inclui os formatos nativos **Tópicos**, **Semanas** e **Atividade Única**.

Formatos de terceiros que substituem o HTML padrão dos módulos por um layout próprio (como formatos visuais de trilha ou quadro) podem não exibir o aviso por atividade na página do curso. **O cálculo da penalidade, o histórico de notas e o Relatório de Penalidades não são afetados — apenas a exibição do aviso na página do curso.**

---

### 🧪 Testes Automatizados

O Late Penalty inclui **60 testes PHPUnit** executados em todo push de CI na matriz completa (Moodle 4.5 → 5.2, PostgreSQL e MariaDB):

| Grupo de testes | Cenários cobertos |
|-----------------|------------------|
| `calculate_days_late()` | Aritmética de timestamps — no prazo, exatamente 1 dia, dias fracionados arredondados para cima |
| `apply_penalty()` | Fórmula de desconto, casos extremos (taxa 0%, limite 100%, nota já em 0) |
| `get_submission_time()` | Fórum sem postagens; tarefa individual; sem entrega; tarefa em grupo (userid = 0); h5pactivity retorna null (fallback por timestamp documentado) |
| Cadeia do observer — Tarefa | Sem regra, regra desabilitada, sem prazo, no prazo, 1 dia, 2 dias, limitado ao máximo, prazo do campo do módulo, penalidade em entrega em grupo |
| Cadeia do observer — Questionário | 1 dia de atraso via `completionexpected` + `quiz_attempts.timefinish` |
| Cadeia do observer — h5pactivity | Atrasado (fallback por timestamp do evento): penalidade aplicada; no prazo: nota inalterada |
| Observer — sobreposições por aluno | Prazo customizado (desloca ou remove atraso), taxa diária customizada, teto customizado, penalidade isenta (taxa = 0), override nulo herda a regra |
| `get_module_user_deadline()` | Extensão assign, override de usuário assign, override de grupo assign, override de usuário quiz, override de usuário lesson, módulo desconhecido → null, sem override → null, integração completa com extensão |
| Recálculo | Prazo estendido reduz penalidade, prazo estendido restaura nota no prazo, mudança de taxa recalcula, aluno no prazo não é afetado |
| Recálculo — sobreposições por aluno | Override de prazo, taxa e teto têm prioridade sobre os novos parâmetros da regra |
| Recálculo — h5pactivity | Mudança de taxa recalcula penalidade a partir do timestamp do `grade_grades_history` |
| Recálculo — override manual do professor | Nota sobrescrita manualmente não é alterada pelo recálculo |
| Controller de sobreposições | Exibição da lista (estado vazio, nome do aluno e penalidades, sempre exibe botão adicionar); exibição do formulário de adição (sem alunos quando todos já cobertos); salvar adição rejeita aluno não matriculado; salvar edição preserva usuário original; exclusão remove o registro com confirmação, mantém sem confirmação, não afeta override de outro aluno |

Para executar localmente:

```bash
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit local/latepenalty/tests/observer_test.php
vendor/bin/phpunit local/latepenalty/tests/recalculator_test.php
vendor/bin/phpunit local/latepenalty/tests/override/controller_test.php
```

---

### 🔐 Segurança e Conformidade

* Controle de acesso baseado em capabilities via API padrão de formulários do Moodle
* Proteção com `require_sesskey()` em todas as ações POST
* Sem interpolação de strings SQL — consultas parametrizadas em todo o código
* Gravações de nota via API oficial de notas do Moodle (`update_final_grade`)
* Proteção anti-recursão que impede o evento de nota de re-acionar o observer infinitamente

---

### 🔒 Privacidade

Este plugin **não armazena** nenhum dado pessoal de usuário.

Os únicos dados gravados são:

* **Configuração da regra de penalidade** — armazenada por módulo de curso (atividade), não por usuário.
* **Modificações de nota** — registradas na tabela padrão `grade_grades_history` do Moodle, gerenciada pelo núcleo do Moodle.

O Privacy Provider (`classes/privacy/provider.php`) declara este plugin como livre de dados para exportações e exclusões de usuários.

---

## 📄 Licença

Este projeto é licenciado sob a **GNU General Public License v3 (GPLv3)**.

**Copyright:** 2026 Jean Lúcio
