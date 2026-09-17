---
tipo: decisao_arquitetural
ciclo_vida: ativo
rag: incluir
sensibilidade: interna
canonico: true
atualizado_em: 2026-09-17
decisao_id: ADR-0005
status: aprovado
relacionado_com: "[[ADR 0004 - Expansão para Diário Ativo, Calendário e Hospedagem Nativa]]"
---

# ADR 0005 — Deploy Automatizado por Endpoint HTTPS e Blindagem Anti-Robô com Turnstile

## Contexto

A implantação do ambiente de homologação independente (staging) na Hostinger expôs barreiras estruturais severas que inviabilizaram automações remotas convencionais de agentes de IA:
1. **Firewall e Isolamento:** Porta 3306 (MySQL) e SFTP fechados externamente pela CDN da Hostinger;
2. **Proteções Anti-Bot e 2FA:** Cloudflare Turnstile e autenticação multifator bloqueando navegadores headless no hPanel;
3. **Fricção no File Manager e phpMyAdmin:** O Gerenciador de Arquivos criava subpastas indesejadas na extração de ZIPs gerando erro 500 silencioso, enquanto o phpMyAdmin bloqueava upload via iframe;
4. **Achados de Segurança (Auditoria de 17/09/2026):**
   - **R1:** Presença temporária de dados pessoais reais de certificados históricos versionados no Git;
   - **R2:** Senhas administrativas padrão nos seeds de desenvolvimento;
   - **O1:** Consulta por CPF no portal do aluno suscetível a homônimos;
   - **O2:** Tentativas de chave de aluno contadas apenas em sessão sem proteção anti-máquina;
   - **O3/O4:** Validador público de certificados exposto a varreduras automatizadas de bots e raspagem de dados;
   - **O10/Y1:** Necessidade de forçar HTTPS 301 e calibrar Content Security Policy (CSP) para players de vídeo e formulários.

## Decisões

### 1. Eliminação Definitiva do File Manager e phpMyAdmin via Deployer HTTPS
- Fica estabelecido que nenhum desenvolvedor ou agente de IA utilizará o File Manager ou o phpMyAdmin da Hostinger para deploys e migrações cotidianas.
- Criação do endpoint seguro `public/deploy.php`, servido sobre a porta 443 (HTTPS), protegido por `DEPLOY_TOKEN` criptográfico SHA-256 de 64 caracteres.
- O endpoint recebe o pacote do repositório via POST multipart, utiliza `ZipArchive` nativo do PHP para extrair cirurgicamente em `src/` e `public_html/` sem criar subpastas, e executa migrações SQL pendentes localmente via PDO em `localhost:3306`.
- A operação local é acionada por um comando único no PowerShell (`scripts/deploy_staging.ps1`), que roda a suíte de testes antes do envio e conclui o deploy em 2 segundos.
- O bootstrap inicial do `deploy.php` será feito manualmente no painel uma única vez.

### 2. Resolução Estratégica dos Achados de Segurança (R1 e R2)
- **R1 (Dados Pessoais Reais no Git):** Os dados reais dos certificados da turma Sicoob serão preservados temporariamente para viabilizar a reemissão e conferência oficial na nova plataforma assim que for hospedada definitivamente. Após a conferência e consolidação no Livro Digital oficial, todo o histórico do Git e lastros residuais serão expurgados integralmente com `git filter-repo`.
- **R2 (Credenciais Administrativas):** As credenciais temporárias são mantidas no staging durante a fase de testes restritos (onde o rate-limiting com bloqueio em 5 erros já mitiga força bruta). Uma credencial definitiva forte e exclusiva será gerada no cutover para produção.

### 3. Blindagem de Acesso do Aluno e Validador com Cloudflare Turnstile (O1 a O4)
- **O1 (Download Duplo de Certificados):** No portal do aluno, a consulta de certificado por CPF exigirá confirmação dupla: o CPF (11 dígitos) e a seleção do **sobrenome correto** em meio a alternativas distratoras, todas formatadas obrigatoriamente em **CAIXA ALTA**.
- **O2 (Proteção Anti-Máquina na Chave da Turma):** O atraso progressivo (1s, 2s, 4s) é mantido para não prejudicar redes corporativas compartilhadas (NAT). Após 3 falhas consecutivas de chave, ativa-se o widget do **Cloudflare Turnstile**, bloqueando agentes de IA e bots automatizados.
- **O3 e O4 (Proteção Anti-Scraping no Validador Público):** O validador público (`/validar`) adota o **Cloudflare Turnstile em modo invisível**, permitindo que humanos verifiquem certificados instantaneamente via QR Code pelo celular, mas bloqueando ferramentas de raspagem massiva de dados. O mascaramento atual de nome (`A** L****`) e CPF (`***.456.789-**`) é mantido integralmente em conformidade com a LGPD.

### 4. Perímetro Web e CSP (O10 e Y1)
- Redirecionamento permanente **HTTPS 301** forçado diretamente no `.htaccess` do Apache.
- Definição de **Content-Security-Policy (CSP)** no portal do aluno permitindo apenas scripts locais e os embeds autorizados de players (YouTube nocookie, Vimeo, Google Forms e Microsoft Forms).

## Consequências

- **Positivas:**
  - Fim absoluto do "ping-pong" e dos bloqueios de automação entre agentes na Hostinger;
  - Deploys instantâneos e atômicos a partir do terminal com validação prévia de testes;
  - Proteção robusta contra agentes de IA e raspagem de dados sem degradar a experiência do aluno humano em redes corporativas;
  - Conformidade plena com a LGPD e caminho seguro para o cutover final.
- **Atenção:**
  - O bootstrap manual do `deploy.php` e a configuração do `DEPLOY_TOKEN` no `credentials.local.php` do servidor devem ser realizados uma única vez.
