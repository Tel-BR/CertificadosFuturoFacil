# 11: Sincronização Excel com Prévia de Diff Visual e Preservação de Dados

**What to build:**
Aprimorar o fluxo de sincronização bidirecional com planilhas Excel (.xlsx): ao enviar uma planilha atualizada da turma, o operador é recepcionado por um modal/tela de prévia com Diff Visual detalhado destacando exatamente o que será inserido, atualizado ou mantido inalterado antes da efetivação no banco; caso uma aba venha vazia (ex: aba de alunos não preenchida porque o operador só alterou o calendário), o sistema aplica a Regra Estrita de Preservação, mantendo 100% dos alunos cadastrados intactos sem deleção acidental; e o reagendamento de datas via Excel preserva o histórico integral de chamadas e planos já executados.

**Blocked by:**
05: Sincronização Bidirecional com Planilhas Excel (.xlsx)
09: Gerenciamento Completo de Turmas, Modo Multi-Seleção no Calendário e Override de Encontros

**Status:** completed

- [x] Ao realizar o upload do arquivo `.xlsx` na tela da turma (`/diario/turma`), o sistema analisa a planilha em memória sem persistir de imediato e gera um relatório estruturado de **Diff Visual**.
- [x] A interface exibe a prévia com contadores e listas expansíveis com distinção visual: novos alunos a cadastrar, alunos existentes a atualizar, encontros com datas/horários alterados e registros inalterados.
- [x] **Regra Estrita de Preservação de Seções Vazias:** Se a aba de Alunos estiver vazia na planilha reimportada (ex: operador manteve apenas o cabeçalho pois pretendia ajustar apenas datas), o parser não apaga nem limpa os alunos existentes no banco de dados; apenas preserva os registros existentes.
- [x] **Reagendamento Seguro de Aulas Realizadas:** Se o operador alterar a data ou horário de uma aula que já teve chamada gravada no Modo Aula, o sistema atualiza a data do encontro e mantém íntegras todas as presenças e conteúdos já ministrados, registrando aviso no Diff Visual.
- [x] O operador tem os botões de ação explícita: `[ Confirmar e Aplicar Sincronização ]` e `[ Cancelar ]`.
- [x] A confirmação executa todas as atualizações dentro de uma transação única e atômica (`BEGIN ... COMMIT`) no MariaDB, garantindo consistência total.