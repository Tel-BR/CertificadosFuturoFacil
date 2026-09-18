# 07: Área Protegida de Conteúdo da Turma (Autenticação e Blindagem de Downloads)

**What to build:**
Permitir que os alunos de uma turma acessem os materiais didáticos, apostilas, exercícios e slides sob a rota `/turmas` exclusivamente após autenticação com a Chave de Acesso da Turma (definida pelo instrutor no painel). Os arquivos físicos armazenados no servidor são blindados contra download direto via link público por regras de `.htaccess` (`Deny from all`), sendo entregues unicamente através de um controlador de download PHP seguro que valida a sessão autorizada, protegendo integralmente os direitos autorais e a propriedade intelectual da Futuro Fácil.

**Blocked by:**
01: Fundação do Banco, Migração do Histórico e Validador Público LGPD
02: Autenticação Administrativa e Estrutura Base do Painel Web

**Status:** completed

- [x] A tabela `turmas` no MariaDB armazena o campo `chave_acesso` da turma para autenticação dos alunos.
- [x] No painel administrativo da turma (Tickets 02 / 04), o instrutor pode visualizar, definir ou alterar a Chave de Acesso da Turma e vincular os arquivos e links de materiais didáticos na tabela `materiais_turma`.
- [x] Ao acessar `/turmas` (ou `/turmas/<slug>`), o visitante não autenticado é recepcionado por tela de identificação solicitando a Chave de Acesso da Turma.
- [x] A inserção da chave correta inicializa uma sessão segura de aluno (`aluno_turma_autenticado`) com escopo restrito aos materiais daquela turma.
- [x] O portal do aluno exibe os materiais pedagógicos liberados (apostilas, planilhas de exercícios, roteiros e slides).
- [x] **Bloqueio Físico contra Download Direto (Costura de Teste 2):** Os arquivos físicos residem em diretório com regra `.htaccess` contendo `Deny from all`. Teste automatizado confirma que tentar acessar diretamente a URL do arquivo sem passar pelo PHP retorna **HTTP 403 Forbidden**.
- [x] O download de qualquer material é intermediado pelo controlador `download.php`, que verifica a sessão ativa do aluno antes de fazer o streaming seguro dos bytes do arquivo para o navegador com cabeçalhos apropriados.
- [x] Tentativas de acesso direto por URL ou com chave incorreta são bloqueadas e redirecionadas para a tela de autenticação da turma.

## Comments

- **2026-09-17:** Implementado e aprovado no commit `32c1bc6` com cobertura completa em `tests/test_ticket_07_protected_content.php`.

