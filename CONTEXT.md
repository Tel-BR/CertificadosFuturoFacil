# Emissor de Certificados Futuro Fácil

Sistema para emissão massiva de certificados de cursos livres em conformidade com as normas do MEI, LDB, Decreto Federal nº 5.154/2004 e LGPD.

## Language

**Certificado**:
Documento comprobatório de conclusão de curso livre de capacitação profissional emitido nos termos do Decreto Federal nº 5.154/2004.
_Avoid_: Diploma, habilitação profissional, certificado acadêmico

**Curso Livre**:
Modalidade de educação não-formal voltada à capacitação inicial ou continuada, dispensada de regulação e reconhecimento do MEC ou CEE.
_Avoid_: Curso técnico, graduação, curso regulamentado

**Aluno**:
Pessoa física que concluiu o curso livre e para quem o certificado é expedido.
_Avoid_: Estudante, cliente, usuário

**Livro de Registro Digital**:
Registro formal sequencial (livro, folha e número de registro) mantido pela instituição emissora para comprovação e auditoria perante faculdades e concursos.
_Avoid_: Log de emissão, histórico escolar, banco de dados simples

**Código de Autenticidade**:
Identificador único (hash criptográfico ou UUID) gerado no momento da emissão para atestar a integridade do certificado sem expor dados pessoais plenos.
_Avoid_: Token de acesso, chave privada, senha

**Validação Pública**:
Mecanismo de consulta acessado via QR Code ou digitação do código para atestar a autenticidade do documento com mascaramento de dados (LGPD).
_Avoid_: Login, autenticação de usuário, checagem interna

**Guilloché**:
Padrão geométrico vetorial contínuo e matemático (tipo papel-moeda/diploma) gerado na borda do anverso para conferir segurança visual contra falsificação.
_Avoid_: Borda decorativa, moldura simples, marca d'água

**Ementa**:
Detalhamento dos tópicos e conteúdos programáticos do curso, impresso obrigatoriamente no verso para validação acadêmica de horas complementares.
_Avoid_: Sumário, programa de aula, resumo
