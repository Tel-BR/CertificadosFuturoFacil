# Handoff técnico: staging Hostinger (2026-09-17)

Este registro permite retomar o deploy de teste sem acessar a produção nem repetir a investigação. A fonte da verdade para decisões e planejamento continua sendo o cofre Obsidian indicado em `OBSIDIAN_FONTE_DA_VERDADE.md`.

## Código local concluído

Branch: `main`. Último commit observado: `8611413`.

| Commit | Mudança |
| --- | --- |
| `c2bd882` | Autenticação de aluno exige `chave_acesso`; `codigo_turma` não autentica. |
| `82b56db` | Roteador local único e bloqueio de `.db` e materiais físicos. |
| `50fcc75` | Emissão bloqueia reemissão e só confirma a transação após montar o ZIP. |
| `bae6f54` | `MaterialService` usa `CURRENT_TIMESTAMP`, compatível com SQLite/MariaDB. |
| `6451144` | Teste Excel edita o XLSX exportado sem reflexão. |
| `cc44371` | `SECRETS` incluído no `.gitignore`. |
| `8611413` | GET/HEAD de `SECRETS` retorna 403 no servidor local; Apache tem regra de bloqueio. |

As dez suítes `php tests/test_ticket_*.php` passaram em sequência após a última correção: 469 verificações com marca `✓` e 31 verificações adicionais nos três testes novos (500 no total). O arquivo `SECRETS` está na raiz **somente local**, contém as credenciais do banco de staging e foi confirmado como ignorado e não rastreado pelo Git. **Nunca enviar `SECRETS` à Hostinger nem adicioná-lo a um pacote.**

## Estado remoto confirmado

- O site PHP/HTML independente `staging.futurofacil.com.br` foi criado no hPanel, separado do site `futurofacil.com.br`. O gerenciador de arquivos específico do staging mostra `public_html` na raiz do site. Nenhum arquivo da aplicação foi enviado.
- O banco MariaDB vazio `u505703191_ffstaging` foi criado com o usuário exclusivo `u505703191_ffstage`. O phpMyAdmin confirmou **nenhuma tabela**. A senha está apenas no `SECRETS` local; não registrá-la neste documento, no Git ou em mensagens.
- A conexão DNS do novo site ainda não foi concluída. O assistente ofereceu uma alteração dos servidores DNS de todo `futurofacil.com.br`; ela **não foi aplicada**. A opção de registro `A` específico para `staging` foi apenas inspecionada. Confirmar o destino vigente no hPanel antes de alterar DNS.
- O acesso ao gerenciador de arquivos da produção foi rejeitado pela revisão automática; nenhum arquivo de produção foi lido ou alterado.
- A importação de `database/schema.sql` pelo seletor de arquivos do phpMyAdmin falhou com `fileChooser.setFiles: Not allowed`. Nenhuma instrução SQL foi executada no banco de staging.

## Pacotes locais prontos, ainda não publicados

Os arquivos `.scratch/staging_public.zip` (25 entradas) e `.scratch/staging_src.zip` (16 entradas) foram gerados com `git archive` do `HEAD`. Ambos foram inspecionados: não contêm `SECRETS`, bancos `.db`, testes ou `.git`. A extensão `.zip` é ignorada pelo Git.

Na instalação independente, o conteúdo de `staging_public.zip` deve ficar em `public_html/`; o conteúdo de `staging_src.zip` deve ficar em `src/`, **irmão de `public_html/`**. Isso preserva os caminhos `../../src` usados pelos scripts em `public_html/diario/`. Usar somente o gerenciador de arquivos do site de staging, não o do domínio principal.

## Próximos passos

1. Importar `database/schema.sql` no banco vazio `u505703191_ffstaging` e confirmar as oito tabelas. O arquivo contém DDL, sem dados de produção. Como o upload pelo navegador foi recusado, usar um método autorizado e documentar qual funcionou.
2. Publicar os dois pacotes nos diretórios acima por acesso ao **site staging**, verificando que `SECRETS` continua apenas local. Não copiar `SECRETS` para o servidor. Configurar `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD` no ambiente PHP de staging por mecanismo privado apropriado; confirmar esse mecanismo antes de usá-lo.
3. Configurar apenas o registro DNS do subdomínio, se necessário. Não alterar os servidores DNS do domínio principal.
4. Testar no staging: conexão MariaDB e operações de `MaterialService`; login de aluno somente com `chave_acesso`; GET/HEAD de `.db`, `SECRETS` e materiais físicos com 403; emissão única com ZIP íntegro e rollback quando a montagem falhar. Usar dados de teste no banco exclusivo de staging.
5. Registrar os resultados e eventuais diferenças de MariaDB no repositório, sem credenciais.

O deploy remoto e o teste real em MariaDB **ainda não foram concluídos**.
