# 🔐 Segurança e Conformidade

* Controle de acesso baseado em capabilities via API padrão de formulários do Moodle
* Proteção com `require_sesskey()` em todas as ações POST
* Sem interpolação de strings SQL — consultas parametrizadas em todo o código
* Gravações de nota via API oficial de notas do Moodle (`update_final_grade`)
* Proteção anti-recursão que impede o evento de nota de re-acionar o observer infinitamente
