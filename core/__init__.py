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

from core.guilloche import (
    draw_guilloche_frame,
    draw_modern_wave_ribbon,
    draw_security_seal,
    resolve_color,
)
from core.renderer import (
    CertificateRenderConfig,
    generate_batch_certificates,
    generate_certificate_pdf,
    render_anverso,
    render_reverso,
)
from core.consolidator import (
    consolidate_duplex_pdf,
    merge_duplex_files,
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
    "draw_guilloche_frame",
    "draw_modern_wave_ribbon",
    "draw_security_seal",
    "resolve_color",
    "CertificateRenderConfig",
    "generate_certificate_pdf",
    "generate_batch_certificates",
    "render_anverso",
    "render_reverso",
    "consolidate_duplex_pdf",
    "merge_duplex_files",
]
