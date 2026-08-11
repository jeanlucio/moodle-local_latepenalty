# 🔒 Privacidade

Este plugin armazena dados pessoais em uma tabela:

| Tabela | Dados pessoais |
|--------|---------------|
| `local_latepenalty_overrides` | Sobreposições por estudante criadas por professores: prazo personalizado, taxa diária e limite máximo, identificadas por `userid` e módulo de curso |

O Privacy Provider (`classes/privacy/provider.php`) implementa a Privacy API completa do Moodle:

* **Declaração de metadados** — descreve os dados pessoais armazenados em `local_latepenalty_overrides`.
* **Descoberta de contextos** — `get_contexts_for_userid()` localiza todos os contextos de módulo onde o usuário possui um registro de sobreposição.
* **Descoberta de usuários** — `get_users_in_context()` identifica todos os usuários com registros de sobreposição em um determinado contexto de módulo.
* **Exportação de dados** — `export_user_data()` exporta cada registro de sobreposição (prazo, taxas, timestamps) sob o contexto do módulo da atividade.
* **Exclusão de dados** — suporta exclusão por usuário, por contexto e por lista de usuários (`delete_data_for_user()`, `delete_data_for_all_users_in_context()`, `delete_data_for_users()`).

Os seguintes dados **não** são gerenciados pela Privacy API deste plugin:

* **Configuração de regras de penalidade** (`local_latepenalty_rules`, `local_latepenalty_group_overrides`) — armazenados por módulo de curso ou por grupo, não por usuário individual.
* **Modificações de nota** — registradas na tabela padrão `grade_grades_history` do Moodle, pertencente e gerenciada pelo núcleo do Moodle.
