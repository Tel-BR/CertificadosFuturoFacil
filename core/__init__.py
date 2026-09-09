"""Core module for Certificados Futuro Fácil."""

from core.registry import (
    CertificadoRegistro,
    CursoMetadata,
    LivroRegistroManager,
    formatar_carga_horaria_extenso,
    generate_authenticity_code,
)
from core.spreadsheet import (
    SpreadsheetValidationResult,
    generate_template_spreadsheet,
    read_and_validate_spreadsheet,
)
from core.validator import (
    ValidacaoAluno,
    clean_cpf,
    format_cpf,
    mask_cpf,
    normalize_name,
    validar_aluno,
    validate_cpf,
)

__all__ = [
    "CertificadoRegistro",
    "CursoMetadata",
    "LivroRegistroManager",
    "formatar_carga_horaria_extenso",
    "generate_authenticity_code",
    "SpreadsheetValidationResult",
    "generate_template_spreadsheet",
    "read_and_validate_spreadsheet",
    "ValidacaoAluno",
    "clean_cpf",
    "format_cpf",
    "mask_cpf",
    "normalize_name",
    "validar_aluno",
    "validate_cpf",
]
