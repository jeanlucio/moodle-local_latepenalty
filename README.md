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
* 📅 **Flexible deadline resolution:** Uses the `completionexpected` date on the course module first; falls back to the module-specific deadline field (`duedate`, `timeclose`, `deadline`, `submissionend`).
* 📉 **Progressive daily penalty:** Configurable percentage deducted per day late (e.g., 5% per day).
* 🔒 **Maximum penalty cap:** Deduction never exceeds the configured cap (e.g., 50% maximum), and the final grade is always ≥ 0.
* 🔄 **Event-driven, zero polling:** Reacts to `user_graded` events in real time — no cron jobs, no scheduled tasks.
* 📝 **Gradebook audit trail:** Every grade modification is recorded in Moodle's standard grade history table.
* 💾 **Backup and restore:** Penalty rules travel with the activity on course backup, restore, and duplication.
* 🔔 **Course page notice:** Students see a reminder below each activity showing the deadline and penalty terms before they start.
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
4. The plugin resolves the deadline (from `completionexpected` or the module's own deadline field).
5. Days late are calculated and the discount is applied.
6. The adjusted grade is written back to the Gradebook via the standard grade API.
7. On the course page, a notice is displayed below each affected activity so students know the penalty terms.

#### Calculation Formula

```
Days Late = ceil((Submission Time − Deadline) / 86400)
Discount   = min(Days Late × Daily Rate, Maximum Cap)
Final Grade = Raw Grade × (1 − Discount / 100)
```

#### Supported Activity Types and Deadline Fields

| Activity    | Deadline field resolved      |
|-------------|------------------------------|
| Assignment  | `duedate`                    |
| Forum       | `duedate`                    |
| Lesson      | `deadline`                   |
| Quiz        | `timeclose`                  |
| SCORM       | `timeclose`                  |
| Workshop    | `submissionend`              |
| PlayerGroup | `timeclose`                  |
| Any type    | `completionexpected` (priority) |

---

### 🧪 Automated Tests

Late Penalty ships with **22 PHPUnit unit tests** that run on every CI push across the full matrix (Moodle 4.5 → 5.2, PostgreSQL & MariaDB):

| Test group | Scenarios covered |
|------------|------------------|
| `calculate_days_late()` | Timestamp arithmetic — on-time, exactly 1 day, fractional days rounded up |
| `apply_penalty()` | Discount formula, edge cases (0% rate, 100% cap, grade already 0) |
| `get_submission_time()` | Forum with no posts returns null; assignment with submission returns timestamp |
| Observer chain | No rule, disabled rule, no deadline, on-time (no change), 1 day late, 2 days late, penalty capped at max |

Run them locally with:

```bash
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit local/latepenalty/tests/observer_test.php
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
* 📅 **Resolução flexível de prazo:** Usa primeiro a data de `completionexpected` do módulo de curso; depois recorre ao campo de prazo específico do módulo (`duedate`, `timeclose`, `deadline`, `submissionend`).
* 📉 **Penalidade diária progressiva:** Percentual configurável por dia de atraso (ex.: 5% ao dia).
* 🔒 **Limite máximo de penalidade:** O desconto nunca excede o teto configurado (ex.: 50% no máximo) e a nota final é sempre ≥ 0.
* 🔄 **Orientado a eventos, sem polling:** Reage a eventos `user_graded` em tempo real — sem cron jobs ou tarefas agendadas.
* 📝 **Histórico de notas:** Toda modificação de nota é registrada na tabela padrão de histórico do Moodle.
* 💾 **Backup e restauração:** As regras de penalidade viajam junto com a atividade no backup, restauração e duplicação de cursos.
* 🔔 **Aviso na página do curso:** Os alunos veem um lembrete abaixo de cada atividade com o prazo e as condições da penalidade antes de começar.
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
4. O plugin resolve o prazo (a partir de `completionexpected` ou do campo de prazo do módulo).
5. Os dias de atraso são calculados e o desconto é aplicado.
6. A nota ajustada é registrada de volta no Livro de Notas via API padrão de notas.
7. Na página do curso, um aviso é exibido abaixo de cada atividade afetada para que os alunos conheçam as condições da penalidade.

#### Fórmula de Cálculo

```
Dias de Atraso = ceil((Hora da Entrega − Prazo) / 86400)
Desconto       = min(Dias de Atraso × Taxa Diária, Limite Máximo)
Nota Final     = Nota Bruta × (1 − Desconto / 100)
```

#### Tipos de Atividade Suportados e Campos de Prazo

| Atividade   | Campo de prazo resolvido      |
|-------------|-------------------------------|
| Tarefa      | `duedate`                     |
| Fórum       | `duedate`                     |
| Lição       | `deadline`                    |
| Questionário | `timeclose`                  |
| SCORM       | `timeclose`                   |
| Oficina     | `submissionend`               |
| PlayerGroup | `timeclose`                   |
| Qualquer    | `completionexpected` (prioridade) |

---

### 🧪 Testes Automatizados

O Late Penalty inclui **22 testes PHPUnit** executados em todo push de CI na matriz completa (Moodle 4.5 → 5.2, PostgreSQL e MariaDB):

| Grupo de testes | Cenários cobertos |
|-----------------|------------------|
| `calculate_days_late()` | Aritmética de timestamps — no prazo, exatamente 1 dia, dias fracionados arredondados para cima |
| `apply_penalty()` | Fórmula de desconto, casos extremos (taxa 0%, limite 100%, nota já em 0) |
| `get_submission_time()` | Fórum sem postagens retorna null; Tarefa com entrega retorna timestamp |
| Cadeia do observer | Sem regra, regra desabilitada, sem prazo, no prazo (sem alteração), 1 dia de atraso, 2 dias de atraso, penalidade limitada ao máximo |

Para executar localmente:

```bash
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit local/latepenalty/tests/observer_test.php
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
