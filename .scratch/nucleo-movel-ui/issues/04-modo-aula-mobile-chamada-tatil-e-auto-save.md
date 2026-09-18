# 04: Modo Aula Mobile — Chamada Tátil, Plano Dinâmico e Auto-Save

**What to build:**
Permitir que o instrutor, em pé na sala de aula segurando o smartphone com uma mão, opere o diário do dia com máxima ergonomia e zero atrito: a lista de alunos possui altura generosa ($\ge 52\text{px}$) com *segmented control* binário de toque amplo ($\ge 44\times 44\text{px}$) para marcar Presente ou Falta, com frequência acumulada em tempo real e alerta visual para alunos em risco (< 75%). Um botão flutuante proeminente *"Marcar Todos Presentes"* agiliza a turma inteira. No topo, o card retrátil do Plano de Aula Dinâmico expande com um toque para edição do conteúdo ministrado. Todas as edições e presenças são persistidas de forma assíncrona com auto-save (debounce de 600ms via AJAX), confirmadas visualmente por pílula discreta (`Salvo no banco às HH:MM:SS`) e com retenção local preventiva contra quedas de sinal 4G/5G.

**Blocked by:**
01: Shell da Plataforma e Design System Editorial
03: Calendário com Selos Geométricos de Turno e Bottom Sheet Mobile

**Status:** closed

> Nota (2026-09-18, agente): implementado e testado localmente nesta cópia técnica. Conforme `OBSIDIAN_FONTE_DA_VERDADE.md`, esta árvore não é a fonte da verdade — o cofre Obsidian não estava acessível nesta sessão, então o encerramento formal do ticket e a reconciliação de escopo/prioridade devem ser registrados lá antes de considerar isto definitivo.

- [x] A interface do Modo Aula (`/diario/aula`) adota layout vertical para smartphone com alvos de toque $\ge 44\times 44\text{px}$ e espaçamento $\ge 8\text{px}$ entre controles.
- [x] Cada linha de aluno possui altura mínima de 52px, exibindo o nome, percentual de frequência acumulada atualizado dinamicamente e *segmented control* tátil de alto contraste (Presente em verde marfim suave `#EDF3EC`, Falta em vermelho marfim `#FDEBEC`).
- [x] Alunos com frequência acumulada inferior a 75% exibem indicador semântico de atenção imediata.
- [x] Botão de ação em massa *"Marcar Todos Presentes"* permanece acessível para preenchimento de toda a turma em um único toque.
- [x] O Plano de Aula Dinâmico é posicionado em card colapsável no topo da tela: exibe 1 linha de resumo do conteúdo previsto e expande suavemente com um toque para edição do conteúdo ministrado sem ocultar a lista de chamada.
- [x] O mecanismo de persistência opera via Auto-Save assíncrono (requisição AJAX com debounce de 600ms), sem recarregar a página, com indicador sutil no topo/rodapé (`Salvando...` $\rightarrow$ `Salvo no banco às HH:MM:SS`).
- [x] Em caso de falha de conexão móvel, as alterações são mantidas em cache local seguro com pílula de alerta amarelo marfim (`#FBF3DB`) informando retenção e tentativa automática de reconexão.

## Comments

- 2026-09-18 (agente, via `/anthropic-skills:implement`): Backend reaproveitado sem alterações — `AttendanceService::saveAttendance()` e `getAttendanceList()` já cobriam a gravação transacional e o recálculo de frequência/risco. Criado `public/diario/aula_autosave.php` (endpoint JSON, autenticado, CSRF, reaproveita o mesmo caminho transacional do formulário clássico) e reescrito `public/diario/aula.php` (tokens ADR-0006, segmented control ivory, card colapsável do plano, auto-save com debounce 600ms, retenção offline via `localStorage` com reconexão automática, fallback `<noscript>`). Suíte `tests/test_ticket_ui_04_modo_aula.php` criada (54 asserções: estática + funcional via fixture SQLite). Regressão: `test_ticket_04_attendance.php` (58/58) e `test_ticket_ui_02_validator.php` (31/31) permanecem verdes; varredura completa de `php -l` sem erros em `src/` e `public/`. Três suítes pré-existentes (`test_ticket_03_calendar`, `test_ticket_06_zip_atomicity`, `test_ticket_07_protected_content`) já falhavam antes desta mudança por motivos de ambiente (datas fixas, `cd /d` específico de Windows, diretório de temp do ZipArchive) — não relacionadas a este ticket.
