# 13: Faturamento, Dados Financeiros e Gerador Assistido de Texto para NFS-e

**What to build:**
Prover à gestão administrativa e financeira da Futuro Fácil um módulo integrado de faturamento por turma: persistir dados cadastrais e contratuais do tomador do serviço (Razão Social, CNPJ, Cidade/UF, E-mail Financeiro, Ordem de Serviço/Contrato, Carga Horária, Tipo de Cobrança e Valor da Turma); disponibilizar o Gerador Assistido de Texto para NFS-e, que compila automaticamente a discriminação exata dos serviços prestados contendo as datas reais e horários executados de todos os encontros; e oferecer um botão de cópia de 1 clique para transferir a descrição pronta diretamente ao emissor web de notas fiscais da prefeitura.

**Blocked by:**
09: Gerenciamento Completo de Turmas, Modo Multi-Seleção no Calendário e Override de Encontros

**Status:** ready-for-agent

- [ ] A tabela `turmas` (e respectiva modelagem) é complementada com os campos fiscais e de faturamento: `razao_social`, `cnpj_tomador`, `cidade_uf`, `email_financeiro`, `numero_os_contrato`, `tipo_cobranca` (valor_fechado, por_aluno, hora_aula), `valor_unitario` e `valor_total`.
- [ ] Na tela de detalhes da turma (`/diario/turma`) e no formulário de edição (`/diario/turma/editar`), é exibido o card/painel de **"Faturamento & Dados Financeiros"** com preenchimento flexível (podendo ser preenchido no início ou no encerramento da turma).
- [ ] **Gerador Assistido de Discriminação de Serviços para NFS-e:** Um componente gera a descrição formatada padrão contendo: Nome do Curso/Treinamento, Razão Social e CNPJ do tomador, Ordem de Serviço/Contrato, Carga Horária total e a listagem cronológica exata de todas as datas e horários em que as aulas foram ministradas (ex: *"Aulas ministradas nos dias: DD/MM/AAAA (14h às 18h), DD/MM/AAAA (14h às 18h)..."*).
- [ ] Um botão `[ Copiar Descrição para NFS-e ]` copia o texto formatado para a área de transferência do sistema operacional com feedback visual (toast/tooltip *"Copiado com sucesso!"*).
- [ ] O valor total a faturar é calculado dinamicamente de acordo com o `tipo_cobranca` (ex: valor fixo fechado, número de alunos matriculados × valor_unitario, ou total de horas reais × valor_hora).
- [ ] Todas as alterações são cobertas por testes automatizados (unitários de cálculo/formatação de texto e de integração web).
