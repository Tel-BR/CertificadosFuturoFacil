# 12: Portal do Aluno — QR Code Projetável no Telão e Auto-Acesso com Barreira Anti-Robô

**What to build:**
Oferecer aos alunos em sala de aula um onboarding com atrito mínimo e máxima segurança contra vazamento de materiais: o professor ativa o "Modo Telão" no Modo Aula ou na tela da turma para projetar o QR Code em tela cheia com alto contraste; o aluno escaneia o código pelo celular e acessa a página de recepção onde informa unicamente seu e-mail e resolve uma barreira anti-robô (Cloudflare Turnstile ou Honeypot com Rate-Limit estrito); o sistema envia automaticamente um e-mail transacional com o link direto e o código de acesso da turma, blindando os downloads da propriedade intelectual contra acessos não autorizados por terceiros.

**Blocked by:**
07: Área Protegida de Conteúdo da Turma (Autenticação e Blindagem de Downloads)
09: Gerenciamento Completo de Turmas, Modo Multi-Seleção no Calendário e Override de Encontros

**Status:** ready-for-agent

- [ ] Na tela `/diario/aula` e `/diario/turma`, um botão **"Projetar no Telão (QR Code)"** abre uma visualização em tela cheia (estilo apresentação, fundo escuro ou alto contraste) exibindo o QR Code vetorial grande, o nome do curso e instruções claras para os alunos escanearem.
- [ ] Ao escanear o QR Code, o aluno é direcionado para a rota `/turmas/entrar?turma=<slug>` com uma interface limpa, rápida e responsiva otimizada para smartphones.
- [ ] A tela solicita exclusivamente o **E-mail do Aluno**, eliminando senhas e cadastros burocráticos prévios.
- [ ] **Barreira Anti-Robô e Rate-Limiting:** O formulário é protegido contra abusos por Cloudflare Turnstile (com fallback para Honeypot inteligente + token de sessão + rate-limit de no máximo 3 envios por IP a cada 10 minutos).
- [ ] Ao submeter o e-mail válido, o sistema dispara um e-mail transacional formatado contendo a Chave de Acesso da Turma e o link seguro para acesso ao portal `/turmas`.
- [ ] Se o aluno já constar na lista de matriculados da turma, o e-mail confirma seu vínculo oficial; se não constar, permite acesso como ouvinte/participante identificado para download dos materiais didáticos daquele curso.
- [ ] A inserção da chave recebida por e-mail inicializa a sessão protegida do aluno e libera o acesso aos materiais didáticos com download blindado (regras de segurança já estabelecidas no Ticket 07).