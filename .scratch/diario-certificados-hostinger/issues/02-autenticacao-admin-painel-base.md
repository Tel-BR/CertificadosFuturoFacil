# 02: Autenticação Administrativa e Estrutura Base do Painel Web

**What to build:**
Permitir que o operador único realize login seguro no painel administrativo hospedado na Hostinger (`/diario/login`), com proteção de sessão por cookies blindados, proteção contra CSRF e proteção contra ataques de força bruta, acessando a área interna que servirá de casca de navegação para o Calendário (Futuro), Diário de Classe (Presente) e Turmas Concluídas (Passado). O diretório do painel é isolado e protegido contra indexação de motores de busca.

**Blocked by:**
01: Fundação do Banco, Migração do Histórico e Validador Público LGPD

**Status:** completed

- [x] A tela de login `/diario/login` solicita credenciais do operador e valida contra a tabela `usuarios_admin` utilizando `password_verify` com hash `bcrypt`.
- [x] O mecanismo de autenticação rejeita logins incorretos e aplica rate-limiting / bloqueio temporário após tentativas consecutivas falhas.
- [x] A sessão do operador é inicializada com flags de segurança reforçadas (`cookie_httponly = 1`, `cookie_secure = 1`, `cookie_samesite = Strict`) e sofre `session_regenerate_id(true)` no login bem-sucedido.
- [x] O escopo da sessão do operador (`usuario_admin`) é estritamente isolado da futura sessão de aluno (`aluno_turma_autenticado`).
- [x] Todas as páginas da pasta `/diario` (exceto `login.php`) possuem verificação de sessão obrigatória, redirecionando usuários não autenticados imediatamente para o login.
- [x] O diretório `/diario` é protegido contra indexação pública em motores de busca via cabeçalho HTTP `X-Robots-Tag: noindex, nofollow` e diretiva no `robots.txt`.
- [x] Todos os formulários administrativos contêm e validam token anti-CSRF por sessão.
- [x] O layout base administrativo responsivo é estabelecido, contendo barra superior institucional da Futuro Fácil, menu com os atalhos operacionais (Calendário, Turmas, Novo Agendamento) e botão de logout.
