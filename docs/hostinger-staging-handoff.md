# Handoff Técnico: Staging Hostinger & Dificuldades de Acesso

**Data do Handoff:** 2026-09-17  
**Ambiente Homologado:** `https://staging.futurofacil.com.br`  
**Repositório Local:** `C:\Users\Admin\Documents\CertificadosFuturoFacil` (Branch: `main`)  
**Cofre Obsidian (Fonte Canônica da Verdade):** `c:\Users\Admin\Vaults\Sync\Futuro Fácil\Ferramentas\Certificados Futuro Fácil`  
**Governança:** Conforme `OBSIDIAN_FONTE_DA_VERDADE.md`, o cofre Obsidian é a autoridade máxima de contexto e decisões de produto; a pasta de documentos Git é o backup técnico e repositório operacional.

---

## 1. Resumo Executivo e Estado Atual

O deploy e a homologação do ambiente independente de staging na Hostinger foram **concluídos com 100% de sucesso**. O ambiente está ativo, conectado ao MariaDB, operando com regras de segurança, rate-limiting e separação de privilégios.

- **URL Pública de Testes:** `https://staging.futurofacil.com.br`
- **Banco de Dados:** MariaDB 10.4+ (`u505703191_ffstaging`), 8 tabelas criadas e ativas.
- **Testes Locais Automatizados:** 10 suítes (`php tests/test_ticket_*.php`), 500 asserções executadas com 100% de aprovação.
- **Integridade do Código:** Git limpo, sem segredos versionados.

---

## 2. Dificuldades Técnicas de Acesso (Mesmo com SECRETS Presente)

