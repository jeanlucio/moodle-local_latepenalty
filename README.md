# Moodle Local Late Penalty

![Moodle](https://img.shields.io/badge/Moodle-4.5%2B-orange?style=flat&logo=moodle&logoColor=white)
![License](https://img.shields.io/badge/License-GPLv3-blue?style=flat)
![Status](https://img.shields.io/badge/Status-Stable-green?style=flat)
[![Latest Release](https://img.shields.io/github/v/release/jeanlucio/moodle-local_latepenalty?style=flat)](https://github.com/jeanlucio/moodle-local_latepenalty/releases)
[![Author](https://img.shields.io/badge/by-Jean_Lucio-6f42c1?style=flat)](https://github.com/jeanlucio/)

[![Moodle Plugin CI](https://github.com/jeanlucio/moodle-local_latepenalty/actions/workflows/ci.yml/badge.svg)](https://github.com/jeanlucio/moodle-local_latepenalty/actions/workflows/ci.yml)
[![Last Commit](https://img.shields.io/github/last-commit/jeanlucio/moodle-local_latepenalty?style=flat)](https://github.com/jeanlucio/moodle-local_latepenalty/commits)
[![Open Issues](https://img.shields.io/github/issues/jeanlucio/moodle-local_latepenalty?style=flat)](https://github.com/jeanlucio/moodle-local_latepenalty/issues)

[English](#english) | [Português](#português)

---

## English

The **Late Penalty** plugin automatically applies progressive grade deductions to any Moodle
activity when a student submits after the deadline.

Unlike Moodle's native late-submission penalty — which is limited to Assignments — this plugin
listens to the Gradebook's `user_graded` event and works with **every activity type** that
records a grade: Assignments, Quizzes, SCORM, Forums, Lessons, Workshops, and more.

📚 **[Full documentation](https://jeanlucio.github.io/moodle-local_latepenalty/)** — features,
educational purpose, configuration, the deadline priority chain, the penalty report, the full
test suite, and security & privacy details.

### 📦 Requirements

| Component | Version |
|-----------|---------|
| Moodle    | 4.5+    |
| PHP       | 8.1+    |

### 🛠️ Installation & Configuration

1. Download the `.zip` file or clone this repository.
2. Extract the folder into your Moodle `local/` directory.
3. Rename the folder to `latepenalty` (if necessary).
   Final path:
   `your-moodle/local/latepenalty/`
4. Visit **Site administration > Notifications** to complete installation.

A **Late Penalty** section then appears in every activity's settings form, as covered in the
[Configuration](https://jeanlucio.github.io/moodle-local_latepenalty/#configuration) section of
the full documentation.

### 🆘 Support

Found a bug or have a question? Open an issue on the
[issue tracker](https://github.com/jeanlucio/moodle-local_latepenalty/issues).
For general questions or ideas, use [GitHub Discussions](https://github.com/jeanlucio/moodle-local_latepenalty/discussions).

### 📄 License

This project is licensed under the **GNU General Public License v3 (GPLv3)**.

**Copyright:** 2026 Jean Lúcio

### 👤 Maintainer

Maintained by [Jean Lúcio](https://github.com/jeanlucio).

[⬆️ Back to top](#english)

---

## Português

O plugin **Late Penalty** aplica automaticamente descontos progressivos na nota de qualquer
atividade do Moodle quando o aluno entrega após o prazo.

Ao contrário da penalidade de entrega tardia nativa do Moodle — restrita apenas a Tarefas —
este plugin escuta o evento `user_graded` do Livro de Notas e funciona com **qualquer tipo de
atividade** que registra nota: Tarefas, Questionários, SCORM, Fóruns, Lições, Oficinas e muito
mais.

📚 **[Documentação completa](https://jeanlucio.github.io/moodle-local_latepenalty/pt.html)** —
funcionalidades, finalidade educacional, configuração, a cadeia de prioridade de prazo, o
relatório de penalidades, a suíte completa de testes, e detalhes de segurança e privacidade.

### 📦 Requisitos

| Componente | Versão |
|------------|--------|
| Moodle     | 4.5+   |
| PHP        | 8.1+   |

### 🛠️ Instalação e Configuração

1. Baixe o arquivo `.zip` ou clone este repositório.
2. Extraia na pasta `local/` do seu Moodle.
3. Renomeie para `latepenalty` (se necessário).
   Caminho final:
   `seu-moodle/local/latepenalty/`
4. Acesse **Administração do site > Notificações** para concluir a instalação.

Uma seção **Penalidade por Atraso** passa a aparecer no formulário de qualquer atividade,
conforme explicado na seção
[Configuração](https://jeanlucio.github.io/moodle-local_latepenalty/pt.html#configuration) da
documentação completa.

### 🆘 Suporte

Encontrou um bug ou tem alguma dúvida? Abra uma issue no
[rastreador de issues](https://github.com/jeanlucio/moodle-local_latepenalty/issues).
Para perguntas gerais ou ideias, use as [Discussions do GitHub](https://github.com/jeanlucio/moodle-local_latepenalty/discussions).

### 📄 Licença

Este projeto é licenciado sob a **GNU General Public License v3 (GPLv3)**.

**Copyright:** 2026 Jean Lúcio

### 👤 Mantenedor

Mantido por [Jean Lúcio](https://github.com/jeanlucio).

[⬆️ Voltar ao topo](#português)
