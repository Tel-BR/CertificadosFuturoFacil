"""Serviço de validação pública de autenticidade de certificados e gerador de QR Code.

Implementa a verificação por terceiros (empresas, universidades, comissões de concurso)
com conformidade estrita à LGPD (mascaramento de CPF) e sem exposição do PDF original,
atendendo aos requisitos de ADR-0002 e ADR-0003.
"""

from dataclasses import dataclass
import io
from pathlib import Path
import re
from typing import Any, Dict, Optional, Union

from PIL import Image
import qrcode

from core.registry import CertificadoRegistro, LivroRegistroManager
from core.validator import clean_cpf, mask_cpf, normalize_name

DEFAULT_VALIDATION_BASE_URL: str = (
    "https://certificados-futurofacil.streamlit.app/?validar="
)


def clean_auth_code(codigo: Optional[str]) -> str:
    """
    Higieniza o código de autenticidade (SHA-256):
    Remove todos os espaços em branco, tabulações e quebras de linha (\\n, \\r)
    decorrentes de cópia do PDF ou digitação, retornando string em caixa alta.
    """
    if not codigo:
        return ""
    return re.sub(r"\s+", "", str(codigo)).upper()


def build_validation_url(base_url: str, code: str) -> str:
    """
    Constrói a URL pública de validação evitando barras extras ou parâmetros duplicados.
    Suporta URLs com e sem '?validar=' ou parâmetros existentes de query string.
    """
    clean_base = str(base_url or "").strip()
    clean_code = clean_auth_code(code)

    if "validar=" in clean_base:
        if clean_base.endswith("=") or clean_base.endswith("&"):
            return f"{clean_base}{clean_code}"
        return f"{clean_base}&validar={clean_code}"

    if "?" in clean_base:
        sep = "&" if not clean_base.endswith(("?", "&")) else ""
        return f"{clean_base}{sep}validar={clean_code}"

    return f"{clean_base.rstrip('/')}/?validar={clean_code}"


def generate_qr_code_image(
    code: str,
    base_url: str = DEFAULT_VALIDATION_BASE_URL,
    fill_color: str = "#0E7490",
    back_color: str = "white",
    box_size: int = 10,
    border: int = 2,
) -> Image.Image:
    """
    Gera uma imagem PIL com o QR Code apontando para a URL pública de validação.
    """
    url = build_validation_url(base_url, code)
    qr = qrcode.QRCode(
        version=1,
        error_correction=qrcode.constants.ERROR_CORRECT_M,
        box_size=max(1, int(box_size)),
        border=max(0, int(border)),
    )
    qr.add_data(url)
    qr.make(fit=True)
    img = qr.make_image(fill_color=fill_color, back_color=back_color)
    return img.convert("RGB")


def generate_qr_code_bytes(
    code: str,
    base_url: str = DEFAULT_VALIDATION_BASE_URL,
    format: str = "PNG",
    fill_color: str = "#0E7490",
    back_color: str = "white",
    box_size: int = 10,
    border: int = 2,
) -> bytes:
    """
    Gera e retorna os bytes brutos da imagem do QR Code (formato padrão PNG).
    """
    img = generate_qr_code_image(
        code=code,
        base_url=base_url,
        fill_color=fill_color,
        back_color=back_color,
        box_size=box_size,
        border=border,
    )
    buf = io.BytesIO()
    img.save(buf, format=format)
    return buf.getvalue()


def _extrair_cpf_mascarado(
    cpf_mascarado_db: Optional[str],
    cpf_db: Optional[str],
) -> str:
    """
    Garante que o CPF retornado na consulta pública esteja estritamente mascarado (LGPD).
    Rejeita e mascara qualquer string não-mascarada que possa ter vindo do banco.
    """
    if cpf_mascarado_db and "*" in cpf_mascarado_db:
        return cpf_mascarado_db

    cpf_candidato = cpf_db or cpf_mascarado_db or ""
    digits = clean_cpf(cpf_candidato)
    if len(digits) == 11:
        return mask_cpf(digits)

    return "-"