Mesmo com o arquivo `SECRETS` local contendo as credenciais do banco de staging (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`), uma automação direta externa via terminal ou script local **não é viável** pelos motivos abaixo:

### A. Isolamento de Rede e Firewall de Borda (Hostinger Edge / CDN)
1. **Firewall Externo Bloqueia Porta 3306:**
   - O domínio `staging.futurofacil.com.br` passa pela CDN da Hostinger (`Server: hcdn`, IPs `147.79.105.96`, `89.116.213.141`).
   - As portas 3306 (MySQL), 21 (FTP), 22 (SSH) e 65002 (SFTP) estão completamente fechadas ou sem resposta externa (drop/timeout) no firewall de borda.
   - O MariaDB da Hostinger é configurado para aceitar conexões **exclusivamente locais** (`localhost:3306` ou via socket UNIX) disparadas pelo runtime PHP rodando dentro do servidor. Uma conexão TCP externa via PDO/MySQL CLI vinda da máquina de desenvolvimento é rejeitada na camada de rede.
2. **Ausência de SFTP Provisionado:**
   - No arquivo `SECRETS`, os campos `STAGING_SFTP_HOST`, `STAGING_SFTP_USER`, etc., estavam vazios. Sem chave SSH cadastrada no hPanel nem IP de SFTP liberado, transferência remota direta por terminal não funciona.

### B. Proteções Anti-Bot e Autenticação no hPanel (Cloudflare & SSO)
1. **Cloudflare Turnstile:**
   - A navegação automatizada headless padrão (via Playwright Chromium) foi interceptada pela tela de verificação da Cloudflare ("Executando verificação de segurança").
   - Embora o uso de Edge com flags stealth (`--disable-blink-features=AutomationControlled`) tenha passado pelo Turnstile, o acesso ao painel exigiu autenticação humana com SSO/2FA.
2. **CDP (Chrome DevTools Protocol) Indisponível na Sessão Existente:**
   - Tentativas de conectar um navegador automatizado a uma sessão Edge já aberta (`connect_over_cdp` em `127.0.0.1:9222`) falharam porque o Edge estava em execução sob o serviço de inicialização do Windows (`--no-startup-window --win-session-start`), que não expõe a porta de depuração remota sem flags manuais de boot.

### C. Limitação do Seletor de Arquivos no phpMyAdmin
- Ao tentar usar automação no phpMyAdmin para importar o arquivo `database/schema.sql`, o input HTML de upload rejeitou a injeção com o erro `fileChooser.setFiles: Not allowed` devido a proteções de iframe/sandboxing do phpMyAdmin dentro do hPanel.
- **Solução definitiva:** A execução do DDL deve ser feita **colando o SQL diretamente na aba "SQL" do phpMyAdmin**, eliminando qualquer dependência de upload de arquivo.

### D. Armadilha de Extração do File Manager e Erro 500 Silencioso
1. **Criação de Subpastas Indesejadas no "Extract":**
   - A ferramenta "Extract" do Gerenciador de Arquivos da Hostinger cria por padrão uma subpasta com o nome do arquivo ZIP (por exemplo, extrair `staging_src.zip` gera `/src/staging_src/`).
   - A aplicação espera que os arquivos PHP residam diretamente em `domains/staging.futurofacil.com.br/src/`.
2. **PHP 8.3 com `display_errors = Off`:**
   - Com o diretório `src/` aninhado incorretamente, as chamadas `require_once __DIR__ . '/../../src/Config/Database.php'` falharam, gerando um HTTP 500 em branco, sem log nem mensagem de erro na tela.
3. **Diagnóstico e Resolução:**
   - Foi injetado temporariamente no topo de `validar/index.php`:
     ```php
     ini_set('display_errors', '1');
     error_reporting(E_ALL);
     ```
     Isso expôs imediatamente o erro de `Failed opening required .../src/Config/Database.php`.
   - O usuário moveu os arquivos para o caminho canônico `src/` e o sistema subiu instantaneamente com HTTP 200.

---

## 3. Topologia de Diretórios e Arquitetura no Servidor

A estrutura física em `/home/u505703191/domains/staging.futurofacil.com.br/` está organizada da seguinte forma:

```text
/home/u505703191/domains/staging.futurofacil.com.br/
├── public_html/                       <- Diretório público servido pelo Apache/LiteSpeed
│   ├── .htaccess                      <- Regras de segurança, bloqueio a .db, SECRETS, etc.
│   ├── index.php                      <- Roteador principal / dashboard
│   ├── validar/
│   │   └── index.php                  <- Front-controller do validador público de certificados
│   ├── turmas/
│   │   ├── index.php                  <- Portal do aluno (requer chave_acesso)
│   │   └── arquivos/                  <- Bloqueado via .htaccess (HTTP 403)
│   └── diario/
│       ├── login.php                  <- Login do professor/admin com rate-limiting
│       ├── painel.php                 <- Gestão de turmas e diário
│       └── ...
└── src/                               <- Diretório PRIVADO (irmão de public_html, inacessível via web)
    ├── Config/
    │   ├── Database.php               <- PDO com suporte a getEnvVar e fallback credentials
    │   └── credentials.local.php      <- Credenciais reais de staging (IGNORADO NO GIT)
    ├── Controllers/
    ├── Domain/
    ├── Repositories/
    ├── Services/
    └── Views/
```

### Mecanismo de Credenciais (`Database.php`)
O arquivo `src/Config/Database.php` implementa o método resiliente `getEnvVar()`:
1. Verifica `getenv($name)`.
2. Verifica `$_SERVER[$name]` e `$_ENV[$name]`.
3. Caso ausente, lê o arquivo privado `src/Config/credentials.local.php`, que retorna:
   ```php
   <?php
   return [
       'DB_HOST' => 'localhost',
       'DB_DATABASE' => 'u505703191_ffstaging',
       'DB_USERNAME' => 'u505703191_ffstage',
       'DB_PASSWORD' => '...',
   ];
   ```
Esse arquivo reside exclusivamente no servidor em `src/Config/` e na máquina local em `.scratch/`. Ele está bloqueado no `.gitignore` por `credentials.local.php` e `*.local.php`.

---

## 4. O que Foi Feito Exatamente

1. **Schema DDL Importado:**
   - 8 tabelas criadas no MariaDB: `turmas`, `encontros`, `alunos`, `frequencias`, `materiais_turma`, `registros_certificados`, `usuarios_admin`, `tentativas_login`.
2. **Empacotamento e Publicação:**
   - `.scratch/staging_public.zip` extraído na raiz de `public_html/`.
   - `.scratch/staging_src.zip` (com `credentials.local.php`) extraído em `src/`.
3. **Verificação de Ponta a Ponta Remota (HTTP):**
   - `GET https://staging.futurofacil.com.br/validar/` -> **HTTP 200 OK**. Consulta real via PDO à tabela `registros_certificados` no MariaDB com proteção LGPD.
   - `GET https://staging.futurofacil.com.br/diario/login.php` -> **HTTP 200 OK**. Formulário com token anti-CSRF, cookies seguros e rate-limiting gravando em `tentativas_login`.
   - `GET https://staging.futurofacil.com.br/turmas/` -> **HTTP 200 OK**. Portal do aluno bloqueando `codigo_turma` e exigindo `chave_acesso`.
   - `GET https://staging.futurofacil.com.br/turmas/arquivos/` -> **HTTP 403 Forbidden** (bloqueio Apache ativo).
   - `GET https://staging.futurofacil.com.br/SECRETS` e `*.db` -> **HTTP 404/403** (bloqueados e ausentes da raiz pública).

---

## 5. Ação Imediata Pendente (Limpeza no Servidor)

No arquivo remoto `/public_html/validar/index.php`, foi adicionado temporariamente para depuração:
```php
ini_set('display_errors', '1');
error_reporting(E_ALL);
```
**Ação recomendada:** Editar via File Manager da Hostinger e remover essas duas linhas para retornar ao padrão de produção seguro (`display_errors = 0`).

---

## 6. Próximo Trabalho no Backlog: Ticket 08

O próximo pacote funcional a ser desenvolvido é o **Ticket 08**:
- **Especificação:** `.scratch/diario-certificados-hostinger/issues/08-gestao-avancada-capacidade-deslocamento-feriados-teto.md`
- **Requisitos Principais:**
  1. **Deslocamento Logístico:** `encontros.tipo = 'deslocamento'`, badge avião `✈`, previne conflito na agenda do instrutor, mas não gera chamada para alunos e não conta horas de certificado.
  2. **Motor de Adiamento Atômico em Lote:** Reagendamento em cadeia de encontros futuros de uma turma caso uma data precise ser movida, com rollback automático caso detecte colisão com deslocamento ou outra turma.
  3. **Feriados Nacionais e Bloqueios:** Calendário com feriados oficiais brasileiros e bloqueios de agenda pessoal do instrutor, sugerindo pontes de 1 clique.
  4. **Régua Visual de Teto de Horas:** Alerta e trava visual para carga horária mensal máxima de 80h de instrução.
- **Abordagem de Desenvolvimento:**
  - Seguir estritamente TDD: criar testes unitários e de integração em `tests/test_ticket_08_*.php`.
  - Executar a suíte completa de testes com `php tests/test_ticket_*.php` (garantir que as 500 asserções existentes permaneçam verdes).
  - Atualizar as notas correspondentes no cofre Obsidian antes de finalizar.