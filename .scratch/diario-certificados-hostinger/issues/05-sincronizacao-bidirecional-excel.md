# 05: Sincronização Bidirecional com Planilhas Excel (.xlsx)

**What to build:**
Permitir que o operador baixe a qualquer momento uma planilha Excel (.xlsx) completa da turma com 3 abas estruturadas, possa editar alunos, presenças e planos de aula offline no Excel, e reimporte a planilha de volta para a aplicação na Hostinger, atualizando o banco de forma transparente, idempotente e sem duplicar cadastros ou perder histórico. O parser aplica sanitização rigorosa de espaços em branco para blindar o sistema contra dessincronização de nomes.

**Blocked by:**
04: Modo Aula Mobile — Chamada em Tempo Real e Plano de Aula Dinâmico

**Status:** completed

- [x] Na tela de visualização da turma (`/diario/turma`), um botão **"Exportar Planilha Excel"** gera o download de um arquivo `.xlsx` estruturado rigorosamente em 3 abas dedicadas:
  - Aba 1: `Alunos e Chamada` (linhas com nome e CPF; colunas para cada encontro/data com 1 para presente e 0 para falta, além de coluna de frequência acumulada %);
  - Aba 2: `Diário e Planos` (lista dos encontros com datas, horários, conteúdo previsto e conteúdo ministrado);
  - Aba 3: `Dados da Turma` (nome do curso, cliente, carga horária, instrutor e datas de início/conclusão).
- [x] O sistema disponibiliza o upload de planilha para importação na tela da turma.
- [x] O parser de importação lê as 3 abas da planilha e aplica sanitização estrita com `.strip()` em todas as strings de nomes e CPFs para evitar problemas com espaços em branco residuais (conforme apurado no Ticket #001).
- [x] **Idempotência sem Duplicidade (Costura de Teste 4):** Reimportar a planilha da turma com alterações pontuais atualiza os registros correspondentes no MariaDB sem duplicar alunos nem duplicar registros de presenças.
- [x] Em caso de inconformidade cadastral (ex: CPF matematicamente inválido), o sistema exibe relatório claro apontando a linha e o motivo sem corromper o salvamento dos registros válidos.
