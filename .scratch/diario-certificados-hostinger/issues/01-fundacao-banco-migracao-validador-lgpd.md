# 01: Fundação do Banco, Migração do Histórico e Validador Público LGPD

**What to build:**
Permitir que qualquer pessoa ou instituição valide a autenticidade de um certificado emitido escaneando o QR Code ou digitando o código de autenticidade no novo validador web na Hostinger, recebendo resposta instantânea com os dados do curso e os dados pessoais do aluno rigorosamente mascarados conforme a LGPD. O histórico existente (incluindo os 39 certificados oficiais da turma Sicoob) é importado sem alteração dos hashes SHA-256 ou numeração sequencial, garantindo que nenhum certificado já emitido precise ser reemitido. O schema MariaDB inicial já nasce completo para suportar toda a plataforma (incluindo turmas, encontros, presenças, materiais protegidos e livro de registro).

**Blocked by:**
None (can start immediately)

**Status:** completed

- [x] O schema relacional completo em SQL (`turmas`, `encontros`, `alunos`, `frequencias`, `materiais_turma`, `registros_certificados`, `usuarios_admin`) é criado no MariaDB com suporte a UTF-8 (`utf8mb4_unicode_ci`), índices nos códigos SHA-256 e restrições de integridade referencial.
- [x] A tabela `turmas` já nasce com suporte a faturamento/NFS-e (`ordem_servico`, `modalidade`, `cliente_tipo`, `cliente_cidade`, `cliente_uf`, `cliente_cnpj`, `tipo_cobranca`, `valor_hora_aula`, `valor_total`) e o campo `chave_acesso` para alunos.
- [x] A tabela `materiais_turma` é provisionada para suportar a futura proteção de conteúdo (ADR-0008).
- [x] O banco MariaDB roda localmente no servidor (`localhost`) com a porta 3306 estritamente inacessível para a internet externa.
- [x] Todos os 39 registros de certificados existentes da turma Sicoob e registros anteriores em `registros.db` são migrados para a tabela `registros_certificados` no MariaDB, preservando exatamente os mesmos códigos SHA-256 e o sequenciamento de Livro, Folha e Registro.
- [x] A página pública de consulta (`/validar`) aceita o código via parâmetro de URL (`?validar=<HASH>`) ou digitação direta em campo de busca responsivo.
- [x] A resposta da validação exibe confirmação de autenticidade, nome do curso, carga horária, data de conclusão e data de emissão.
- [x] Os dados sensíveis do aluno são exibidos de forma mascarada (ex: `A** L**** S***** N*********` e `***.123.456-**`) conforme as diretrizes da LGPD (ADR-0002).
- [x] A rota pública de validação executa estritamente consultas somente-leitura e não expõe nenhum dado administrativo ou endpoint de modificação.
- [x] **Garantia de Não-Regressão (Costura de Teste 1):** Script de teste automatizado verifica a consulta dos 39 códigos reais da turma Sicoob contra o novo validador e confirma 100% de sucesso e conformidade de dados mascarados.
- [x] **Ponte de Redirecionamento Streamlit:** O app legado no Streamlit Cloud recebe instrução de redirecionamento HTTP 301 para encaminhar imediatamente requisições antigas de QR Code para `https://futurofacil.com.br/validar?validar=<HASH>`.
