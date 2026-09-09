# 04: Módulo de validação pública com mascaramento LGPD de CPF e QR Code funcional

**What to build:**
O serviço de conferência de autenticidade acessado por terceiros (RH, universidades, comissões de concurso): consulta pelo código de autenticidade (via digitação ou leitura do QR Code), retornando os dados oficiais do curso e do livro de registro com mascaramento estrito de CPF (\***.123.456-**\) em total conformidade com a LGPD e sem permissão de download do PDF para prevenir vazamento de dados pessoais ou clonagem, gerando o QR Code com a URL base pública configurável.

**Blocked by:** 02: Livro de Registro Digital com persistência SQLite e exportação contínua para Excel

**Status:** ready-for-agent

- [ ] Módulo \core/validator_service.py\ para consulta de autenticidade por hash contra o banco de registros.
- [ ] Mascaramento garantido do CPF no formato LGPD (\***.123.456-**\) na resposta pública.
- [ ] Geração do QR Code apontando para a URL base configurável com o parâmetro \?validar=<codigo>\.
- [ ] Tratamento de códigos inexistentes ou adulterados com mensagem clara de documento inválido ou não registrado.
- [ ] Testes automatizados cobrindo busca de certificados autênticos (com dados mascarados) e rejeição segura de códigos falsos.
