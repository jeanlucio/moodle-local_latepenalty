# 🧩 Integração Opcional: PlayerHUD

O Late Penalty funciona sozinho. Se o bloco de gamificação **PlayerHUD** (`block_playerhud`, do mesmo autor) também estiver instalado, o professor pode transformar uma extensão de prazo em recompensa: o PlayerHUD oferece o poder de item **Extensão de Prazo**, que o estudante ganha no jogo e resgata em dias a mais numa atividade.

* O professor cria o item no PlayerHUD e define o número de dias e uma atividade específica ou *Qualquer atividade elegível* (o estudante escolhe ao resgatar). A opção só aparece quando o Late Penalty está instalado.
* Só aceitam a extensão as atividades visíveis para o estudante e com uma regra do Late Penalty ativada.
* Os dias são somados ao prazo atual do estudante naquela atividade; resgatar um segundo item estende de novo.
* A extensão é gravada como **Sobreposição do Late Penalty** para o estudante (só o prazo; as taxas continuam vindo da regra). O professor a vê, e pode alterá-la ou excluí-la, em *Sobreposições de penalidade por atraso*, aba *Sobreposições de usuário*, e o relatório a mostra como origem do prazo.
* A nota do estudante é recalculada na hora, reduzindo ou removendo uma penalidade já aplicada.

Nenhum dos dois plugins exige o outro. Sem o PlayerHUD, nada muda no Late Penalty; sem o Late Penalty, o PlayerHUD simplesmente não oferece esse poder de item.

👉 <https://marketplace.moodle.com/plugins/block_playerhud>
