# 07b: Auditoria OWASP, Blindagem de Perímetro e Testes de Regressão de Segurança

**What to build:**
Executar varredura completa de segurança estática e de perímetro sobre a base implantada em staging (01 a 07), combinando as regras da `security-audit` (PHP/OWASP) e a matriz de risco da `guard-audit`. Blindar cabeçalhos no `.htaccess`, proteger arquivos sensíveis e criar a suíte automatizada `tests/test_ticket_07b_security.php`.

**Blocked by:**
07: Área Protegida de Conteúdo da Turma

**Status:** in-progress

- [ ] Relatório de auditoria gerado em `.scratch/diario-certificados-hostinger/security-audit-report.md` com achados classificados (🔴 Crítico, 🟠 Médio, 🟡 Baixo, 🟢 Em conformidade).
- [ ] Confirmação de ausência de vulnerabilidades críticas na base 01–07 (sem SQLi, sem vazamento de credenciais, sem bypass de autenticação, sem path traversal).
- [ ] Cabeçalhos de segurança HTTP implementados no `public/.htaccess` (`Strict-Transport-Security`, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`).
- [ ] Bloqueio comprovado contra leitura externa de `SECRETS`, `.db`, `src/` e `credentials.local.php`.
- [ ] Suíte automatizada criada em `tests/test_ticket_07b_security.php` passando com 100% de sucesso junto com as 10 suítes existentes.
