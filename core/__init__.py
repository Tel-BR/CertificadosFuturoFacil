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
    clean_cnpj,
    validate_cnpj,
    format_cnpj,
    normalize_name,
    validar_aluno,
    validate_cpf,
)

from core.guilloche import (
    draw_continuous_l_ribbon,
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
from core.validator_service import (
    DEFAULT_VALIDATION_BASE_URL,
    ResultadoValidacaoPublica,
    ValidadorPublicoService,
    build_validation_url,
    clean_auth_code,
    generate_qr_code_bytes,
    generate_qr_code_image,
    validar_certificado,
)
from core.config import (
    InstituicaoConfig,
    load_instituicao_config,
    save_instituicao_config,
    validate_instituicao_config,
)
from core.batch_service import (
    BatchEmissionResult,
    create_zip_package,
    emitir_lote_certificados,
)

__all__ = [
    "BatchEmissionResult",
    "create_zip_package",
    "emitir_lote_certificados",
    "InstituicaoConfig",
    "load_instituicao_config",
    "save_instituicao_config",
    "validate_instituicao_config",
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
    "clean_cnpj",
    "validate_cnpj",
    "format_cnpj",
    "normalize_name",
    "validar_aluno",
    "validate_cpf",
    "draw_continuous_l_ribbon",
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
    "DEFAULT_VALIDATION_BASE_URL",
    "ResultadoValidacaoPublica",
    "ValidadorPublicoService",
    "build_validation_url",
    "clean_auth_code",
    "generate_qr_code_bytes",
    "generate_qr_code_image",
    "validar_certificado",
]
