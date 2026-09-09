# 01: Configuração do ambiente, planilha modelo e validação de alunos

**What to build:**
Garantir o ambiente de desenvolvimento pronto com as dependências instaladas e fornecer tanto o gerador da planilha modelo oficial (.xlsx) contendo apenas as colunas \"Nome\" e \"CPF\", quanto o motor de validação matemática e higienização desses dados (cálculo de dígitos verificadores de CPF e normalização de nomes com tratamento de preposições em Title Case).

**Blocked by:** None (can start immediately)

**Status:** resolved

- [x] Arquivo `requirements.txt` criado e dependências (`streamlit`, `reportlab`, `svglib`, `qrcode`, `openpyxl`, `pandas`, `pypdf`, `num2words`, `pillow`, `pytest`) instaladas e verificadas.
- [x] Função para gerar a planilha modelo oficial `modelo_alunos.xlsx` com validação de colunas estritas `Nome` e `CPF`.
- [x] Módulo `core/validator.py` implementando higienização e validação matemática de CPF (rejeição de tamanhos incorretos, caracteres inválidos ou dígitos verificadores falsos).
- [x] Normalização inteligente de nomes de alunos em Title Case preservando preposições minúsculas ("de", "da", "do", "dos", "das").
- [x] Bateria de testes unitários com `pytest` aprovando casos válidos e rejeitando casos inválidos.


## Comments

### Plano de Implementação Técnica (TDD & Seams)

1. **Configuração e Ambiente**:
   - Criar `requirements.txt` com todas as dependências especificadas.
   - Instalar dependências ausentes e verificar ambiente de execução.

2. **Costura 1: `core/validator.py`**:
   - `clean_cpf(cpf: str) -> str`: Remove caracteres não-numéricos.
   - `validate_cpf(cpf: str) -> bool`: Validação matemática com cálculo dos dois dígitos verificadores (módulo 11), rejeitando tamanhos incorretos e sequências de dígitos repetidos (ex: `11111111111`).
   - `format_cpf(cpf: str) -> str`: Formata no padrão `000.000.000-00`.
   - `mask_cpf(cpf: str) -> str`: Formata no padrão LGPD `***.000.000-**`.
   - `normalize_name(name: str) -> str`: Converte para Title Case mantendo preposições minúsculas (`de`, `da`, `do`, `dos`, `das`, `e`), eliminando múltiplos espaços em branco e respeitando acentuação.
   - `validate_student(name: str, cpf: str) -> StudentValidation`: Objeto com status de validade, campos limpos e lista de eventuais inconsistências.

3. **Costura 2: `core/spreadsheet.py`**:
   - `generate_template_spreadsheet(destination)`: Gera `modelo_alunos.xlsx` contendo estritamente as colunas `Nome` e `CPF`.
   - `read_and_validate_spreadsheet(source)`: Carrega arquivos Excel/CSV, audita a presença estrita das colunas obrigatórias e valida cada linha reportando o status por aluno.

4. **Ciclo TDD**:
   - Red -> Green para cada costura em `tests/test_validator.py` e `tests/test_spreadsheet.py`.
   - Execução final de toda a suíte de testes unitários com `pytest`.

## Answer

Ticket 01 implementado com sucesso seguindo o ciclo TDD:
- Criado e instalado o arquivo `requirements.txt` com todas as dependências do projeto (`streamlit`, `reportlab`, `svglib`, `qrcode[pil]`, `openpyxl`, `pandas`, `pypdf`, `num2words`, `pillow`, `pytest`).
- Implementado `core/validator.py` com algoritmo canônico módulo 11 para verificação de CPFs, rejeição de repetições, higienização, formatação, máscara LGPD e normalização inteligente de nomes em Title Case preservando preposições da língua portuguesa.
- Implementado `core/spreadsheet.py` com gerador da planilha oficial `modelo_alunos.xlsx` e motor de leitura e auditoria de planilhas (.xlsx e .csv) com validação estrita de colunas e linhas.
- Gerado o arquivo físico `modelo_alunos.xlsx` com formatação e dados instrucionais.
- 16 testes unitários criados e aprovados com 100% de sucesso via `pytest`.

