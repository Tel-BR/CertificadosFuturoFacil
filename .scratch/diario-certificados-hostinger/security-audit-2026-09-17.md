---
tipo: auditoria_seguranca
ciclo_vida: ativo
rag: incluir
sensibilidade: restrita
canonico: false
atualizado_em: 2026-09-17
fonte_repositorio: 'C:\Users\Admin\Documents\CertificadosFuturoFacil'
fonte_commit: "cf00731"
estado: aguardando_confirmacao_item_a_item
---

# Auditoria de Segurança — 17 de setembro de 2026

> [!source] Cópia técnica. A fonte da verdade é a nota homônima no cofre Obsidian indicado em OBSIDIAN_FONTE_DA_VERDADE.md.

> [!danger] Estado e decisão
> Auditoria somente de leitura. Nenhuma correção foi autorizada ou aplicada. A decisão sobre cada achado permanece pendente, começando por R1 e R2. Qualquer mudança de guard-server exige revisão específica do risco de indisponibilidade antes da execução.

## Resumo

A revisão estática encontrou dois riscos urgentes: um lote histórico versionado com 40 nomes, CPFs completos e códigos de autenticidade; e senhas administrativas padrão no seed PHP e no aplicativo Streamlit legado, cuja ativação em hospedagem não foi confirmada. Não se deve repetir neste cofre nomes, CPFs, códigos nem valores de senha.

Este relatório substitui a **avaliação de risco** do relatório histórico em `.scratch/diario-certificados-hostinger/security-audit-report.md`, que afirmava não haver achados críticos. Não substitui evidências de testes então realizados. A implantação atual pode diferir do código desta cópia de referência.

## Escopo e método

- Código PHP 8.3, rotas públicas e administrativas, esquema MariaDB, arquivos `.htaccess`, histórico Git, documentação de staging e código Python legado presentes na cópia técnica.
- Skills consultadas: `security-audit` (netresearch), `guard-audit` (lunacy-studio), `mariadb-features` e `mariadb-system-versioned-tables`.
- Checklist PHP/OWASP aplicado aos componentes existentes; verificações específicas de TYPO3 e infraestrutura não presente no repositório são inaplicáveis.
- Inspeção estática e comandos somente de leitura. As suítes existentes foram lidas e não executadas, pois criam arquivos e escrevem em bancos de teste.
- Tentativa de consulta HTTP HEAD ao staging falhou por indisponibilidade de conexão. Não houve acesso ao MariaDB, hPanel, conta de aluno nem conta admin. A visibilidade do repositório remoto não foi confirmada.
- `git status --porcelain` estava vazio ao terminar a inspeção. Esta nota é registro, não correção.

## Matriz de risco de negócio — guard-audit

