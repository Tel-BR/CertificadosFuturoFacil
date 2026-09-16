---
tipo: ticket_incidente
id: TICKET-2026-001
status: aberto
prioridade: alta
data_abertura: 2026-09-15
componentes:
  - Streamlit UI (app.py)
  - Batch Emission (core/batch_service.py)
  - Spreadsheet Validation (core/spreadsheet.py)
  - Validator (core/validator.py)
relator: Tel Santana Leite
---

# Ticket #001 — Investigação: Omissão de Certificados no ZIP e Sobrescrita de Frequência no Streamlit

## 1. Sumário do Incidente

Na primeira emissão em lote de certificados da turma Sicoob (arquivo `modelo_alunos_sicoob.xlsx`), o cliente reportou as seguintes pendências e inconformidades após receber o pacote ZIP:
1. **Ausência de 9 certificados individuais:**
   - Andressa Cristina Azevedo Arruda Rojas
   - Francisco das Chagas Rocha de Lima Junior
   - João Victor Naves Oliveira Zica
   - Kallynne Aparecida Torres Oliveira
   - Mariana Moreno da Silva Sampaio
   - Mayara Resende Leite Vilarinho
   - Ruthy Gabrielly Ferreira da Silva
   - Thais Cristina do Amaral Fontes
   - Wisliane Lacir Aparecida Lima da Silva
2. **Inconformidade de frequência em alunos emitidos:**
   - **Fernando Gomes de Sousa:** emitido com 100% (deve ser 88% pela falta no último módulo).
   - **Ingred Diniz Rocha:** emitida com 88% (deve ser 100% pelo abono da aula de 05/08).
   - **Yago Romário Santos Costa:** emitido com 88% (deve ser 100% pelo abono da aula de 05/08).
3. **Perda de digitação interativa (Kallynne Aparecida):**
   - O emissor ajustou manualmente a frequência de Kallynne para 75% na tabela interativa do Streamlit, mas o certificado não foi gerado.

---

## 2. Diagnóstico Técnico Detalhado

### Causa Raiz 1: Sobrescrita de Edições Manuais no `st.data_editor` (Streamlit Session State)
- **Localização:** `app.py`, linhas 642–720.
- **Mecanismo da falha:**
  A cada interação do usuário ou clique no botão de ação (`st.button("Emitir Lote de Certificados")`), o Streamlit reinicia a execução do script (`rerun`).
  No topo da execução, a planilha enviada é relida diretamente do buffer de upload por `read_and_validate_spreadsheet()`.
  O `res_auditoria` é reconstruído a partir dos dados brutos do arquivo original, no qual a aluna Kallynne constava com apenas 4 presenças de 8 encontros (50% de frequência).
  Em seguida, o `df_edit` é recriado com os dados da planilha antes de ser passado para `st.data_editor(df_edit, key="editor_alunos")`.
  Como o estado de edição não estava explicitamente sincronizado e blindado no `st.session_state`, **o valor digitado manualmente (75%) foi sobrescrito pelo recálculo da planilha (50%)**.
  Como a frequência recalculada resultou em 50% (< 75%), o mecanismo de **Emissão Não-Bloqueante** filtrou a aluna para fora de `alunos_aptos`, omitindo silenciosamente o certificado dela no lote.

### Causa Raiz 2: Ausência dos Outros 8 Alunos no ZIP
- **Localização:** Auditoria de dados de entrada e pipeline de empacotamento.
- **Fatos apurados:**
  - Todos os 8 alunos possuem CPF matematicamente válido.
  - Todos os 8 alunos possuem frequência calculada entre 75% e 100%.
  - Quatro desses nomes possuem espaço em branco no final da string original na planilha (`"Andressa Cristina Azevedo Arruda Rojas "`, `"João Victor Naves Oliveira Zica "`, `"Ruthy Gabrielly Ferreira Da Silva "`, `"Yago Romário Santos Costa "`).
  - É necessário auditar se:
    1. A sanitização de nomes com espaço em branco no final causou dessincronização de índice entre `res_auditoria.alunos` e `edited_df.iterrows()`;
    2. O filtro de exibição na tabela interativa deixou linhas não-submetidas;
    3. O ZIP gerado anteriormente foi baseado em uma versão filtrada ou arquivo alternativo.

### Causa Raiz 3: Abono Geral da Aula de 05/08/2026
- Na planilha, o dia 05/08 constava com falta para 6 alunos (incluindo Ingred e Yago).
- Como essa aula foi formalmente abonada para toda a turma pela coordenação do curso, a presença de 05/08 deve constar como `True` para todos, elevando Ingred e Yago de 88% para 100%.

---

## 3. Ações Corretivas Necessárias

1. **[Código - Alta Prioridade] Blindagem do `st.data_editor` via `st.session_state`:**
   - Garantir que edições manuais em `Nome`, `CPF` ou `Frequência (%)` feitas pelo usuário no painel web tenham precedência absoluta sobre a leitura bruta do arquivo.
   - Persistir o `edited_df` no `session_state` e alimentar `alunos_para_emissao` estritamente a partir das alterações confirmadas.
   - Adicionar sanitização com `.strip()` na leitura das células para evitar problemas com espaços no final dos nomes.

2. **[Base de Dados e Planilha] Saneamento da Turma Sicoob:**
   - Atualizar a planilha mestre `modelo_alunos_sicoob.xlsx`:
     - Abonar o dia 05/08 para toda a turma (`True` para todos os alunos).
     - Desmarcar presença de Fernando Gomes no último encontro (10/08), fixando em 88%.
     - Inserir explicitamente 75% na coluna `Aproveitamento (%)` para Kallynne Aparecida.
     - Remover espaços residuais no final dos nomes dos alunos.

3. **[Operação] Emissão do Pacote ZIP Definitivo:**
   - Gerar o pacote ZIP com todos os 39 alunos certificados, consolidando o Livro de Registro Digital sequencial e limpo.
