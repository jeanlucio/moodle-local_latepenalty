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
* 📅 **Flexible deadline resolution:** Resolves the effective deadline through a priority chain: plugin per-user override → plugin group override → module-native user/group override (Assignment extensions and overrides, Quiz overrides, Lesson overrides) → `completionexpected` → module deadline field (Assignment and Forum only).
* 👥 **Group overrides:** Teachers can set a custom deadline, daily rate, and maximum cap for entire groups. When a student belongs to multiple groups with overrides, the most lenient value per field is applied independently (latest deadline, lowest penalty rates), mirroring Moodle's native quiz behaviour.
* 📉 **Progressive daily penalty:** Configurable percentage deducted per day late (e.g., 5% per day).
* 🔒 **Maximum penalty cap:** Deduction never exceeds the configured cap (e.g., 50% maximum), and the final grade is always ≥ 0.
* 🔄 **Event-driven, zero polling:** Reacts to `user_graded` events in real time — no cron jobs, no scheduled tasks.
* 📝 **Gradebook audit trail:** Every grade modification is recorded in Moodle's standard grade history table.
* 💾 **Backup and restore:** Penalty rules travel with the activity on course backup, restore, and duplication.
* 🔔 **Dynamic status badge:** Each activity on the course page shows a contextual badge — grey with the deadline when on time, yellow with the accumulated penalty when overdue, and red when the maximum is reached. Tooltip text adapts to each state. Badge and notice disappear automatically once the student completes the activity. Teachers see a role-specific variant: for overdue activities the badge shows the penalty rate plus the number of students who have not yet submitted; when all students have submitted the badge is hidden entirely.
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

1. The teacher opens any Moodle activity that has a grade and completion conditions.

2. The teacher sets a **submission deadline** for the activity, which serves as the reference point for penalty calculation:
   - **Assignment** and **Forum**: have a native due date field (do not confuse with the Assignment cut-off date, which blocks submissions and prevents penalty calculation).
   - **Quiz, Lesson, SCORM, and all other activities**: do not have a deadline that allows late submission. For these, the **"Set reminder on timeline"** field (*Completion conditions* tab) is **required** — it does not block submissions and serves solely as the penalty reference date. Without it configured, there is no deadline and no penalty is applied.

3. The teacher then opens the **Late Penalty** section and enables **Enable progressive penalty**.

4. The teacher enters the **daily penalty (%)** and the **maximum penalty (%)**. Example: 10% daily penalty with a 50% cap → the system deducts 10% of the student's achieved grade per day late, up to a maximum of 50%, regardless of how many days pass after that point.

5. When the activity is saved, a **badge** appears next to the activity name showing the deadline. After the deadline, if the student has not yet completed the activity, the badge switches to show the accumulated penalty. The badge has contextual status: grey with the deadline when on time, yellow with the accumulated penalty when overdue, and red when the maximum is reached. The tooltip adapts to each state. The badge and the activity-page notice disappear automatically once the student completes the activity. **Teachers see a different badge for overdue activities:** it shows the penalty rate plus how many students have not yet submitted. When all students have submitted, the badge is hidden — there is nothing actionable left to show.

6. When a student submits after the deadline and a grade is assigned (manually by the teacher or automatically), the plugin calculates and applies the penalty.

7. If a **deadline override** is set for a specific student, it takes precedence over other configurations. The priority order is:
   - **Plugin per-user override** — accessed via *Penalty overrides* inside the activity. Highest priority.
   - **Plugin group override** — accessed via *Group penalty overrides* inside the activity. When the student belongs to multiple groups, the most lenient value per field is used.
   - **Module-native override** — Assignment (extension/override), Quiz (override), and Lesson (override) have their own fields, checked next.
   - **"Set reminder on timeline"** (`completionexpected`) — applies to any activity type.
   - **Native deadline field** — Assignment and Forum only, as a final fallback.

8. Days late are calculated and the discount is applied.

9. The adjusted grade is written back to the Gradebook via the standard grade API.