| ID | Prazo | Achado e dano possível | Evidência técnica | Guardrail | Confiança |
| --- | --- | --- | --- | --- | --- |
| R1 | 🔴 Urgente hoje | Lote histórico no Git contém 40 registros de nomes e CPFs completos, além de códigos que podem continuar válidos. Pessoas com cópia do repositório podem acessar dados de alunos. | `database/seeds/migracao_sicoob_historico.sql`; arquivo e histórico Git confirmados. | guard-data; guard-server para custódia do repositório | Confirmado no repositório; alcance externo pendente |
| R2 | 🔴 Urgente hoje | Seed PHP e aplicação Streamlit legada têm senhas administrativas padrão. Se alguma estiver ativa, há risco de acesso administrativo indevido. | `database/seeds/admin_seed.php:15`; `app.py:295`. | guard-auth | Código confirmado; ativação no servidor pendente |
| O1 | 🟠 Esta semana | Chave compartilhada da turma e consulta por CPF permitem consultar certificado de colega. Condição por CPF parcial OU nome também pode selecionar pessoa errada. | `public/turmas/index.php:91-111,840-852`. | guard-auth e guard-data | Código confirmado; sem teste autenticado |
| O2 | 🟠 Esta semana | Tentativas de chave do aluno são contadas só na sessão; uma nova sessão reinicia o atraso. Chave mínima de três caracteres. | `src/Services/AuthService.php:411-452`; `src/Services/MaterialService.php:315-322`. | guard-auth | Confirmado |
| O3 | 🟠 Esta semana | Validador público não limita requisições nem rejeita explicitamente entradas de tipo array. Códigos SHA-256 com nonce aleatório são difíceis de adivinhar, mas o endpoint não controla volume. | `public/validar/index.php:25-32`; `src/Services/ValidatorService.php:177-222`. | guard-server e guard-auth | Confirmado no código |
| O4 | 🟠 Esta semana | Resposta pública mostra iniciais/comprimento do nome, seis dígitos centrais do CPF e vários metadados; combinação pode reidentificar titular com código conhecido. | `public/validar/index.php:460-513`. | guard-data | Confirmado |
| O5 | 🟠 Esta semana | Autologin usa `?chave=`; a requisição inicial pode constar em histórico e registros do servidor/CDN apesar do redirecionamento. | `public/turmas/index.php:31-51`. | guard-auth e guard-server | Confirmado no código; logs pendentes |
| O6 | 🟠 Esta semana | Renovação do cookie do aluno para 30 dias omite SameSite e o campo `expira_em` da sessão não é consultado pela autorização. | `src/Services/AuthService.php:320-373`. | guard-auth | Confirmado |
| O7 | 🟠 Esta semana | Login admin aceita cabeçalhos de IP sem prova de proxy confiável e a tabela de tentativas não tem chave única IP/usuário. Pode fragilizar o bloqueio. | `src/Services/AuthService.php:455-465`; `database/schema.sql:184-194`. | guard-auth e guard-server | Impacto condicionado ao proxy |
| O8 | 🟠 Esta semana | Handoff informa `display_errors=1` temporário no arquivo remoto do validador. Erros podem revelar detalhes internos. | `docs/hostinger-staging-handoff.md:124-132`. | guard-server | Remoto não verificado |
| O9 | 🟠 Esta semana | Privilégios reais da conta MariaDB não comprovados. Código tem fallback para `root`, enquanto o handoff descreve usuário próprio de staging. | `src/Config/Database.php:77-102`; `docs/hostinger-staging-handoff.md:90-105`. | guard-data | GRANTs não verificados |
| O10 | 🟠 Esta semana | `.htaccess` define HSTS em HTTPS, mas o redirecionamento de HTTP depende da borda Hostinger, não verificada. Mudanças podem afetar subdomínios. | `public/.htaccess:11-30`. | guard-server | Condicionado ao deploy |
| Y1 | 🟡 Backlog | CSP somente no validador, ausente nas páginas admin/aluno. Defesa adicional; XSS explorável não confirmado. | `public/validar/index.php:13`; `public/.htaccess`. | guard-server | Confirmado no código |
| Y2 | 🟡 Backlog | Upload administrativo valida extensão, mas não conteúdo e tamanho no serviço; aceita `copy()` fora do fluxo HTTP. | `src/Services/MaterialService.php:160-207`. | guard-server | Confirmado |
| Y3 | 🟡 Backlog | Datas do registro são VARCHAR, há índices redundantes em colunas UNIQUE e busca por chave envolve `LOWER(TRIM(...))`, podendo perder uso do índice. | `database/schema.sql:132-177`; `src/Services/AuthService.php:265-271`. | guard-data | Confirmado no esquema |
| Y4 | 🟡 Backlog | Não há prova disponível de restauração de backup nem registro persistente das consultas no validador PHP. Isso não demonstra ausência de backup na Hostinger. | `src/Services/ValidatorService.php:177-222`; documentação disponível. | guard-data | Verificação pendente |
| Y5 | 🟡 Backlog | Arquivo local ignorado `.scratch/staging_src.zip` contém `credentials.local.php`; sua custódia e localização no servidor devem ser verificadas. | Arquivo ZIP inspecionado apenas por nomes de entrada; `.gitignore`. | guard-server | Presença local confirmada |
| Y6 | 🟡 Backlog | Dependências do aplicativo Python legado têm limites mínimos sem lockfile; versão exata implantada não é reproduzível a partir do repositório. | `requirements.txt`. | guard-server | Confirmado; deploy legado pendente |

