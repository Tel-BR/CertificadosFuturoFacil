# 01: Shell da Plataforma e Design System Editorial

**What to build:**
Estabelecer a casca responsiva navegável e a identidade visual oficial da Futuro Fácil em toda a aplicação administrativa (`/diario`), integrando os tokens do ADR-0006: fundo papel marfim (`#FAF7F1`), texto carvão (`#1B1918`), Petróleo Tech (`#0E7490`), Coral Solar (`#EA580C`) e bordas nítidas de 1px (`#E2DFDA`). No desktop, o cabeçalho superior minimalista abriga o novo logotipo vetorial oficial (`logo.svg` em MuseoModerno, sem o símbolo da coruja), os atalhos de navegação e o logout. No smartphone, a navegação é ancorada em uma *Bottom Navigation Bar* fixa na zona do polegar com altura confortável e alvos táteis mínimos de 44x44px para as 4 abas canônicas: `Calendário`, `Diário`, `Certificados` e `Ajustes`.

**Blocked by:**
None (can start immediately)

**Status:** closed

- [x] Os tokens CSS institucionais (`--ff-paper: #FAF7F1`, `--ff-ink: #1B1918`, `--ff-cyan: #0E7490`, `--ff-orange: #EA580C`, `--ff-line: #E2DFDA`) são consolidados no stylesheet base do painel.
- [x] A família tipográfica Ubuntu institucional (pesos 300, 400, 500 e 700) e a tipografia Monospace para dados técnicos são carregadas sem causar Cumulative Layout Shift (CLS < 0.1).
- [x] O logotipo vetorial oficial da marca (`logo.svg`, sem coruja) é exibido com proporções preservadas e área de respiro adequada no cabeçalho.
- [x] No mobile (< 768px), o cabeçalho superior é enxuto e uma barra de navegação inferior (*Bottom Nav*) fixa com altura de 56px disponibiliza as 4 abas: `Calendário`, `Diário`, `Certificados` e `Ajustes`, com alvos mínimos de 44x44px e indicação tátil do estado ativo.
- [x] No desktop (>= 768px), a navegação é disposta em linha no cabeçalho superior, liberando 100% da largura útil para tabelas e calendários sem barras laterais persistentes.
- [x] A sessão monousuário administrativa (`bcrypt`, CSRF, cookies HttpOnly/SameSite) e a rota de logout funcionam perfeitamente integradas ao novo layout visual.

## Comments

- **2026-09-17:** Implementado e aprovado no commit `80d377e` juntamente com Ticket 07c.

