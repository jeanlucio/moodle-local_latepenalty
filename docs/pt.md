---
layout: default
title: Documentação do Late Penalty
lang: pt
---

![Moodle](https://img.shields.io/badge/Moodle-4.5%2B-orange?style=flat&logo=moodle&logoColor=white)
![License](https://img.shields.io/badge/License-GPLv3-blue?style=flat)
![Status](https://img.shields.io/badge/Status-Stable-green?style=flat)
[![Latest Release](https://img.shields.io/github/v/release/jeanlucio/moodle-local_latepenalty?style=flat)](https://github.com/jeanlucio/moodle-local_latepenalty/releases)
[![Author](https://img.shields.io/badge/by-Jean_Lucio-6f42c1?style=flat)](https://github.com/jeanlucio/)

[![Moodle Plugin CI](https://github.com/jeanlucio/moodle-local_latepenalty/actions/workflows/ci.yml/badge.svg)](https://github.com/jeanlucio/moodle-local_latepenalty/actions/workflows/ci.yml)
[![Last Commit](https://img.shields.io/github/last-commit/jeanlucio/moodle-local_latepenalty?style=flat)](https://github.com/jeanlucio/moodle-local_latepenalty/commits)
[![Open Issues](https://img.shields.io/github/issues/jeanlucio/moodle-local_latepenalty?style=flat)](https://github.com/jeanlucio/moodle-local_latepenalty/issues)

O plugin **Late Penalty** aplica automaticamente descontos progressivos na nota de qualquer
atividade do Moodle quando o aluno entrega após o prazo. Ao contrário da penalidade de entrega
tardia nativa do Moodle — restrita apenas a Tarefas — este plugin escuta o evento `user_graded`
do Livro de Notas e funciona com qualquer tipo de atividade que registra nota.

<p class="page-hint">👈 Use a barra lateral para pular direto a qualquer seção desta página.</p>

---

<span id="features"></span>
{% include_relative pt/features.md %}

<span id="educational-purpose"></span>
{% include_relative pt/educational-purpose.md %}

<span id="requirements"></span>
{% include_relative pt/requirements.md %}

<span id="installation"></span>
{% include_relative pt/installation.md %}

<span id="configuration"></span>
{% include_relative pt/configuration.md %}

<span id="usage"></span>
{% include_relative pt/usage.md %}

<span id="report"></span>
{% include_relative pt/report.md %}

<span id="recalculation"></span>
{% include_relative pt/recalculation.md %}

<span id="testing"></span>
{% include_relative pt/testing.md %}

<span id="security"></span>
{% include_relative pt/security.md %}

<span id="privacy"></span>
{% include_relative pt/privacy.md %}

<span id="license"></span>
{% include_relative pt/license.md %}
