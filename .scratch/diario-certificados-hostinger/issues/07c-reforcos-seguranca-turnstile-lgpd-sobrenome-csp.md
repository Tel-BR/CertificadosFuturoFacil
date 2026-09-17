# 07c: Reforços de Segurança — Desafio de Sobrenome em CAIXA ALTA, Cloudflare Turnstile, Rate-Limit 30 req/min e CSP

**What to build:**
Implementar o pacote de reforços defensivos e conformidade de privacidade aprovados na sessão de Grill-Me (ADR 0005 e Auditoria OWASP):

1. **O1 — Validação Dupla de Certificado por CPF no Portal do Aluno (`/turmas`):**
   - Para evitar raspagem de certificados de colegas apenas digitando um CPF qualquer, implementar um desafio de múltipla escolha pós-CPF:
   - Ao informar um CPF válido de 11 dígitos, o sistema localiza o registro do aluno e sorteia 3 outros sobrenomes distratores (de alunos de outras turmas ou gerados).
   - O aluno deve selecionar seu sobrenome correto em meio às 4 opções.
   - **Exigência estrita de UX:** Todos os sobrenomes nas opções devem ser exibidos obrigatoriamente em **CAIXA ALTA** (`mb_strtoupper`).
   - O download do certificado individual só é liberado mediante o acerto do sobrenome correspondente ao CPF.

2. **O2 — Proteção Anti-Automação na Chave de Acesso (`/turmas`):**
   - Manter o atraso progressivo (1s, 2s, 4s) na sessão para mitigar impacto sobre redes corporativas (NAT).
   - Após 3 tentativas incorretas consecutivas registradas na sessão (`student_failed_attempts >= 3`), exigir desafio Cloudflare Turnstile no formulário de login de alunos antes de processar novas tentativas.

3. **O3 & O4 — Proteção Anti-Robô e Anti-Scraping no Validador Público (`/validar`):**
   - Integrar Cloudflare Turnstile em modo invisível para barrar bots de IA e scrapers automatizados em varredura massiva.
   - Implementar rate-limiting por IP limitando a no máximo 30 requisições de validação por minuto por IP (utilizando tabela `tentativas_login` ou cache transitório).
   - Manter estritamente o mascaramento LGPD padrão ouro (`A** L****` e `***.456.789-**`).

4. **O10 & Y1 — Perímetro HTTPS e Política de Conteúdo (CSP):**
   - Adicionar regra de redirecionamento 301 forçado para HTTPS no `.htaccess` (`RewriteEngine On`, checando `HTTPS != on` e cabeçalho `X-Forwarded-Proto != https`).
   - Refinar a diretiva Content-Security-Policy (CSP) em `/turmas` autorizando estritamente os domínios de mídia dos conteúdos incorporados (YouTube nocookie, Vimeo, formulários Google Forms e Microsoft Forms).

**Blocked by:**
07b: Auditoria de Segurança OWASP, Blindagem de Perímetro e Testes de Segurança
08: Gestão Avançada de Capacidade — Deslocamento fora de Goiânia, Feriados Nacionais, Remarcação e Teto de 80h

**Status:** ready

- [ ] O formulário de consulta de certificado no portal `/turmas` exige confirmação com desafio de múltipla escolha com o sobrenome do aluno.
- [ ] Todas as opções do desafio de sobrenome são exibidas obrigatoriamente em CAIXA ALTA (`mb_strtoupper`).
- [ ] Tentativas de login com chave de acesso no `/turmas` exigem verificação de Cloudflare Turnstile após 3 falhas na sessão.
- [ ] Validador público `/validar` inclui Cloudflare Turnstile invisível e trava de rate-limit de 30 consultas por minuto por IP.
- [ ] Arquivo `public/.htaccess` aplica redirecionamento 301 automático de requisições HTTP inseguras para HTTPS.
- [ ] O portal do aluno possui cabeçalho CSP autorizando apenas scripts locais e embeds autorizados (YouTube nocookie, Vimeo, Google/MS Forms).
- [ ] Criada suíte de testes automatizados `tests/test_ticket_09_security_refinements.php` cobrindo todas as novas regras de negócio defensivas.
