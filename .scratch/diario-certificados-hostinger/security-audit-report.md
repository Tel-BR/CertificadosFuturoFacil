# Relatório de Auditoria de Segurança e Perímetro (OWASP & Guard-Audit)

**Data:** 2026-09-17  
**Alvo:** Base de Código e Perímetro de Staging (Tickets 01–07)  
**Ambiente:** PHP 8.3 + MariaDB 10.4+ / Apache (Hostinger)  
**Metodologia:** `netresearch/security-audit-skill` (OWASP Top 10 PHP) & `ExcellentMaps/lunacy-studio-skills` (`guard-audit`)

---

## 1. Resumo Executivo (Para o Gestor)

A base de código da plataforma Futuro Fácil foi auditada em profundidade. **Nenhuma vulnerabilidade crítica (🔴) foi encontrada.** As áreas mais sensíveis — proteção contra injeção SQL, integridade de senhas, isolamento de sessões, LGPD no validador público e blindagem contra downloads indevidos de arquivos — foram desenvolvidas com excelente maturidade técnica.

As únicas recomendações são de **blindagem de perímetro (🟠)**: padronizar os cabeçalhos de segurança HTTP diretamente no servidor Apache (`.htaccess`) e bloquear extensões adicionais de backup/configuração.

---

## 2. Matriz de Classificação de Riscos (Guard-Audit)

| Severidade | Quantidade | Ação Recomendada |
| :--- | :---: | :--- |
| 🔴 **Crítico** (Perda total/comprometimento imediato) | **0** | Nenhuma vulnerabilidade crítica detectada. |
| 🟠 **Médio** (Endurecimento de perímetro/cabeçalhos) | **3** | Aplicar cabeçalhos defensivos e desativar listagem de diretórios no `.htaccess`. |
| 🟡 **Baixo** (Políticas incrementais e observabilidade) | **2** | Acompanhar CSP para embeds e higienizar linhas de debug em staging. |
| 🟢 **Em Conformidade** (Defesas ativas e aprovadas) | **12** | Prepared statements, bcrypt, rate-limit, CSRF, cookies HttpOnly, LGPD, etc. |

---

## 3. Achados Detalhados

### 🟠 1. Cabeçalhos HTTP de Segurança Ausentes no Perímetro Global (`guard-server`)
- **O que está aberto:** Os cabeçalhos `X-Frame-Options`, `X-Content-Type-Options` e `Referrer-Policy` estavam sendo injetados apenas pelo script PHP do validador (`/validar`), mas não de forma global pelo Apache para todo o domínio (`/turmas`, `/diario`).
- **Risco:** Ausência de cabeçalho global contra clickjacking (abertura do site em iframe de terceiros) e MIME-sniffing caso algum navegador tente interpretar arquivos incorretamente.
- **Solução:** Configurar a diretiva `<IfModule mod_headers.c>` no `public/.htaccess` e `.htaccess` raiz.
- **Tempo estimado:** 10 minutos.

### 🟠 2. Listagem de Diretórios Não Desativada Globalmente no Apache (`guard-server`)
- **O que está aberto:** Embora a pasta de uploads (`public/turmas/arquivos/`) já possua `Options -Indexes`, os arquivos `.htaccess` da raiz e de `public/` não continham a diretiva explícita.
- **Risco:** Em caso de eventual ausência de `index.php` em alguma subpasta pública, o Apache poderia listar os arquivos do diretório.
- **Solução:** Adicionar `Options -Indexes` em `public/.htaccess`.
- **Tempo estimado:** 5 minutos.

### 🟠 3. Regra de Bloqueio a Extensões Sensíveis e Backups (`guard-server`)
- **O que está aberto:** O `.htaccess` atual bloqueava especificamente `.db`, `.sqlite` e `SECRETS`. Não cobria de forma preventiva extensões de backup e logs como `.env`, `.ini`, `.sql`, `.bak` e `*.local.php`.
- **Risco:** Caso algum desenvolvedor crie inadvertidamente um backup `banco.sql` ou arquivo de teste na raiz web, ele poderia ser lido diretamente.
- **Solução:** Ampliar a regex do `FilesMatch` para barrar preventivamente essas extensões.
- **Tempo estimado:** 5 minutos.

---

## 4. O Que Já Está em Alta Conformidade (🟢)

1. **Proteção contra SQL Injection (OWASP A03):** 100% das consultas utilizam PDO com prepared statements e `PDO::ATTR_EMULATE_PREPARES => false`. Nenhuma concatenação dinâmica de variáveis de usuário em SQL.
2. **Criptografia de Senhas (OWASP A07):** Uso nativo de `password_hash` / `password_verify` com bcrypt de custo adequado na tabela `usuarios_admin`.
3. **Mitigação de Força Bruta & Rate Limiting (OWASP A07):** Tabela `tentativas_login` bloqueia temporariamente o IP/usuário por 15 minutos após 5 erros consecutivos.
4. **Delay Progressivo para Chaves de Alunos:** Retardo intencional de 1s, 2s a 4s por erro sucessivo na inserção da chave da turma, mitigando ataques de enumeração automatizada.
5. **Proteção contra CSRF (OWASP A01):** Tokens anti-CSRF gerados com entropia criptográfica (`random_bytes(32)`) e validados com tempo constante (`hash_equals`) em todos os formulários administrativos.
6. **Segurança de Sessões & Cookies (OWASP A07):** Cookies configurados com `HttpOnly = true`, `SameSite = Strict`, flag `Secure` automática em HTTPS, e renovação imediata de identificador (`session_regenerate_id(true)`) após login.
7. **Isolamento de Escopo Administrativo vs Aluno:** Variáveis de sessão `usuario_admin` e `aluno_turma_autenticado` operam em namespaces estritamente segregados, prevenindo escalada de privilégios.
8. **Proteção contra Path Traversal em Downloads (OWASP A01):** `MaterialService::resolveFilePath()` executa checagem canônica via `realpath()`, rejeita bytes nulos `\0` e valida prefixo da pasta permitida.
9. **Bloqueio de Download Direto sem Autenticação:** A pasta física `public/turmas/arquivos/` rejeita conexões diretas com HTTP 403 Forbidden pelo Apache. O download só ocorre por streaming autenticado em `download.php`.
10. **Whitelist de Extensões no Upload:** Apenas tipos seguros autorizados (`pdf`, `xlsx`, `docx`, `pptx`, `zip`, `csv`). Arquivos executáveis (.php, .exe, .sh) são terminantemente bloqueados.
11. **Conformidade com a LGPD (Lei nº 13.709/2018):** No validador público, nomes (`A** L****...`) e CPFs (`***.123.456-**`) são exibidos estritamente mascarados, sem expor a identidade completa dos alunos.
12. **Segurança de Arquivos Sensíveis na Hostinger:** O diretório de código `src/` está isolado fora de `public_html/`, tornando-o fisicamente inacessível via browser.

---

## 5. Plano de Ação de Hardening

1. **Perímetro (.htaccess):** Aplicar cabeçalhos defensivos (`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `HSTS`), `Options -Indexes` e filtro estendido de arquivos sensíveis.
2. **Router Embutido (`public/router.php`):** Sincronizar os mesmos cabeçalhos no despachante local para testes.
3. **Testes de Regressão (`tests/test_ticket_07b_security.php`):** Criar suíte automatizada validando programaticamente todas as defesas descritas.
