# 09: Gerenciamento Completo de Turmas, Modo Multi-Seleção no Calendário e Override de Encontros

**What to build:**
Permitir que o operador crie e gerencie turmas de ponta a ponta com flexibilidade total: selecionar múltiplas datas de uma só vez na grade mensal do calendário através do "Modo Seleção" e abrir o agendamento já com todas as datas em ordem cronológica; cadastrar turmas completas através de formulário web (`/diario/turmas/novo` e `/diario/turma/editar`); contar com inferência bidirecional inteligente entre horários livres e turnos padrão ([M], [V], [N], [D]); personalizar dias e horários individualmente por encontro através de grade editável de aulas (suportando reposições e horários alternativos); operar turmas com ou sem alunos matriculados; e usufruir da transição de status automática de prevista para em andamento no primeiro dia de aula.

**Blocked by:**
08: Gestão Avançada de Capacidade (Deslocamento, Feriados, Remarcação em Bloco, Teto 80h)

**Status:** completed

- [x] No Calendário Mensal (`/diario/calendario`), um botão `[ Modo Seleção ]` permite alternar para o modo de seleção múltipla: o operador clica diretamente nas células de dias desejados, destacando-os visualmente com numeração ordinal ordenada (*1º Dia, 2º Dia...*).
- [x] Uma barra flutuante de ação rápida no calendário exibe o total de dias selecionados e o botão `[ Agendar Turma nestas Datas → ]`, abrindo o formulário de cadastro com todas as datas preenchidas cronologicamente sem choques de horário.
- [x] O sistema disponibiliza o formulário web completo de cadastro e edição de turma (`/diario/turmas/novo` e `/diario/turma/editar`), incluindo campos pedagógicos e operacionais (nome do curso, cliente, modalidade, carga horária e gerador de encontros).
- [x] **Bidirecionalidade Inteligente de Horários e Turnos:** Selecionar um turno (ex: `[V]`) pré-carrega o horário padrão (14:00 - 18:00); digitar ou alterar horários livres (ex: `13:00 - 17:00` ou `14:00 - 16:00`) infere e marca automaticamente o turno correspondente (`[V]`) no frontend e na validação do backend.
- [x] **Grade Individual de Encontros com Override:** A turma gera seus encontros com base no turno e horário padrão, mas exibe uma tabela de encontros permitindo ao operador alterar data, turno e horário específico de qualquer encontro individual (ex: encontro de sábado pela manhã em turma vespertina), além de adicionar encontros extras de reposição e excluir encontros pendentes sem chamadas.
- [x] **Desacoplamento e Alunos Opcionais:** A criação e o salvamento da turma funcionam perfeitamente mesmo com 0 alunos matriculados, permitindo uso imediato para controle de agenda e diário de classe.
- [x] **Ciclo de Vida:** O sistema sugere ou atualiza o status de `prevista` para `em_andamento` automaticamente na data do primeiro encontro cadastrado.
- [x] Todas as alterações mantêm 100% da suíte de testes existente verde.