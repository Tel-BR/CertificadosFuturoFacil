# 02: Validação Pública e Harmonização Editorial

**What to build:**
Adequar a página pública de consulta de autenticidade (`/validar`) ao Design System Editorial e às diretrizes do ADR-0006 e da LGPD. O visual institucional adota o logotipo oficial vetorial, fundo marfim e cartões limpos com bordas de 1px. Ao receber um hash por QR Code ou busca, exibe o cartão de autenticidade com dados do aluno rigorosamente mascarados (`A** L**** S***** N*********` e `***.123.456-**`). Em caso de hash não localizado ou inválido, apresenta um cartão austero com borda em vermelho marfim (`#FDEBEC`), sem alarmismos, com instrução clara para conferência do código.

**Blocked by:**
None (can start immediately)

**Status:** closed

- [x] A página `/validar` adota o Design System institucional (fundo `#FAF7F1`, texto `#1B1918`, logotipo oficial `logo.svg` no topo e sem elementos visuais supérfluos).
- [x] O formulário de consulta permite busca rápida por digitação direta do código de autenticidade SHA-256 com normalização e sanitização imediata.
- [x] A consulta com hash válido exibe confirmação inequívoca de autenticidade, nome do curso, carga horária, datas e dados do aluno estritamente mascarados sob a LGPD.
- [x] A consulta com hash inválido ou inexistente renderiza um card austero com borda em vermelho marfim suave (`#FDEBEC`), mensagem informativa *"Certificado não localizado"* e orientação objetiva para conferir a digitação ou contatar a instituição.
- [x] Não-regressão comprovada: os 39 códigos reais da turma Sicoob continuam validando com 100% de sucesso e sem regressões na resposta mascarada.

