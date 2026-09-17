# 10: Lixeira de Turmas, Soft Delete e Restauração Segura de Capacidade

**What to build:**
Permitir o tratamento seguro de falhas humanas no agendamento através de uma Lixeira com soft-delete (`deleted_at`): ao descartar uma turma criada por engano ou cancelada bruscamente, seus horários e turnos são imediatamente desocupados no calendário de capacidade pedagógica sem destruição física dos dados; o operador pode acessar a aba de Lixeira para consultar o histórico de exclusões, restaurar a turma de volta para a grade ativa (com verificação prévia de conflitos de horário) ou executar o expurgo definitivo caso a turma não possua assentos registrados no Livro de Registro Digital.

**Blocked by:**
09: Gerenciamento Completo de Turmas, Modo Multi-Seleção no Calendário e Override de Encontros

**Status:** done

- [x] A tabela `turmas` (e `encontros`) no MariaDB é atualizada com a coluna `deleted_at DATETIME NULL DEFAULT NULL` e respectivo índice para suporte a soft-delete.
- [x] Na tela da turma (`/diario/turma`) e na listagem (`/diario/turmas`), uma ação "Mover para Lixeira" (com diálogo de confirmação) marca `deleted_at = NOW()`, removendo a turma da listagem padrão de turmas ativas.
- [x] **Desocupação Imediata no Calendário:** O motor de capacidade e bloqueio de choques (`CalendarService::checkConflict`, `getMonthCapacity`, etc.) filtra e desconsidera turmas na lixeira (`WHERE deleted_at IS NULL`), liberando instantaneamente os turnos para novos agendamentos na grade.
- [x] A tela `/diario/turmas` ganha um filtro/aba dedicado **"Lixeira"** (`/diario/turmas?filtro=lixeira`), exibindo todas as turmas descartadas com data de exclusão e opções de "Restaurar" e "Excluir Definitivamente".
- [x] **Restauração com Trava de Conflito:** A ação de restaurar valida se alguma das datas/turnos dos encontros da turma foi ocupada por outra turma enquanto esteve na lixeira; caso haja colisão, bloqueia a restauração e exibe mensagem clara com os dias em conflito; caso não haja colisão, redefine `deleted_at = NULL` e reativa a turma.
- [x] **Expurgo Definitivo com Blindagem de Certificados:** A exclusão física permanente (`DELETE FROM turmas`) é autorizada apenas para turmas sem assentos oficiais vinculados na tabela `livro_registros`; se houver certificados já emitidos, o sistema impede a exclusão física para resguardar a fé pública do registro digital.
