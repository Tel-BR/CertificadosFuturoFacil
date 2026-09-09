# 04: Módulo de validação pública com mascaramento LGPD de CPF e QR Code funcional

**What to build:**
O serviço de conferência de autenticidade acessado por terceiros (RH, universidades, comissões de concurso): consulta pelo código de autenticidade (via digitação ou leitura do QR Code), retornando os dados oficiais do curso e do livro de registro com mascaramento estrito de CPF (\***.123.456-**\) em total conformidade com a LGPD e sem permissão de download do PDF para prevenir vazamento de dados pessoais ou clonagem, gerando o QR Code com a URL base pública configurável.

**Blocked by:** 02: Livro de Registro Digital com persistência SQLite e exportação contínua para Excel

**Status:** resolved

- [x] Módulo `core/validator_service.py` para consulta de autenticidade por hash contra o banco de registros.
- [x] Mascaramento garantido do CPF no formato LGPD (`***.123.456-**`) na resposta pública.
- [x] Geração do QR Code apontando para a URL base configurável com o parâmetro `?validar=<codigo>`.
- [x] Tratamento de códigos inexistentes ou adulterados com mensagem clara de documento inválido ou não registrado.
- [x] Testes automatizados cobrindo busca de certificados autênticos (com dados mascarados) e rejeição segura de códigos falsos.

## Comments

### Plano e Decisões de Design (Rodadas de Alinhamento e Code Review)
- **Conformidade Estrita à LGPD**: O modelo `ResultadoValidacaoPublica` e seu método de serialização `to_dict()` nunca expõem o CPF em claro do aluno e não fornecem links ou buffers para download do arquivo PDF original, eliminando vetores de vazamento de dados e clonagem.
- **Resiliência de Mascaramento**: Implementado fallback seguro (`_extrair_cpf_mascarado`) que limpa e reaplica a máscara mesmo na hipótese de persistência de CPFs desmascarados legados no banco de dados.
- **Desacoplamento e Reuso**: Funções `build_validation_url` e geradores de QR Code compartilhados entre o motor de PDF (`core/renderer.py`) e a camada de validação pública.

## Answer

Ticket 04 implementado com sucesso seguindo o ciclo TDD (Red -> Green) e validado em revisão de código em dois eixos (Standards e Spec):
- Criado o módulo `core/validator_service.py` contendo:
  - Constante `DEFAULT_VALIDATION_BASE_URL` apontando para a rota padrão pública do Streamlit (`/?validar=`).
  - Função `build_validation_url` normalizando URLs sem duplicar parâmetros ou barras.
  - Funções `generate_qr_code_image` e `generate_qr_code_bytes` para geração rápida de QR Codes vetoriais/visuais.
  - Modelo de dados `ResultadoValidacaoPublica` com fábrica `from_registro`, serialização segura `to_dict()` e blindagem LGPD para que apenas o CPF mascarado (`***.123.456-**` ou "Não informado") seja retornado.
  - Classe `ValidadorPublicoService` e função utilitária `validar_certificado` realizando a busca no `LivroRegistroManager`, com validação estrutural de integridade (SHA-256 de 64 caracteres) para rejeitar códigos adulterados com mensagens claras.
- Reutilização de `build_validation_url` em `core/renderer.py` eliminando redundância.
- Exportação dos componentes oficiais em `core/__init__.py`.
- 10 novos testes unitários e de integração adicionados em `tests/test_validator_service.py`.
- Suíte completa do repositório executada com 51 testes aprovados com 100% de sucesso via `pytest`.