## Verificações pedidas

### Validação pública

- Consulta preparada com parâmetro `?` e PDO com emulação desligada; não foi encontrada concatenação de entrada nesse SQL.
- Não há rate limiting no PHP do validador.
- Nome e CPF são parcialmente mascarados, mas a resposta contém dados além do mínimo para atestar autenticidade.
- O código de autenticidade de nova emissão usa SHA-256 sobre payload com nonce de `random_bytes(16)`; a falta de limite não implica viabilidade de varredura sequencial de todo o espaço de 256 bits.

### Login e segregação

- Admin: hash bcrypt no seed e verificação via `password_verify`; bloqueio após cinco erros por par IP/usuário; token CSRF e regeneração de sessão.
- Aluno: acesso por chave compartilhada da turma, sem senha individual bcrypt/Argon2.
- Rastreamento estático de ID/parâmetro/token: rotas `/diario` exigem chave de sessão administrativa antes de ler dados; download compara turma do material à turma da sessão. Não foi encontrado caminho de escalada de aluno a admin por parâmetro. Falta teste autenticado no ambiente real.
- Não se confirmou SQL injection, CSRF em ações administrativas examinadas, path traversal no download, LFI, type juggling explorável ou injeção de cabeçalhos. A conclusão vale para os caminhos revisados e não é certificação do deploy.

## MariaDB

- Pontos positivos no esquema: InnoDB, `utf8mb4`, chaves estrangeiras, unicidade de `codigo_autenticidade` e do assento `(livro_numero, folha_numero, registro_numero)`.
- Privilégio mínimo: executar somente leitura `SELECT CURRENT_USER(); SHOW GRANTS FOR CURRENT_USER;` na conexão efetiva quando houver acesso autorizado. Usuário não-root em documentação não comprova GRANTs.
- `mariadb-secure-installation`: execução e estado não verificáveis pela cópia; em hospedagem compartilhada o controle pertence à Hostinger. Confirmar com a provedora, sem executar alterações na instância.
- System-Versioned Tables podem auxiliar em retificações futuras do Livro de Registro Digital, mas não registram consultas SELECT e não são histórico imutável por si só: o MariaDB prevê `DELETE HISTORY` com privilégio específico. O Livro atual é predominantemente append-only; decidir somente após confirmar versão da engine, política de retenção, backups e restauração. `mariadb-dump --dump-history` existe a partir de 10.11.

## Fontes de referência

- OWASP: https://cheatsheetseries.owasp.org/cheatsheets/SQL_Injection_Prevention_Cheat_Sheet.html
- OWASP: https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html
- PHP `setcookie`: https://www.php.net/function.setcookie.php
- MariaDB `SHOW GRANTS`: https://mariadb.com/docs/server/reference/sql-statements/administrative-sql-statements/show/show-grants
- MariaDB `mariadb-secure-installation`: https://mariadb.com/docs/server/clients-and-utilities/deployment-tools/mariadb-secure-installation
- MariaDB System-Versioned Tables: https://mariadb.com/docs/server/reference/sql-structure/temporal-tables/system-versioned-tables

## Próxima decisão

Começar por **R1**, apresentar escopo concreto de contenção e solicitar confirmação específica antes de qualquer alteração. Em seguida tratar **R2**. Nenhuma correção, limpeza de histórico Git, mudança de credencial, ajuste no servidor ou migração foi autorizada por esta auditoria.

