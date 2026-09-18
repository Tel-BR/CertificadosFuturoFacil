# 07b: Auditoria OWASP, Blindagem de Perímetro e Testes de Regressão de Segurança

**What to build:**
Executar varredura completa de segurança estática e de perímetro sobre a base implantada em staging (01 a 07), combinando as regras da `security-audit` (PHP/OWASP) e a matriz de risco da `guard-audit`. Blindar cabeçalhos no `.htaccess`, proteger arquivos sensíveis e criar a suíte automatizada `tests/test_ticket_07b_security.php`.

**Blocked by:**
07: Área Protegida de Conteúdo da Turma

**Status:** completed

- [x] Relatório de auditoria gerado em `.scratch/diario-certificados-hostinger/security-audit-report.md` com achados classificados (🔴 Crítico, 🟠 Médio, 🟡 Baixo, 🟢 Em conformidade).
- [ ] Confirmação de ausência de vulnerabilidades críticas na base 01–07 (sem SQLi, sem vazamento de credenciais, sem bypass de autenticação, sem path traversal).
- [x] Cabeçalhos de segurança HTTP implementados no `public/.htaccess` (`Strict-Transport-Security`, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`).
- [x] Bloqueio comprovado contra leitura externa de `SECRETS`, `.db`, `src/` e `credentials.local.php`.
- [x] Suíte automatizada criada em `tests/test_ticket_07b_security.php` passando com 100% de sucesso junto com as 10 suítes existentes.

## Comments

- **2026-09-17 — Auditoria somente de leitura:** a avaliação histórica de ausência de riscos críticos foi superada pelo relatório canônico no Obsidian, com cópia técnica em [security-audit-2026-09-17.md](../security-audit-2026-09-17.md). Foram identificados R1 (dados pessoais e códigos versionados) e R2 (senhas administrativas padrão com ativação em produção não confirmada). As demais verificações e limitações constam da matriz. Nenhuma correção, teste com escrita ou mudança de servidor foi realizada. A decisão segue pendente item por item, começando por R1. A caixa de verificação que pressupõe ausência de achados críticos não deve ser marcada.
