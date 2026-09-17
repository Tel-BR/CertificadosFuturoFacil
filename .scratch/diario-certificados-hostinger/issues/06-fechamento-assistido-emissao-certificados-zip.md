# 06: Fechamento Assistido, Emissão de Certificados Duplex e Pacote ZIP

**What to build:**
Permitir que o operador conclua formalmente a turma através de uma tela de fechamento assistido: verificar se alguma aula precisa de abono coletivo (aplicando 100% de presença naquele encontro para todos com 1 clique), conferir a lista auditada de alunos aptos ($\ge 75\%$) e inaptos ($< 75\%$) com possibilidade de justificativa manual extraordinária, e acionar a emissão oficial. O sistema registra os assentos de forma atômica no Livro de Registro Digital no MariaDB (dando sequência exata e ininterrupta de Livro, Folha e Registro a partir do histórico anterior), renderiza os certificados individuais em PDF A4 paisagem duplex vetorial (com a mesma fidelidade do ReportLab) e entrega o pacote ZIP para download imediato.

**Blocked by:**
04: Modo Aula Mobile — Chamada em Tempo Real e Plano de Aula Dinâmico
01: Fundação do Banco, Migração do Histórico e Validador Público LGPD

**Status:** completed

- [x] A tela `/diario/fechamento` apresenta o resumo auditado de aproveitamento da turma com a separação visual clara entre **Alunos Aptos** ($\ge 75\%$) e **Alunos Inaptos** ($< 75\%$).
- [x] Um botão de **"Abonar Aula Coletiva"** permite selecionar uma aula específica (ex: feriado ou reagendamento) e marcar presença abonada para toda a turma de forma instantânea, recalculando os percentuais de todos os alunos antes da emissão definitiva.
- [x] A tela permite autorizar justificativa individual excepcional para aluno abaixo de 75% caso deliberado formalmente pela coordenação.
- [x] Ao acionar "Confirmar e Emitir Certificados", o sistema reserva e incrementa atômica e sequencialmente o Livro, Folha e Registro no MariaDB a partir do último registro presente no histórico migrado da turma Sicoob.
- [x] Para cada aluno apto, é calculado o código de autenticidade SHA-256 e renderizado o PDF duplex vetorial em A4 paisagem de alta fidelidade visual:
  - Anverso: Fundo off-white (`#FAFCFF`), fita perimétrica de guilloché vetorial, logotipo/wordmark da Futuro Fácil, card do assento digital e texto oficial;
  - Reverso: Ementa e conteúdo programático completo, card do livro/folha/registro, QR Code vetorial apontando para `https://futurofacil.com.br/validar?validar=<HASH>` e hash SHA-256 dividido em blocos.
- [x] O sistema empacota todos os certificados individuais, o PDF consolidado intercalado para gráfica (2 * N páginas) e o livro de registro atualizado em um arquivo `.zip` pronto para download pelo navegador.
- [x] Qualquer certificado recém-gerado valida perfeitamente contra a rota pública `/validar`.
- [x] **Apoio ao Faturamento / NFS-e:** Na tela de fechamento assistido, o sistema exibe um card com o valor calculado da turma (hora-aula x carga horária total ou valor fixo) e gera a **discriminação de serviços completa e formatada** (com Ordem de Serviço, curso, modalidade, carga horária total e diária h/dia, lista dinâmica das datas dos encontros realizados, tipo e nome do cliente, município-UF, instrutor e valor final), contendo botão de 1 toque **"Copiar Descrição para NFS-e"**.