@dataclass
class ResultadoValidacaoPublica:
    """
    Resultado público oficial da validação de um certificado.
    
    GARANTIA DE PRIVACIDADE E LGPD:
    - O CPF do aluno é estritamente mascarado (***.123.456-**).
    - Não expõe links de download ou arquivos PDF originais para evitar vazamentos e clonagem.
    """

    autentico: bool
    mensagem: str
    codigo_autenticidade: str
    aluno_nome: Optional[str] = None
    aluno_cpf_mascarado: Optional[str] = None
    curso_nome: Optional[str] = None
    carga_horaria: Optional[int] = None
    carga_horaria_extenso: Optional[str] = None
    modalidade: Optional[str] = None
    data_inicio: Optional[str] = None
    data_conclusao: Optional[str] = None
    data_emissao: Optional[str] = None
    livro_numero: Optional[int] = None
    folha_numero: Optional[int] = None
    registro_numero: Optional[int] = None
    instrutor: Optional[str] = None
    cidade: Optional[str] = None
    ementa: Optional[str] = None
    frequencia: Optional[int] = None

    @classmethod
    def from_registro(
        cls,
        registro: CertificadoRegistro,
        mensagem: str = "Certificado autêntico e registrado oficialmente no Livro Digital.",
    ) -> "ResultadoValidacaoPublica":
        """Constrói uma instância pública a partir do assento formal do livro de registros."""
        cpf_mascarado = _extrair_cpf_mascarado(
            registro.aluno_cpf_mascarado,
            registro.aluno_cpf,
        )
        return cls(
            autentico=True,
            mensagem=mensagem,
            codigo_autenticidade=registro.codigo_autenticidade,
            aluno_nome=normalize_name(registro.aluno_nome),
            aluno_cpf_mascarado=cpf_mascarado,
            curso_nome=registro.curso_nome,
            carga_horaria=registro.carga_horaria,
            carga_horaria_extenso=registro.carga_horaria_extenso,
            modalidade=registro.modalidade,
            data_inicio=registro.data_inicio,
            data_conclusao=registro.data_conclusao,
            data_emissao=registro.data_emissao,
            livro_numero=registro.livro_numero,
            folha_numero=registro.folha_numero,
            registro_numero=registro.registro_numero,
            instrutor=registro.instrutor,
            cidade=registro.cidade,
            ementa=registro.ementa,
            frequencia=getattr(registro, "frequencia", 100),
        )

    def to_dict(self) -> Dict[str, Any]:
        """
        Exporta os dados em formato serializável (dicionário) para consumo seguro.
        Garante estritamente que o CPF bruto nunca esteja presente no retorno.
        """
        return {
            "autentico": self.autentico,
            "mensagem": self.mensagem,
            "codigo_autenticidade": self.codigo_autenticidade,
            "aluno_nome": self.aluno_nome,
            "aluno_cpf_mascarado": self.aluno_cpf_mascarado,
            "curso_nome": self.curso_nome,
            "carga_horaria": self.carga_horaria,
            "carga_horaria_extenso": self.carga_horaria_extenso,
            "modalidade": self.modalidade,
            "data_inicio": self.data_inicio,
            "data_conclusao": self.data_conclusao,
            "data_emissao": self.data_emissao,
            "livro_numero": self.livro_numero,
            "folha_numero": self.folha_numero,
            "registro_numero": self.registro_numero,
            "instrutor": self.instrutor,
            "cidade": self.cidade,
            "ementa": self.ementa,
            "frequencia": self.frequencia,
        }


class ValidadorPublicoService:
    """
    Serviço de conferência de autenticidade de certificados emitidos.
    Consulta registros no Livro de Registro Digital sem expor dados sensíveis desnecessários.
    """

    def __init__(
        self,
        registry_manager: Optional[LivroRegistroManager] = None,
        db_url_or_path: Optional[str] = None,
    ):
        if registry_manager is not None:
            self.registry_manager = registry_manager
        else:
            self.registry_manager = LivroRegistroManager(db_url_or_path=db_url_or_path)

    def validar_codigo(self, codigo: Optional[str]) -> ResultadoValidacaoPublica:
        """
        Valida um Código de Autenticidade (SHA-256) contra o banco de registros.
        Retorna `ResultadoValidacaoPublica` com os dados do curso e assento formal,
        com mascaramento estrito de CPF conforme LGPD.
        """
        clean_code = clean_auth_code(codigo)
        if not clean_code:
            return ResultadoValidacaoPublica(
                autentico=False,
                mensagem="Código de autenticidade não informado ou inválido.",
                codigo_autenticidade="",
            )

        # Validação estrutural de integridade (SHA-256 de 64 caracteres hexadecimais)
        is_hex_sha256 = (
            len(clean_code) == 64
            and all(c in "0123456789ABCDEF" for c in clean_code)
        )

        registro: Optional[CertificadoRegistro] = (
            self.registry_manager.get_certificate_by_code(clean_code)
        )

        if registro is None:
            if not is_hex_sha256:
                msg = "Código de autenticidade em formato inválido ou adulterado."
            else:
                msg = "Certificado não localizado ou código de autenticidade não registrado."
            return ResultadoValidacaoPublica(
                autentico=False,
                mensagem=msg,
                codigo_autenticidade=clean_code,
            )

        return ResultadoValidacaoPublica.from_registro(registro)


def validar_certificado(
    codigo: Optional[str],
    manager: Optional[LivroRegistroManager] = None,
) -> ResultadoValidacaoPublica:
    """Função utilitária de conveniência para validação rápida de certificados."""
    service = ValidadorPublicoService(registry_manager=manager)
    return service.validar_codigo(codigo)