> **Note — manual grading without a submission:** The penalty is based on the student's submission timestamp, not on when the teacher grades. If a teacher assigns a grade to a student who never submitted (e.g., a Forum where the student posted nothing), no submission record exists and the plugin skips the penalty entirely. This is by design: without a submission there is no lateness to measure.

> **Note — Assignment team (group) submissions:** When an Assignment is configured for team submissions with *Require all team members to submit* **disabled**, Moodle stores a single submission record for the whole group (`userid = 0`). The plugin automatically detects this case, looks up the student's groups, and uses the **group submission timestamp** as the basis for penalty calculation for every group member. When *Require all team members to submit* is **enabled**, Moodle records an individual submission per member and each student's own submission time is used.

#### Calculation

1. **Days late** — counted from the moment of submission. Any fraction of a day counts as a full day (rounded up). Example: submitted 25 hours after the deadline = 2 days late.
2. **Discount** — days late × daily rate, capped at the maximum penalty.
3. **Final grade** — the raw grade reduced by the discount percentage.

**Example** (raw grade: 100 | daily penalty: 10% | cap: 50%):

| Submission | Discount | Final grade |
|---|---|---|
| On time | 0% | 100 |
| 1 day late | 10% | 90 |
| 2 days late | 20% | 80 |
| 3 days late | 30% | 70 |
| 4 days late | 40% | 60 |
| 5+ days late | 50% (cap) | 50 |

#### Deadline Priority Chain

For each student, the effective deadline is resolved in this order (first match wins):

| Priority | Source | Applies to |
|---|---|---|
| 1 | Plugin per-user override (`local_latepenalty_overrides`) | All modules |
| 2 | Plugin group override (`local_latepenalty_group_overrides`) — most lenient value per field across all of the student's groups | All modules |
| 3 | Module-native user/group override | Assignment (`assign_user_flags.extensiondue`, `assign_overrides.duedate`), Quiz (`quiz_overrides.timeclose`), Lesson (`lesson_overrides.deadline`) |
| 4 | `completionexpected` on the course module | All modules |
| 5 | Module deadline field | See table below |

For module-native overrides at level 3, the **most favourable (latest) deadline** among all of the student's groups is used, mirroring Moodle's native behaviour.

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

Late Penalty ships with **78 PHPUnit tests** that run on every CI push across the full matrix (Moodle 4.5 → 5.2, PostgreSQL & MariaDB):

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
| Group override helper | `get_group_override()` — null when no applicable override, null when user in no group, single group, most-lenient resolution (MAX deadline, MIN rates) across multiple groups, partial null fields; `get_group_overrides_bulk()` — empty input, per-user merged values, most-lenient per user |
| Recalculation | Extended deadline reduces penalty, deadline restored on-time grade, rate change recalculates, on-time student untouched |
| Recalculation — per-user overrides | Override deadline, override daily rate, override max cap each take precedence over new rule parameters |
| Recalculation — group overrides | Group override deadline applied, user override beats group override, `recalculate_for_group()` updates all group members |
| Recalculation — h5pactivity | Rate change recalculates penalty from `grade_grades_history` timestamp |
| Recalculation — teacher override | Manually overridden grade is not touched by recalculation |
| Override controller | Render list (empty state, student name and penalties, always includes add button); render add (no students when all covered); save add rejects unenrolled user; save edit preserves original user; delete removes record on confirm, leaves record without confirm, does not affect foreign override |
| Group override controller | Render list (empty state, group name and penalties, always includes add button); render add (no groups notice when all covered); delete removes record on confirm, leaves record without confirm, does not affect foreign-CM override |

Run them locally with:

```bash
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit local/latepenalty/tests/observer_test.php
vendor/bin/phpunit local/latepenalty/tests/recalculator_test.php
vendor/bin/phpunit local/latepenalty/tests/penalty_helper_group_test.php
vendor/bin/phpunit local/latepenalty/tests/override/controller_test.php
vendor/bin/phpunit local/latepenalty/tests/group_override/controller_test.php
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
* 📅 **Resolução flexível de prazo:** Resolve o prazo efetivo por uma cadeia de prioridade: sobreposição por aluno do plugin → sobreposição de grupo do plugin → override/extensão nativo do módulo (Tarefa, Questionário, Lição) → `completionexpected` → campo de prazo do módulo (apenas Tarefa e Fórum).
* 👥 **Sobreposições de grupo:** Professores podem definir prazo, taxa diária e limite máximo customizados para grupos inteiros. Quando o aluno pertencer a múltiplos grupos com sobreposições, o valor mais favorável por campo é aplicado de forma independente (prazo mais tardio, menores taxas de penalidade), espelhando o comportamento nativo do Moodle para questionários.
* 📉 **Penalidade diária progressiva:** Percentual configurável por dia de atraso (ex.: 5% ao dia).
* 🔒 **Limite máximo de penalidade:** O desconto nunca excede o teto configurado (ex.: 50% no máximo) e a nota final é sempre ≥ 0.
* 🔄 **Orientado a eventos, sem polling:** Reage a eventos `user_graded` em tempo real — sem cron jobs ou tarefas agendadas.
* 📝 **Histórico de notas:** Toda modificação de nota é registrada na tabela padrão de histórico do Moodle.
* 💾 **Backup e restauração:** As regras de penalidade viajam junto com a atividade no backup, restauração e duplicação de cursos.
* 🔔 **Badge de status dinâmico:** Cada atividade na página do curso exibe um badge contextual — cinza com o prazo quando dentro do tempo, amarelo com a penalidade acumulada quando em atraso, e vermelho ao atingir o limite máximo. O tooltip adapta o texto a cada estado. O badge e o aviso desaparecem automaticamente após o aluno concluir a atividade. Professores veem uma variante específica por papel: para atividades em atraso o badge exibe a taxa de penalidade e a quantidade de estudantes que ainda não enviaram; quando todos os estudantes já entregaram o badge é ocultado.
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

#### Como a penalidade é calculada

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

#### Cadeia de Prioridade de Prazo

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

O Late Penalty inclui **78 testes PHPUnit** executados em todo push de CI na matriz completa (Moodle 4.5 → 5.2, PostgreSQL e MariaDB):

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
| Helper de sobreposição de grupo | `get_group_override()` — null sem override aplicável, null sem grupo, grupo único, resolução mais favorável (MAX prazo, MIN taxas) entre múltiplos grupos, campos nulos parciais; `get_group_overrides_bulk()` — entrada vazia, valores mesclados por usuário, mais favorável por usuário |
| Recálculo | Prazo estendido reduz penalidade, prazo estendido restaura nota no prazo, mudança de taxa recalcula, aluno no prazo não é afetado |
| Recálculo — sobreposições por aluno | Override de prazo, taxa e teto têm prioridade sobre os novos parâmetros da regra |
| Recálculo — sobreposições de grupo | Override de prazo do grupo aplicado, override por aluno supera o de grupo, `recalculate_for_group()` atualiza todos os membros |
| Recálculo — h5pactivity | Mudança de taxa recalcula penalidade a partir do timestamp do `grade_grades_history` |
| Recálculo — override manual do professor | Nota sobrescrita manualmente não é alterada pelo recálculo |
| Controller de sobreposições | Exibição da lista (estado vazio, nome do aluno e penalidades, sempre exibe botão adicionar); exibição do formulário de adição (sem alunos quando todos já cobertos); salvar adição rejeita aluno não matriculado; salvar edição preserva usuário original; exclusão remove o registro com confirmação, mantém sem confirmação, não afeta override de outro aluno |
| Controller de sobreposições de grupo | Exibição da lista (estado vazio, nome do grupo e penalidades, sempre exibe botão adicionar); exibição do formulário de adição (aviso sem grupos quando todos já cobertos); exclusão remove com confirmação, mantém sem confirmação, não afeta override de outro CM |

Para executar localmente:

```bash
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit local/latepenalty/tests/observer_test.php
vendor/bin/phpunit local/latepenalty/tests/recalculator_test.php
vendor/bin/phpunit local/latepenalty/tests/penalty_helper_group_test.php
vendor/bin/phpunit local/latepenalty/tests/override/controller_test.php
vendor/bin/phpunit local/latepenalty/tests/group_override/controller_test.php
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
