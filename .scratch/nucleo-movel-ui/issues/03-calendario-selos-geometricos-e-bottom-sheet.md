# 03: Calendário com Selos Geométricos de Turno e Bottom Sheet Mobile

**What to build:**
Permitir que o operador visualize o planejamento anual e mensal na aba `Calendário` com identificação imediata e acessível de turnos através de **Selos Geométricos Setoriais** (substituindo letras cruas e imunes a daltonismo). No desktop, passar o mouse sobre a célula abre um popover flutuante rápido com os compromissos do dia. No smartphone, o toque abre suavemente um *Bottom Sheet* (gaveta inferior deslizante) listando as turmas e o botão tátil direto *"Abrir Diário deste Encontro"*. Ao agendar um encontro, o sistema previne choques de horário emitindo alerta claro em Coral Solar com confirmação explícita de sobreposição se o operador assim desejar.

**Blocked by:**
01: Shell da Plataforma e Design System Editorial

**Status:** ready-for-agent

- [ ] A grade do calendário anual (12 meses) e mensal renderiza os 4 turnos utilizando exclusivamente os Selos Geométricos Setoriais definidos no ADR-0006:
  - Manhã (`M`): Âmbar suave (`#D97706`) com geometria de semicírculo esquerdo;
  - Tarde (`V`): Coral Solar (`#EA580C`) com geometria de semicírculo direito;
  - Noite (`N`): Petróleo Tech (`#0E7490`) com geometria de anel circular vazado;
  - Integral (`D`): Disco cheio bipartido (Coral + Petróleo).
- [ ] No desktop, o rollover (`:hover`) do mouse em dias com turmas exibe popover contextual flutuante sem layout thrashing contendo cursos, horários e clientes atendidos.
- [ ] No mobile, o toque em uma data abre uma gaveta inferior deslizante (*Bottom Sheet*) com a listagem dos encontros do dia e botão de ação direta *"Abrir Diário deste Encontro"* com alvo de toque $\ge 44\times 44\text{px}$.
- [ ] Datas anteriores à data de hoje aparecem estilizadas automaticamente em tom neutro/desbotado suave.
- [ ] Ao tentar agendar em dia e turno já comprometido, a interface exibe um aviso preventivo com destaque em Coral Solar, solicitando confirmação explícita de sobreposição para prosseguir.
