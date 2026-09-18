# 12: Portal do Aluno — QR Code Projetável no Telão, Auto-Cadastro, Presença Automática e Governança de Correções Cadastrais

**What to build:**
Oferecer aos alunos em sala de aula (presencial ou remota via Teams/Zoom/Meet) um onboarding com atrito mínimo, máxima segurança contra vazamento de materiais e resolução definitiva da coleta e governança de dados cadastrais:
1. O professor ativa o "Modo Telão" no Modo Aula (`/diario/aula`) ou na tela da turma (`/diario/turma`) para projetar o QR Code vetorial grande em tela cheia com alto contraste;
2. O painel de projeção conta com 3 seletores reativos para o instrutor: `Coletar CPF`, `Coletar WhatsApp` e `Registrar Presença Automática` (para o encontro ativo de hoje); ao alterar os seletores, o QR Code e a URL se atualizam em tempo real;
3. O aluno escaneia o código pelo celular e acessa a página de recepção `/turmas/entrar?turma=<slug>`, informando Nome Completo e E-mail (obrigatórios), além de CPF (opcional, com validação matemática módulo 11 e mascaramento LGPD) e WhatsApp (opcional, com máscara dinâmica) conforme habilitados pelo professor;
4. O formulário é protegido contra abusos por barreira anti-robô multicamada: Cloudflare Turnstile, Honeypot inteligente, token CSRF de sessão e rate-limit de no máximo 3 envios por IP a cada 10 minutos (HTTP 429);
5. O sistema executa reconciliação inteligente: se o aluno já constar na turma (match por CPF, E-mail ou Nome), completa os dados faltantes sem duplicar o registro; se for novo, cadastra-o como aluno regular da turma;
6. Se a presença automática estiver ativa no telão, o sistema registra imediatamente `presente = 1` na chamada do encontro de hoje;
7. A sessão protegida do aluno é inicializada imediatamente, redirecionando o aluno na hora para os materiais didáticos da turma no celular (fricção zero), enquanto dispara em segundo plano um e-mail transacional formatado contendo a Chave de Acesso da Turma e link direto seguro para acessos futuros em outros dispositivos;
8. Governança e Correção Contínua: o aluno pode visualizar seus dados e enviar solicitações de correção de Nome, CPF ou WhatsApp diretamente pelo portal `/turmas`; o professor visualiza as pendências em um card com diff visual (Antes → Depois) no topo de `/diario/turma` com botões rápidos `[ Aprovar ]` e `[ Recusar ]`;
9. Soberania do Instrutor: o professor mantém acesso permanente para editar diretamente Nome, CPF, E-mail e Telefone de qualquer aluno na lista da turma a qualquer momento, inclusive após o fechamento e emissão de certificados, sincronizando o assento digital para permitir a reemissão imediata de certificados corrigidos.

**Blocked by:**
07: Área Protegida de Conteúdo da Turma (Autenticação e Blindagem de Downloads)
09: Gerenciamento Completo de Turmas, Modo Multi-Seleção no Calendário e Override de Encontros

**Status:** done

- [x] Na tela `/diario/aula` e `/diario/turma`, um botão **"Projetar no Telão (QR Code)"** abre uma visualização em tela cheia (estilo apresentação, fundo escuro/alto contraste) exibindo o QR Code vetorial SVG grande, o nome do curso, instruções claras e URL alternativa de digitação.
- [x] No painel do Telão, 3 seletores rápidos táteis (`Coletar CPF`, `Coletar WhatsApp`, `Registrar Presença Automática`) permitem ao instrutor configurar a recepção na hora, atualizando dinamicamente os parâmetros do QR Code e do link.
- [x] Ao escanear o QR Code, o aluno é direcionado para `/turmas/entrar?turma=<slug>` com uma interface limpa, rápida e responsiva otimizada para smartphones.
- [x] A tela solicita Nome Completo e E-mail obrigatoriamente, exibindo os campos de CPF (com validação de dígitos verificadores módulo 11 e máscara) e WhatsApp (com máscara) se ativados pelo instrutor.
- [x] **Barreira Anti-Robô e Rate-Limiting:** O formulário é protegido contra abusos por Cloudflare Turnstile (com fallback para testes automatizados), Honeypot inteligente, token CSRF de sessão e rate-limit de no máximo 3 envios por IP a cada 10 minutos (retornando HTTP 429 em caso de abuso).
- [x] **Reconciliação Inteligente:** Se o aluno já constar na turma (busca por CPF limpo, E-mail ou Nome), completa os dados sem duplicar; senão, cadastra como aluno regular na tabela `alunos`.
- [x] **Presença Automática:** Se o seletor estiver ativado, o sistema grava presença (`presente = 1`) para o aluno no encontro ativo de hoje em `frequencias`.
- [x] **Acesso Imediato:** Ao submeter com sucesso, a sessão segura do aluno (`aluno_turma_autenticado`) é ativada na hora e o navegador redireciona para `/turmas/<slug>` liberando acesso e download blindado dos materiais didáticos.
- [x] **E-mail Transacional de Apoio:** O sistema dispara em segundo plano um e-mail transacional institucional contendo a Chave de Acesso da Turma e o link seguro para acessos futuros em outros dispositivos.
- [x] **Solicitação de Correção pelo Aluno:** No portal `/turmas`, card exibe os dados cadastrais do aluno para o certificado com botão para sugerir correção de Nome, CPF ou WhatsApp, gravando pendência em `solicitacoes_correcao_aluno`.
- [x] **Aprovação Assistida pelo Professor:** Na tela `/diario/turma`, card de destaque exibe as solicitações pendentes com comparação lado a lado (Antes → Depois) e botões de 1 clique `[ Aprovar ]` (aplica em `alunos`) e `[ Recusar ]`.
- [x] **Edição Direta e Soberania do Instrutor:** O professor pode editar diretamente qualquer aluno na tabela de `/diario/turma` a qualquer momento (inclusive após fechamento e emissão de certificados, sincronizando com `registros_certificados` para viabilizar reemissão imediata).