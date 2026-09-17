# 03: Calendário Anual/Mensal, Rollover de Turnos e Carga de Testes Fictícios

**What to build:**
Permitir que o operador visualize a ocupação de sua agenda na Visão Anual (12 meses) e Visão Mensal, identificando com precisão os turnos ocupados (`[M]` Matutino, `[V]` Vespertino, `[N]` Noturno, `[D]` Dia Todo) por meio de posições fixas e paleta de alto contraste sem risco de confusão para daltônicos (evitando azul vs roxo). Datas passadas aparecem automaticamente desbotadas em tons neutros. Passar o mouse (ou toque mobile) em qualquer dia revela um popover interativo com o curso, cliente e horários ocupados. O clique em um dia permite abrir as turmas existentes ou agendar nova turma/reposição com bloqueio impeditivo de choque de horário. Inclui script de seed com turmas fictícias passadas, presentes e futuras para testar a experiência de ponta a ponta.

**Blocked by:**
02: Autenticação Administrativa e Estrutura Base do Painel Web

**Status:** completed

- [x] A tela do calendário disponibiliza alternância fluida entre a Visão Anual (grade de 12 meses lado a lado) e a Visão Mensal detalhada.
- [x] Cada dia no calendário exibe indicadores visuais para os 4 turnos (`[M]`, `[V]`, `[N]`, `[D]`) utilizando posições dedicadas e paleta acessível que não confunde daltônicos (sem dependência de pares azul/roxo).
- [x] As datas anteriores à data atual (`< hoje`) recebem estilização automática em tons neutros/desbotados para destacar o foco nas atividades presentes e futuras.
- [x] O rollover/hover do cursor no desktop (e toque simples no smartphone) sobre qualquer data exibe uma caixa flutuante (*popover/tooltip*) detalhando as turmas, horários de início/fim e turnos comprometidos naquele dia.
- [x] O clique em um dia com turmas exibe atalhos diretos para abrir o diário/chamada de cada encontro agendado.
- [x] O clique em um dia livre (ou com turnos disponíveis) abre formulário rápido para agendar uma nova turma confirmada ou registrar reposição/adiantamento de aula para turma existente.
- [x] **Bloqueio de Choque de Horário (Costura de Teste 5):** O sistema valida a ocupação de turnos e rejeita a gravação caso o operador tente agendar um novo encontro em um dia e turno já comprometido por outra turma confirmada, exibindo alerta explicativo.
- [x] **Script de Carga de Homologação:** O script `database/seeds/test_data_seed.php` popula o MariaDB com dados e turmas fictícias cobrindo todos os 4 turnos (`M`, `V`, `N`, `D`), com turmas passadas (conferindo o visual desbotado), turmas em andamento no dia atual e turmas futuras agendadas.
