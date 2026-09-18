# 04: Modo Aula Mobile — Chamada Tátil, Plano Dinâmico e Auto-Save

**What to build:**
Permitir que o instrutor, em pé na sala de aula segurando o smartphone com uma mão, opere o diário do dia com máxima ergonomia e zero atrito: a lista de alunos possui altura generosa ($\ge 52\text{px}$) com *segmented control* binário de toque amplo ($\ge 44\times 44\text{px}$) para marcar Presente ou Falta, com frequência acumulada em tempo real e alerta visual para alunos em risco (< 75%). Um botão flutuante proeminente *"Marcar Todos Presentes"* agiliza a turma inteira. No topo, o card retrátil do Plano de Aula Dinâmico expande com um toque para edição do conteúdo ministrado. Todas as edições e presenças são persistidas de forma assíncrona com auto-save (debounce de 600ms via AJAX), confirmadas visualmente por pílula discreta (`Salvo no banco às HH:MM:SS`) e com retenção local preventiva contra quedas de sinal 4G/5G.

**Blocked by:**
01: Shell da Plataforma e Design System Editorial
03: Calendário com Selos Geométricos de Turno e Bottom Sheet Mobile

**Status:** ready-for-agent

- [ ] A interface do Modo Aula (`/diario/aula`) adota layout vertical para smartphone com alvos de toque $\ge 44\times 44\text{px}$ e espaçamento $\ge 8\text{px}$ entre controles.
- [ ] Cada linha de aluno possui altura mínima de 52px, exibindo o nome, percentual de frequência acumulada atualizado dinamicamente e *segmented control* tátil de alto contraste (Presente em verde marfim suave `#EDF3EC`, Falta em vermelho marfim `#FDEBEC`).
- [ ] Alunos com frequência acumulada inferior a 75% exibem indicador semântico de atenção imediata.
- [ ] Botão de ação em massa *"Marcar Todos Presentes"* permanece acessível para preenchimento de toda a turma em um único toque.
- [ ] O Plano de Aula Dinâmico é posicionado em card colapsável no topo da tela: exibe 1 linha de resumo do conteúdo previsto e expande suavemente com um toque para edição do conteúdo ministrado sem ocultar a lista de chamada.
- [ ] O mecanismo de persistência opera via Auto-Save assíncrono (requisição AJAX com debounce de 600ms), sem recarregar a página, com indicador sutil no topo/rodapé (`Salvando...` $\rightarrow$ `Salvo no banco às HH:MM:SS`).
- [ ] Em caso de falha de conexão móvel, as alterações são mantidas em cache local seguro com pílula de alerta amarelo marfim (`#FBF3DB`) informando retenção e tentativa automática de reconexão.
