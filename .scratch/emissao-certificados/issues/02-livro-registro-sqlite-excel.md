# 02: Livro de Registro Digital com persistência SQLite e exportação contínua para Excel

**What to build:**
O subsistema de controle documental e contabilidade cronológica dos certificados: um banco de dados relacional (SQLite local com driver preparado para PostgreSQL/Supabase) que gerencia Livro nº, Folha nº e Registro nº sequenciais, aplicando a regra de 1 registro por folha e virada automática de livro a cada 100 folhas, gerando códigos de autenticidade criptográficos imutáveis (SHA-256) e exportando/atualizando automaticamente a planilha mestre de auditoria \livro_registro_certificados.xlsx\ a cada lote.

**Blocked by:** 01: Configuração do ambiente, planilha modelo e validação de alunos

**Status:** ready-for-agent

- [ ] Módulo \core/registry.py\ com esquema de tabela do Livro de Registro Digital e abstração de conexão (SQLite com suporte a \DATABASE_URL\).
- [ ] Incremento sequencial confiável de Registro e Folha (1 registro por folha), com virada automática de Livro ao atingir 100 folhas.
- [ ] Geração de Código de Autenticidade imutável baseado em hash criptográfico exclusivo para cada certificado.
- [ ] Função de exportação automática dos registros para a planilha \livro_registro_certificados.xlsx\ com formatação profissional.
- [ ] Testes automatizados validando a persistência, o avanço sequencial correto e a virada do centésimo registro.
