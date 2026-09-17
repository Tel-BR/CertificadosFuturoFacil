# Especificação — Plataforma Integrada de Gestão Pedagógica, Calendário, Certificados e Conteúdos de Turmas

## Problem Statement

A Futuro Fácil operava a emissão de certificados por meio de uma ferramenta isolada em Python/Streamlit, restrita à apuração pós-turma via planilhas estáticas e hospedada no Streamlit Community Cloud. 

Esse arranjo gerou quatro problemas operacionais e de segurança críticos:
1. **Descontinuidade Operacional:** O instrutor não possuía ferramenta em tempo real para controle de presença e alimentação do plano de aula durante o andamento da turma, acumulando edições manuais em planilhas que já provocaram omissões de certificados e inconformidades de frequência (incidente do Ticket #001).
2. **Falta de Gestão de Capacidade Futura:** Não havia um calendário centralizado com controle de turnos (Matutino, Vespertino, Noturno e Integral) para planejar turmas confirmadas, checar disponibilidades do instrutor e prevenir choques de agenda.
3. **Vulnerabilidade de Infraestrutura e LGPD:** O uso de containers efêmeros em nuvem pública gratuita, somado à necessidade de abrir portas do banco de dados na internet para conexões remotas, violava as melhores práticas de segurança e colocava em risco a privacidade de dados de alunos (CPFs e nomes completos).
4. **Vazamento de Propriedade Intelectual em `/turmas`:** Os materiais didáticos, apostilas e exercícios publicados no website institucional residiam em diretórios estáticos públicos sem autenticação, permitindo que links diretos fossem acessados e baixados por terceiros não autorizados.

## Solution

Criar uma plataforma web unificada hospedada nativamente no ambiente Hostinger (PHP 8.x + MariaDB em `localhost`), que consolida as três dimensões temporais da rotina pedagógica e a proteção de conteúdo em um ecossistema seguro:

1. **O Futuro (Calendário de Capacidade):** Calendário anual (12 meses) e mensal com identificação clara de turnos (`[M]`, `[V]`, `[N]`, `[D]`), sem confusão cromática para daltônicos, datas passadas desbotadas, rollover dinâmico exibindo cursos/horários comprometidos e bloqueio de choques de horário.
2. **O Presente (Diário de Classe Mobile):** Modo Aula responsivo para uso pelo smartphone em sala de aula, com plano de aula dinâmico (conteúdo previsto pré-carregado e editável na hora), botão "Marcar Todos Presentes", alternância binária ágil de faltas, recálculo imediato da frequência acumulada e sincronização bidirecional em Excel (.xlsx).
3. **O Passado (Certificação e Validação Perpétua):** Fechamento assistido com abono coletivo de aula pontual, cálculo de frequência mínima (75%), emissão em lote de certificados duplex vetoriais (guilloché, ementa, QR Code e hash SHA-256) em ZIP, livro de registro contínuo e validação pública perpétua com dados mascarados (LGPD), sem qualquer quebra ou necessidade de reemissão dos certificados anteriores da turma Sicoob.
4. **Proteção de Conteúdo da Turma:** Controle de acesso sob a rota `/turmas` com Chave de Acesso da Turma para alunos e blindagem física dos arquivos no servidor via `.htaccess` (`Deny from all`), com download mediado exclusivamente por controlador PHP autenticado.

---

## User Stories

### Dimensão 1: O Futuro (Calendário e Agendamento)

1. Como instrutor, quero visualizar uma grade anual de 12 meses, para que eu possa ter um panorama estratégico da distribuição das minhas turmas ao longo de todo o ano.
2. Como instrutor, quero visualizar uma grade mensal detalhada, para que eu possa planejar as aulas e compromissos das próximas semanas.
3. Como instrutor com daltonismo, quero que os indicadores de turno no calendário usem posições dedicadas, letras claras (`[M]`, `[V]`, `[N]`, `[D]`) e alto contraste, para que eu nunca confunda turnos por conflito de cores azul e roxo.
4. Como instrutor, quero que as datas anteriores ao dia atual apareçam automaticamente em tom neutro e desbotado, para que minha atenção visual fique focada no presente e no futuro.
5. Como instrutor, quero passar o cursor (ou dar um toque mobile) sobre qualquer data do calendário e ver um popover com os cursos, clientes e horários ocupados, para que eu saiba rapidamente quais compromissos tenho naquele dia.
6. Como instrutor, quero identificar imediatamente se um dia com aula de manhã ainda possui a tarde ou a noite livres, para que eu possa agendar novas turmas corporativas sem choque de agenda.
7. Como instrutor, quero receber um alerta impeditivo ao tentar agendar uma turma em um turno já ocupado por outra turma confirmada, para que eu evite duplicidade de compromissos.
8. Como instrutor, quero clicar em uma data com turma agendada e ser direcionado diretamente para a chamada/diário daquele encontro, para que eu não perca tempo navegando por menus.
9. Como instrutor, quero clicar em uma data livre e ter a opção de agendar uma nova turma ou lançar uma aula extraordinária/reposição para uma turma existente, para que minha agenda seja flexível a imprevistos.

### Dimensão 2: O Presente (Diário de Classe em Sala de Aula)

10. Como instrutor em sala de aula, quero acessar o Modo Aula pelo meu smartphone, para que eu possa fazer a chamada com conforto e agilidade sem precisar de notebook.
11. Como instrutor, quero que o campo de conteúdo da aula já venha pré-preenchido com o conteúdo previsto para aquele encontro, para que eu só precise ajustá-lo caso a dinâmica da aula mude.
12. Como instrutor, quero poder digitar o conteúdo ministrado do zero caso a aula não tenha tido previsão prévia, para que eu registre o diário pedagógico fielmente.
13. Como instrutor, quero clicar em um botão "Marcar Todos Presentes", para que a presença de todos os 30 ou 40 alunos seja preenchida com um único toque.
14. Como instrutor, quero alternar a presença de um aluno individual entre Presente e Falta com um toque simples, para que eu registre ausências sem atrito.
15. Como instrutor, quero ver a porcentagem de frequência acumulada de cada aluno atualizada em tempo real na própria lista, para que eu identifique instantaneamente quem atingiu a zona de risco (< 75%).
16. Como instrutor, quero salvar o diário do dia e receber confirmação visual imediata, para que eu tenha certeza de que a chamada e o plano foram gravados no banco.
17. Como instrutor, quero exportar a planilha Excel (.xlsx) da turma contendo as abas *Alunos e Chamada*, *Diário e Planos* e *Dados da Turma*, para que eu possa trabalhar ou analisar os dados offline.
18. Como instrutor, quero editar nomes, faltas ou planos diretamente na planilha Excel e reimportá-la para o sistema, para que as alterações sincronizem com o banco sem duplicar alunos ou criar inconsistências.

### Dimensão 3: O Passado (Fechamento, Certificados e Validação)

19. Como instrutor ao final do curso, quero abrir a tela de Fechamento Assistido da turma, para que eu faça a auditoria final das presenças antes de emitir os certificados.
20. Como instrutor, quero poder acionar um botão "Abonar Aula para Toda a Turma", para que aulas prejudicadas por feriados ou eventos institucionais recebam 100% de presença coletiva sem necessidade de edição aluno por aluno.
21. Como instrutor, quero visualizar a separação nítida entre Alunos Aptos ($\ge 75\%$) e Alunos Inaptos ($< 75\%$), para que eu saiba exatamente quem tem direito a receber o certificado.
22. Como instrutor, quero poder autorizar uma justificativa manual extraordinária para um aluno com frequência inferior a 75%, para que decisões pedagógicas da coordenação possam ser contempladas.
23. Como instrutor, quero clicar em "Confirmar e Emitir Certificados" e ter os assentos no Livro de Registro Digital gerados atomicamente com numeração sequencial contínua de Livro, Folha e Registro.
24. Como instrutor, quero que cada certificado seja renderizado em PDF duplex A4 paisagem vetorial de alta qualidade gráfica (frente com moldura guilloché e assento digital; verso com ementa oficial, QR Code e hash SHA-256).
25. Como instrutor, quero baixar um pacote ZIP único contendo todos os certificados individuais, o PDF consolidado para gráfica e a planilha atualizada do Livro de Registro.
26. Como ex-aluno ou empregador, quero escanear o QR Code de um certificado emitido anteriormente no Streamlit (como os da turma Sicoob), para que a autenticidade seja confirmada com 100% de sucesso através do redirecionamento automático para a rota canônica.
27. Como cidadão consultando a autenticidade, quero visualizar os dados do aluno de forma mascarada pela LGPD (`A** L**** S***** N*********` e `***.123.456-**`), para que a privacidade pessoal seja rigorosamente resguardada.

### Dimensão 4: Proteção de Conteúdo das Turmas

28. Como instrutor, quero definir uma Chave de Acesso da Turma no painel de controle, para que eu possa compartilhá-la com os alunos no primeiro dia de aula.
29. Como aluno, quero acessar `/turmas` e digitar a chave da minha turma, para que eu acesse os slides, apostilas e exercícios daquele curso.
30. Como proprietário da Futuro Fácil, quero que qualquer tentativa de baixar arquivos didáticos diretamente pela URL sem sessão autenticada seja sumariamente bloqueada pelo servidor, para que meu material didático não seja copiado ou distribuído sem autorização.

### Dimensão 5: Apoio ao Faturamento e Emissão de NFS-e

31. Como instrutor ao fechar uma turma, quero visualizar o valor financeiro total apurado (hora-aula x carga horária total ou valor global fixo) e a discriminação de serviços completa e formatada com a listagem automática de todas as datas reais dos encontros, carga horária diária (h/dia), ordem de serviço e dados do cliente, com um botão "Copiar Descrição para NFS-e", para que eu possa emitir a Nota Fiscal na prefeitura em segundos, sem retrabalho manual ou erros de conferência.


---

## Implementation Decisions

### 1. Custódia do Código e Estrutura Multi-repo
- O repositório `FuturoFacilWebsite` permanece exclusivo para a presença institucional e páginas comerciais.
- O repositório operacional (`CertificadosFuturoFacil` / evolução) abriga o sistema completo de Diário, Calendário, Certificados e Validador.
- Na infraestrutura Hostinger: o website reside na raiz `/public_html` e a aplicação operacional é implantada em `/public_html/diario` (com validação em `/public_html/validar` e portal de materiais em `/public_html/turmas`).

### 2. Arquitetura de Banco de Dados (MariaDB em `localhost`)
- O MariaDB opera estritamente em `127.0.0.1` / socket local, com a porta TCP 3306 bloqueada externamente para a internet pública.
- Tabelas relacionais fundamentais:
  - `turmas`: metadados da turma, cliente, carga horária, datas, turno padrão, status e `chave_acesso` para alunos;
  - `encontros`: sessões de aula, data, turno, horários, conteúdo previsto, conteúdo ministrado e flag de abono;
  - `alunos`: dados cadastrais sanitizados (nome completo, CPF formatado e limpo);
  - `frequencias`: tabela de junção relacional com constraint UNIQUE `(encontro_id, aluno_id)` e booleano de presença;
  - `materiais_turma`: catálogo de arquivos e links didáticos associados à turma;
  - `registros_certificados`: Livro de Registro Digital com índice UNIQUE no `codigo_autenticidade` (SHA-256) e constraint de unicidade sequencial `(livro_numero, folha_numero, registro_numero)`;
  - `usuarios_admin`: credenciais administrativas do operador único com senhas em `bcrypt`.

### 3. Autenticação e Segurança
- **Painel Administrativo (`/diario`):** Monousuário, protegido por sessão segura (`session_regenerate_id`), cookies blindados (`HttpOnly`, `Secure`, `SameSite=Strict`), tokens anti-CSRF em todos os formulários e rate-limiting contra ataques de força bruta.
- **Portal do Aluno (`/turmas`):** Autenticação por Chave de Acesso da Turma gerando cookie de sessão restrito àquela turma (`aluno_turma_autenticado`).
- **Blindagem de Arquivos Didáticos:** Diretório de arquivos com regra de servidor `.htaccess` contendo `Deny from all`. O streaming de download é executado por script PHP intermediário (`download.php`) após validação da sessão do aluno.

### 4. Motor de Renderização de Certificados em PHP
- Utilização de biblioteca de PDF vetorial de alta performance (TCPDF ou FPDF estendido) para reproduzir fielmente os elementos gráficos do ReportLab:
  - Anverso: Fundo off-white (`#FAFCFF`), moldura geométrica de guilloché com senóides vetoriais, wordmark `FUTUROFÁCIL`, card do assento digital e texto de concessão;
  - Reverso: Ementa programática estruturada, card do livro/folha/registro, QR Code vetorial nativo apontando para a rota canônica `/validar?validar=<HASH>` e caixa do hash SHA-256 dividido em blocos monoespaçados.

### 5. Garantia de Não-Regressão e Ponte de Validação Streamlit
- Migração prévia de 100% dos assentos existentes no `registros.db` (incluindo os 39 certificados do Sicoob) diretamente para a tabela `registros_certificados` no MariaDB.
- Configuração de ponte de redirecionamento imediato no app legado do Streamlit Cloud: requisições com parâmetro `?validar=<HASH>` são redirecionadas com código HTTP 301 para `https://futurofacil.com.br/validar?validar=<HASH>`.

---

## Testing Decisions

### O que constitui um bom teste
Os testes devem validar o **comportamento observável de ponta a ponta** e as garantias de negócio, nunca detalhes internos de implementação ou chamadas de métodos privados.

### Costuras de Teste (*Testing Seams*)

1. **Costura 1: Borda de Requisição HTTP (Validação Pública e Não-Regressão)**
   - *Teste:* Enviar requisições HTTP para a rota pública com códigos de autenticidade dos 39 certificados reais da turma Sicoob.
   - *Comportamento esperado:* Resposta HTTP 200 contendo status autêntico e os dados do aluno rigorosamente mascarados pela LGPD. Nenhuma falha em certificados anteriores.
2. **Costura 2: Borda de Segurança de Arquivos e Autenticação**
   - *Teste:* Tentar requisição HTTP direta para a URL física de uma apostila em `/turmas/arquivos/apostila.pdf` sem autenticação.
   - *Comportamento esperado:* Resposta HTTP 403 Forbidden do servidor web.
   - *Teste:* Enviar chave de turma válida via POST e requisitar o download pelo controlador.
   - *Comportamento esperado:* Download liberado com cabeçalhos `Content-Type: application/pdf` e status 200.
3. **Costura 3: Borda Transacional de Chamada e Fechamento**
   - *Teste:* Executar chamada com 30 alunos via Modo Aula com 1 clique de presença total e desmarcar 2 faltas.
   - *Comportamento esperado:* Gravação atômica em transação única no banco; cálculo instantâneo das frequências acumuladas.
4. **Costura 4: Borda de Sincronização Excel (.xlsx)**
   - *Teste:* Gerar exportação de turma fictícia em `.xlsx`, modificar presenças e planos na planilha, reimportar e validar se o banco foi atualizado de forma idempotente sem duplicidades.
5. **Costura 5: Borda de Conflito de Agenda**
   - *Teste:* Tentar criar um agendamento no mesmo dia e turno de uma turma já confirmada.
   - *Comportamento esperado:* Rejeição da gravação e exibição de alerta de choque de horário.

---

## Out of Scope

- Pagamento online ou checkout de cursos no sistema de turmas.
- Múltiplos logins administrativos concorrentes com permissões RBAC granuladas (o sistema é estritamente monousuário para o operador institucional).
- Plataforma de videoconferência ou streaming de aulas ao vivo embutido.
- Suporte a múltiplos idiomas ou moedas estrangeiras.

---

## Further Notes

- O design do calendário deve seguir rigorosamente as regras de acessibilidade para daltônicos, garantindo que o operador consiga discernir os turnos em qualquer monitor ou iluminação de sala de aula.
- O script de carga de dados fictícios (`database/seeds/test_data_seed.php`) é componente mandatório para permitir que o usuário homologue o sistema visualmente antes de operar turmas reais.
