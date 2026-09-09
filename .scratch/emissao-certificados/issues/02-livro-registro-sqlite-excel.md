# 02: Livro de Registro Digital com persistência SQLite e exportação contínua para Excel

**What to build:**
O subsistema de controle documental e contabilidade cronológica dos certificados: um banco de dados relacional (SQLite local com driver preparado para PostgreSQL/Supabase) que gerencia Livro nº, Folha nº e Registro nº sequenciais, aplicando a regra de 1 registro por folha e virada automática de livro a cada 100 folhas, gerando códigos de autenticidade criptográficos imutáveis (SHA-256) e exportando/atualizando automaticamente a planilha mestre de auditoria \livro_registro_certificados.xlsx\ a cada lote.

**Blocked by:** 01: Configuração do ambiente, planilha modelo e validação de alunos

**Status:** resolved

- [x] Módulo \core/registry.py\ com esquema de tabela do Livro de Registro Digital e abstração de conexão (SQLite com suporte a \DATABASE_URL\).
- [x] Incremento sequencial confiável de Registro e Folha (1 registro por folha), com virada automática de Livro ao atingir 100 folhas.
- [x] Geração de Código de Autenticidade imutável baseado em hash criptográfico exclusivo para cada certificado.
- [x] Função de exportação automática dos registros para a planilha \livro_registro_certificados.xlsx\ com formatação profissional.
- [x] Testes automatizados validando a persistência, o avanço sequencial correto e a virada do centésimo registro.

## Comments

### Plano de Implementação Técnica (TDD & Seams)

1. **Arquitetura de Dados e Conexão Híbrida (`core/registry.py`)**:
   - `CertificadoRegistro`: Dataclass com modelo de dados integral do assento (código SHA-256, dados do aluno com CPF limpo e mascarado LGPD, curso, carga horária e extenso, datas, instrutor, cidade, ementa, livro, folha e registro).
   - `CursoMetadata`: Dataclass para os metadados da turma e ementa.
   - `LivroRegistroManager`: Abstração de persistência relacional com SQLite por padrão (`registros.db`), com suporte parametrizável a `DATABASE_URL` (PostgreSQL/Supabase em produção/Streamlit Cloud conforme ADR-0001 e ADR-0003).

2. **Costura 1: Funções Utilitárias e Criptografia**:
   - `generate_authenticity_code(...) -> str`: Geração de Código de Autenticidade imutável baseado em SHA-256 (64 caracteres hexadecimais), calculado sobre a tupla de dados do certificado + entropia/nonce único.
   - `formatar_carga_horaria_extenso(horas: int) -> str`: Conversão formal para extenso em português ("1 hora", "40 horas", "21 horas", "101 horas").

3. **Costura 2: Sequenciamento Documental e Virada de Livro**:
   - Regra de negócio canônica: 1 registro por folha, virada automática de Livro ao atingir 100 folhas (`folhas_por_livro=100`, ajustável).
   - O número de registro (`registro_numero`) é monotonicamente crescente e contínuo (Registro 1, 2, 3... 100, 101...).
   - Transação atômica para registro individual (`register_certificate`) e em lote (`register_batch`).
   - Virada de folha e livro:
     - Primeiro registro: Livro 1, Folha 1, Registro 1.
     - Registro 100: Livro 1, Folha 100, Registro 100.
     - Registro 101: Livro 2, Folha 1, Registro 101.

4. **Costura 3: Exportação Automática para Excel (`livro_registro_certificados.xlsx`)**:
   - `export_to_excel(destination)`: Gera/atualiza a planilha mestre de auditoria com cabeçalho azul marinho (`#1E3A8A`), texto branco em negrito, colunas com largura ajustada automaticamente, linhas com bordas e zebradas, e painel congelado na primeira linha.
   - Disparo automático da exportação a cada lote concluído com sucesso.

5. **Costura 4: Ciclo TDD (`tests/test_registry.py`)**:
   - `test_formatar_carga_horaria_extenso`: validação de casos singulares, plurais e compostos.
   - `test_generate_authenticity_code`: validação de hash SHA-256 de 64 caracteres, unicidade e imutabilidade.
   - `test_sequential_book_and_page_mechanics`: avanço passo a passo de livro, folha e registro.
   - `test_turnover_at_hundredth_page`: teste crítico da virada do centésimo registro (100 -> Livro 1 Folha 100; 101 -> Livro 2 Folha 1).
   - `test_batch_registration`: lote de 150 alunos registrando múltiplos livros sem furos.
   - `test_certificate_query_by_code`: recuperação por código de autenticidade (case-insensitive).
   - `test_excel_export_structure_and_styling`: validação física da planilha `.xlsx` gerada.

## Answer

Ticket 02 implementado com sucesso seguindo o ciclo TDD (Red -> Green):
- Criado o módulo `core/registry.py` provendo:
  - Modelos de dados canônicos: `CertificadoRegistro` e `CursoMetadata`.
  - Função utilitária `formatar_carga_horaria_extenso` com suporte formal a extenso em português e concordância no feminino ("uma hora", "vinte e uma horas", "quarenta horas").
  - Função `generate_authenticity_code` gerando hashes SHA-256 de 64 caracteres hexadecimais imutáveis e exclusivos por certificado.
  - Classe `LivroRegistroManager` com persistência relacional SQLite local idempotente e suporte a PostgreSQL via `DATABASE_URL`.
  - Sequenciamento estrito de Livro, Folha e Registro (1 registro por folha e virada automática a cada 100 folhas), suportando inserção individual e em lote com garantia transacional.
  - Método de busca rápida de certificados por código de autenticidade (`get_certificate_by_code`) com tratamento insensível a maiúsculas/minúsculas.
  - Método `export_to_excel` gerando a planilha oficial `livro_registro_certificados.xlsx` com formatação notarial profissional (cabeçalho azul marinho `#1E3A8A`, bordas finas, linhas zebradas, congelamento de painel e larguras automáticas).
  - Atualização automática contínua da planilha mestre em disco após a finalização de cada lote.
- Criada bateria completa de testes automatizados em `tests/test_registry.py`.
- Suíte completa do repositório executada com 26 testes aprovados com 100% de sucesso via `pytest`.

