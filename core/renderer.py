"""Motor de renderização de certificados em PDF vetorial A4 Paisagem (ReportLab).

Renderiza anverso e reverso com design contemporâneo de segurança:
- Fitas de guilloché generativo de ondas harmônicas e linhas perimétricas.
- Logotipo institucional (SVG/PNG) e tipografia Saira Condensed / Ubuntu Mono.
- Texto de concessão formal com carga horária por extenso em português.
- Alternância de assinatura (imagem digitalizada transparente ou linha manual).
- Verso em grid modular com Ementa detalhada, assento formal do Livro de Registro,
  QR Code de validação pública e fundamentação jurídica completa.
"""

from dataclasses import dataclass, field
from datetime import date, datetime
import io
import os
from pathlib import Path
import re
from typing import List, Optional, Sequence, Tuple, Union

from PIL import Image
import pypdf
import qrcode
from reportlab.graphics import renderPDF
from reportlab.lib import colors
from reportlab.lib.pagesizes import A4, landscape
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.utils import ImageReader, simpleSplit
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas
from reportlab.platypus import Frame, Paragraph
from svglib.svglib import svg2rlg

from core.guilloche import (
    draw_guilloche_frame,
    draw_modern_wave_ribbon,
    draw_security_seal,
    resolve_color,
)
from core.registry import CertificadoRegistro
from core.validator import format_cpf
from core.validator_service import (
    DEFAULT_VALIDATION_BASE_URL,
    build_validation_url,
)


# ==============================================================================
# Gerenciamento e Registro de Tipografia Oficial
# ==============================================================================

FONTS_DIR = Path(__file__).resolve().parent.parent / "assets" / "fonts"

FONT_MAP = {
    "SairaCondensed-Light": "SairaCondensed-Light.ttf",
    "SairaCondensed-Regular": "SairaCondensed-Regular.ttf",
    "SairaCondensed-Bold": "SairaCondensed-Bold.ttf",
    "SairaCondensed-Black": "SairaCondensed-Black.ttf",
    "UbuntuMono-Regular": "UbuntuMono-Regular.ttf",
    "UbuntuMono-Bold": "UbuntuMono-Bold.ttf",
}

_fonts_registered = False


def _register_project_fonts() -> None:
    """Registra as fontes TrueType do projeto no ReportLab caso ainda não registradas."""
    global _fonts_registered
    if _fonts_registered:
        return

    if FONTS_DIR.exists():
        for font_name, file_name in FONT_MAP.items():
            font_path = FONTS_DIR / file_name
            if font_path.exists():
                try:
                    pdfmetrics.registerFont(TTFont(font_name, str(font_path)))
                except Exception:
                    pass

    _fonts_registered = True


def _get_font(name: str, fallback: str) -> str:
    """Retorna o nome da fonte se registrada no pdfmetrics, senão o fallback nativo."""
    _register_project_fonts()
    try:
        pdfmetrics.getFont(name)
        return name
    except Exception:
        return fallback


def get_font_light() -> str:
    return _get_font("SairaCondensed-Light", "Helvetica")


def get_font_regular() -> str:
    return _get_font("SairaCondensed-Regular", "Helvetica")


def get_font_bold() -> str:
    return _get_font("SairaCondensed-Bold", "Helvetica-Bold")


def get_font_black() -> str:
    return _get_font("SairaCondensed-Black", "Helvetica-Bold")


def get_font_mono() -> str:
    return _get_font("UbuntuMono-Regular", "Courier")


def get_font_mono_bold() -> str:
    return _get_font("UbuntuMono-Bold", "Courier-Bold")


# ==============================================================================
# Configuração Visual do Renderizador
# ==============================================================================

MESES_EXTENSO = {
    1: "janeiro",
    2: "fevereiro",
    3: "março",
    4: "abril",
    5: "maio",
    6: "junho",
    7: "julho",
    8: "agosto",
    9: "setembro",
    10: "outubro",
    11: "novembro",
    12: "dezembro",
}

MESES_SIGLAS = {
    "jan": 1, "fev": 2, "mar": 3, "abr": 4, "mai": 5, "jun": 6,
    "jul": 7, "ago": 8, "set": 9, "out": 10, "nov": 11, "dez": 12,
}


def format_date_pt_extenso(val: Union[str, datetime, date, None]) -> str:
    """Formata uma data para extenso formal em português (ex: '18 de agosto de 2026')."""
    if not val:
        return ""
    if isinstance(val, (datetime, date)):
        return f"{val.day} de {MESES_EXTENSO[val.month]} de {val.year}"
    val_str = str(val).strip()
    if " de " in val_str.lower():
        return val_str
    # Formato DD/MM/AAAA ou DD-MM-AAAA
    m = re.match(r"^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$", val_str)
    if m:
        dia, mes, ano = int(m.group(1)), int(m.group(2)), int(m.group(3))
        if 1 <= mes <= 12 and 1 <= dia <= 31:
            return f"{dia} de {MESES_EXTENSO[mes]} de {ano}"
    # Formato AAAA-MM-DD ou AAAA/MM/DD
    m2 = re.match(r"^(\d{4})[/.-](\d{1,2})[/.-](\d{1,2})$", val_str)
    if m2:
        ano, mes, dia = int(m2.group(1)), int(m2.group(2)), int(m2.group(3))
        if 1 <= mes <= 12 and 1 <= dia <= 31:
            return f"{dia} de {MESES_EXTENSO[mes]} de {ano}"
    # Formato AAAA/mmm/DD (ex: 2026/ago/08)
    m3 = re.match(r"^(\d{4})[/.-]([a-zA-Z]{3})[/.-](\d{1,2})$", val_str)
    if m3:
        ano, mes_sigla, dia = int(m3.group(1)), m3.group(2).lower(), int(m3.group(3))
        mes_num = MESES_SIGLAS.get(mes_sigla)
        if mes_num and 1 <= dia <= 31:
            return f"{dia} de {MESES_EXTENSO[mes_num]} de {ano}"
    return val_str


@dataclass
class CertificateRenderConfig:
    """Parâmetros visuais e institucionais para desenho dos certificados."""

    primary_color: str = "#0E7490"  # Petróleo Tech
    secondary_color: str = "#EA580C"  # Coral Solar
    logo_path: Optional[Union[str, Path]] = None
    signature_image_path: Optional[Union[str, Path]] = "assets/assinatura.png"
    validation_base_url: str = "https://futurofacil.com.br/validar?codigo="
    institution_name: str = "FUTUROFÁCIL"
    institution_tagline: str = "CAPACITAÇÃO DIGITAL SOB MEDIDA"
    instructor_title: str = "Instrutor(a) Responsável · Capacitação Digital"
    instructor_default: str = "Tel Santana Leite"
    city_default: str = "Goiânia"
    student_signature_label: str = "Discente"
    instructor_signature_label: str = "Instrutor"
    cnpj: Optional[str] = None
    razao_social: Optional[str] = None
    margin: float = 24.0



# ==============================================================================
# Helpers de Desenho: Logo, QR Code e Formatação
# ==============================================================================

def _draw_spaced_text(
    c: canvas.Canvas,
    x: float,
    y: float,
    text: str,
    font_name: str,
    font_size: float,
    fill_color: colors.Color,
    char_space: float = 0.0,
) -> None:
    """Desenha uma string no canvas aplicando tracking/charSpace via textObject."""
    to = c.beginText(x, y)
    to.setFont(font_name, font_size)
    to.setFillColor(fill_color)
    if char_space:
        to.setCharSpace(char_space)
    to.textOut(text)
    c.drawText(to)


def _draw_logo_or_wordmark(
    c: canvas.Canvas,
    x: float,
    y: float,
    config: CertificateRenderConfig,
    primary_col: colors.Color,
    secondary_col: colors.Color,
) -> None:
    """Desenha o logotipo institucional (SVG ou PNG) ou a marca oficial FUTUROFÁCIL."""
    has_custom_logo = False
    is_full_logo = False

    if config.logo_path and os.path.exists(str(config.logo_path)):
        logo_str = str(config.logo_path)
        try:
            if logo_str.lower().endswith(".svg"):
                drawing = svg2rlg(logo_str)
                if drawing:
                    # Escala proporcional para altura ~36 pt
                    scale = 36.0 / max(1.0, float(drawing.height))
                    drawing.scale(scale, scale)
                    renderPDF.draw(drawing, c, x, y)
                    has_custom_logo = True
                    if (drawing.width * scale) > 60:
                        is_full_logo = True
            else:
                img = ImageReader(logo_str)
                c.drawImage(img, x, y, width=42, height=42, preserveAspectRatio=True, mask="auto")
                has_custom_logo = True
        except Exception:
            has_custom_logo = False

    offset_x = x
    if not has_custom_logo:
        # Símbolo circular modular vetorial (slot de proporção 1:1)
        c.saveState()
        cx = x + 16
        cy = y + 16
        c.setFillColor(colors.white)
        c.setStrokeColor(colors.HexColor("#CBD5E1"))
        c.setLineWidth(1.2)
        c.circle(cx, cy, 16, stroke=1, fill=1)

        # Fita fluida de destravamento estilizada
        c.setStrokeColor(primary_col)
        c.setLineWidth(2.2)
        c.setLineCap(1)
        p = c.beginPath()
        p.moveTo(cx - 8, cy - 8)
        p.curveTo(cx - 12, cy, cx - 4, cy + 8, cx + 4, cy + 6)
        p.curveTo(cx + 10, cy + 4, cx + 10, cy - 6, cx + 4, cy - 8)
        c.drawPath(p, stroke=1, fill=0)

        # Ponto de ignição solar
        c.setFillColor(secondary_col)
        c.circle(cx + 8, cy + 8, 2.5, stroke=0, fill=1)
        c.restoreState()
        offset_x = x + 42
    else:
        offset_x = x + 50

    # Se já renderizou um logotipo horizontal completo via arquivo, não duplica o wordmark
    if not is_full_logo:
        # Wordmark FUTUROFÁCIL unificado sem espaço
        c.saveState()
        font_light = get_font_light()
        font_black = get_font_black()
        font_bold = get_font_bold()

        c.setFont(font_light, 24)
        c.setFillColor(primary_col)
        c.drawString(offset_x, y + 18, "FUTURO")
        futuro_w = c.stringWidth("FUTURO", font_light, 24)

        c.setFont(font_black, 24)
        c.setFillColor(secondary_col)
        c.drawString(offset_x + futuro_w, y + 18, "FÁCIL")

        # Tagline oficial com tracking
        _draw_spaced_text(
            c,
            x=offset_x,
            y=y + 6,
            text=config.institution_tagline,
            font_name=font_bold,
            font_size=7.5,
            fill_color=colors.HexColor("#64748B"),
            char_space=1.8,
        )

        c.restoreState()


# Reutiliza o construtor unificado de URL de validação
_build_validation_url = build_validation_url


def _draw_vector_qr_code(
    c: canvas.Canvas,
    x: float,
    y: float,
    size: float,
    url: str,
    fill_color: Optional[colors.Color] = None,
) -> None:
    """Renderiza um QR Code 100% vetorial através de módulos diretos no canvas."""
    qr = qrcode.QRCode(
        version=1,
        error_correction=qrcode.constants.ERROR_CORRECT_M,
        box_size=1,
        border=0,
    )
    qr.add_data(url)
    qr.make(fit=True)
    matrix = qr.modules
    n = len(matrix)
    box_size = size / max(1, n)

    c.saveState()
    c.setFillColor(fill_color if fill_color is not None else colors.black)
    for r in range(n):
        for col in range(n):
            if matrix[r][col]:
                c.rect(x + col * box_size, y + (n - 1 - r) * box_size, box_size, box_size, fill=1, stroke=0)
    c.restoreState()


def save_pdf_bytes(
    pdf_bytes: bytes,
    output_path_or_buffer: Optional[Union[str, Path, io.BytesIO]] = None,
) -> bytes:
    """Grava os bytes do PDF em arquivo ou buffer, se fornecido, e retorna os bytes."""
    if output_path_or_buffer is not None:
        if isinstance(output_path_or_buffer, io.BytesIO):
            output_path_or_buffer.write(pdf_bytes)
        else:
            p = Path(output_path_or_buffer)
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_bytes(pdf_bytes)
    return pdf_bytes


# ==============================================================================
# Renderização do Anverso (Frente)
# ==============================================================================

def render_anverso(
    c: canvas.Canvas,
    registro: CertificadoRegistro,
    config: CertificateRenderConfig,
    width: float = 841.89,
    height: float = 595.27,
) -> None:
    """Desenha o anverso (frente) A4 Paisagem do certificado."""
    primary_col = resolve_color(config.primary_color)
    secondary_col = resolve_color(config.secondary_color)
    margin = config.margin

    # Fundo do certificado (suave off-white com sangria total)
    c.saveState()
    c.setFillColor(colors.HexColor("#FAFCFF"))
    c.rect(0, 0, width, height, fill=1, stroke=0)
    c.restoreState()

    # Moldura de segurança com fita de guilloché
    draw_guilloche_frame(
        c,
        width=width,
        height=height,
        margin=margin,
        primary_color=primary_col,
        secondary_color=secondary_col,
    )

    # Logotipo & Wordmark no Canto Superior Esquerdo
    _draw_logo_or_wordmark(
        c,
        x=margin + 42,
        y=height - margin - 56,
        config=config,
        primary_col=primary_col,
        secondary_col=secondary_col,
    )

    # Card do Assento Digital no Canto Superior Direito
    c.saveState()
    card_w = 160
    card_h = 42
    card_x = width - margin - card_w - 38
    card_y = height - margin - card_h - 18

    c.setFillColor(colors.white)
    c.setStrokeColor(colors.HexColor("#E2E8F0"))
    c.setLineWidth(1)
    c.roundRect(card_x, card_y, card_w, card_h, 5, fill=1, stroke=1)

    c.setFont(get_font_bold(), 8)
    c.setFillColor(primary_col)
    c.drawString(card_x + 12, card_y + 26, "REGISTRO DIGITAL")

    c.setFont(get_font_mono_bold(), 9)
    c.setFillColor(secondary_col)
    reg_text = f"LIVRO {registro.livro_numero:02d} · FLS {registro.folha_numero:03d} · REG {registro.registro_numero:03d}"
    c.drawString(card_x + 12, card_y + 11, reg_text)
    c.restoreState()

    # Bloco do Título Editorial
    c.saveState()
    title_x = margin + 42
    title_y = height - 165

    # Título CERTIFICADO (sem tracking forçado)
    c.setFont(get_font_light(), 46)
    c.setFillColor(primary_col)
    c.drawString(title_x, title_y, "CERTIFICADO")

    # Linha acento solar abaixo do título
    c.setStrokeColor(secondary_col)
    c.setLineWidth(2.5)
    c.line(title_x, title_y - 12, title_x + 140, title_y - 12)
    c.restoreState()

    # Bloco do Texto de Concessão com Quebra Dinâmica e Alinhamento
    c.saveState()
    body_x = margin + 42
    curr_y = height - 245
    max_w = width - (margin + 42) * 2  # largura útil de texto ~710 pt

    # Frase inicial
    c.setFont(get_font_regular(), 13.5)
    c.setFillColor(colors.HexColor("#64748B"))
    c.drawString(body_x, curr_y, "Certificamos com distinção que")
    curr_y -= 32

    # Nome do Aluno (com redução proporcional se nome for excepcionalmente longo)
    nome_exibicao = registro.aluno_nome.upper()
    font_aluno = get_font_black()
    size_aluno = 27.0
    while c.stringWidth(nome_exibicao, font_aluno, size_aluno) > max_w and size_aluno > 18.0:
        size_aluno -= 0.5
    c.setFont(font_aluno, size_aluno)
    c.setFillColor(primary_col)
    c.drawString(body_x, curr_y, nome_exibicao)
    curr_y -= 28

    # Identificação do CPF e introdução ao curso
    c.setFont(get_font_regular(), 13)
    c.setFillColor(colors.HexColor("#334155"))
    if registro.aluno_cpf and str(registro.aluno_cpf).strip():
        cpf_formatado = format_cpf(registro.aluno_cpf)
        c.drawString(body_x, curr_y, f"inscrito(a) no CPF sob o nº {cpf_formatado}, concluiu o treinamento prático de")
    else:
        c.drawString(body_x, curr_y, "concluiu o treinamento prático de")
    curr_y -= 28

    # Nome do Curso (quebra dinâmica de linha automática via simpleSplit)
    c.setFont(get_font_black(), 20)
    c.setFillColor(secondary_col)
    curso_lines = simpleSplit(registro.curso_nome, get_font_black(), 20, max_w)
    for cline in curso_lines:
        c.drawString(body_x, curr_y, cline)
        curr_y -= 24
    curr_y += 2

    # Período, Carga Horária e Frequência (quebra dinâmica de linha automática)
    if registro.data_inicio and registro.data_conclusao and registro.data_inicio != registro.data_conclusao:
        periodo_str = f"no período de {registro.data_inicio} a {registro.data_conclusao}"
    elif registro.data_conclusao:
        periodo_str = f"em {registro.data_conclusao}"
    else:
        periodo_str = ""

    horas_str = f"{registro.carga_horaria} horas ({registro.carga_horaria_extenso})"
    freq_val = getattr(registro, "frequencia", 100) or 100
    realizado_prefix = f"realizado pela Futuro Fácil {periodo_str}, " if periodo_str else "realizado pela Futuro Fácil, "
    linha_conclusao = f"{realizado_prefix}perfazendo carga horária total de {horas_str}, com {freq_val}% de frequência."

    c.setFont(get_font_regular(), 12.5)
    c.setFillColor(colors.HexColor("#334155"))
    conclusao_lines = simpleSplit(linha_conclusao, get_font_regular(), 12.5, max_w)
    for lline in conclusao_lines:
        c.drawString(body_x, curr_y, lline)
        curr_y -= 18

    # Cidade e Data de Emissão por extenso (alinhada à DIREITA, conforme tradição formal)
    curr_y -= 8
    cidade_nome = registro.cidade.strip() if (registro.cidade and registro.cidade.strip()) else config.city_default
    data_raw = registro.data_emissao or registro.data_conclusao or ""
    data_extenso = format_date_pt_extenso(data_raw)
    cidade_data = f"{cidade_nome}, {data_extenso}." if data_extenso else f"{cidade_nome}."

    right_margin_x = width - margin - 50  # alinhamento à direita com a linha de assinatura do instrutor
    c.setFont(get_font_bold(), 10.5)
    c.setFillColor(primary_col)
    c.drawRightString(right_margin_x, curr_y, cidade_data)
    c.restoreState()

    # ==============================================================================
    # Rodapé: Dupla Assinatura (Discente e Instrutor)
    # ==============================================================================
    sig_y = margin + 85
    sig_w = 240

    # Assinatura 1: Aluno (Discente)
    sig1_x = margin + 50
    c.saveState()
    c.setStrokeColor(primary_col)
    c.setLineWidth(1.0)
    c.line(sig1_x, sig_y, sig1_x + sig_w, sig_y)

    c.setFont(get_font_black(), 10.5)
    c.setFillColor(primary_col)
    c.drawCentredString(sig1_x + sig_w / 2.0, sig_y - 14, nome_exibicao)

    c.setFont(get_font_regular(), 8.5)
    c.setFillColor(colors.HexColor("#64748B"))
    c.drawCentredString(sig1_x + sig_w / 2.0, sig_y - 25, config.student_signature_label)
    c.restoreState()

    # Assinatura 2: Instrutor (Instrutor)
    sig2_x = width - margin - sig_w - 50
    c.saveState()

    if config.signature_image_path and os.path.exists(str(config.signature_image_path)):
        try:
            sig_img = ImageReader(str(config.signature_image_path))
            iw, ih = sig_img.getSize()
            aspect = (iw / ih) if ih > 0 else 1.0
            target_h = 48.0
            target_w = target_h * aspect
            max_w = sig_w - 20.0
            if target_w > max_w:
                target_w = max_w
                target_h = target_w / aspect
            img_x = sig2_x + (sig_w - target_w) / 2.0
            img_y = sig_y + 2.0
            c.drawImage(sig_img, img_x, img_y, width=target_w, height=target_h, preserveAspectRatio=True, mask="auto")
        except Exception:
            pass

    c.setStrokeColor(primary_col)
    c.setLineWidth(1.0)
    c.line(sig2_x, sig_y, sig2_x + sig_w, sig_y)

    c.setFont(get_font_black(), 10.5)
    c.setFillColor(primary_col)
    instrutor_nome = registro.instrutor.strip() if (registro.instrutor and registro.instrutor.strip()) else config.instructor_default
    c.drawCentredString(sig2_x + sig_w / 2.0, sig_y - 14, instrutor_nome)

    c.setFont(get_font_regular(), 8.5)
    c.setFillColor(colors.HexColor("#64748B"))
    c.drawCentredString(sig2_x + sig_w / 2.0, sig_y - 25, config.instructor_signature_label)
    c.restoreState()

    # ==============================================================================
    # Faixa Inferior: Cláusula Legal Completa
    # ==============================================================================
    c.saveState()
    legal_y = margin + 26
    legal_x = margin + 36

    c.setFont(get_font_regular(), 7.2)
    c.setFillColor(colors.HexColor("#64748B"))
    emissor_info = f" por {config.razao_social} (CNPJ: {config.cnpj})" if (config.cnpj and config.razao_social) else (f" (CNPJ: {config.cnpj})" if config.cnpj else "")
    c.drawString(
        legal_x,
        legal_y + 9,
        f"Curso livre de capacitação profissional ministrado{emissor_info} nos termos dos arts. 170 e 205 da CF/88, art. 42 da Lei nº 9.394/96 e Decreto Federal nº 5.154/2004.",
    )
    c.drawString(
        legal_x,
        legal_y,
        "Validade nacional assegurada pelo art. 219 da Lei nº 10.406/2002 (Código Civil) e art. 10, § 2º da Medida Provisória nº 2.200-2/2001.",
    )
    c.restoreState()


# ==============================================================================
# Renderização do Reverso (Verso)
# ==============================================================================

def render_reverso(
    c: canvas.Canvas,
    registro: CertificadoRegistro,
    config: CertificateRenderConfig,
    width: float = 841.89,
    height: float = 595.27,
) -> None:
    """Desenha o reverso (verso) A4 Paisagem com Ementa, Registro e Fundamentação."""
    primary_col = resolve_color(config.primary_color)
    secondary_col = resolve_color(config.secondary_color)
    margin = config.margin

    # Fundo suave (sangria total)
    c.saveState()
    c.setFillColor(colors.HexColor("#FAFCFF"))
    c.rect(0, 0, width, height, fill=1, stroke=0)

    # Moldura de segurança sutil perimétrica
    c.setStrokeColor(primary_col)
    c.setLineWidth(0.65)
    c.rect(margin + 2, margin + 2, width - (margin + 2) * 2, height - (margin + 2) * 2, stroke=1, fill=0)
    c.setStrokeColor(secondary_col)
    c.setLineWidth(0.35)
    c.rect(margin + 6, margin + 6, width - (margin + 6) * 2, height - (margin + 6) * 2, stroke=1, fill=0)
    c.restoreState()

    # Cabeçalho do Verso
    c.saveState()
    header_x = margin + 30
    header_y = height - margin - 22

    c.setFont(get_font_black(), 15)
    c.setFillColor(primary_col)
    c.drawString(header_x, header_y, "EMENTA E REGISTRO DO CURSO")

    c.setFont(get_font_bold(), 8.5)
    c.setFillColor(secondary_col)
    c.drawString(header_x, header_y - 13, "DOCUMENTO OFICIAL COM VALIDADE NACIONAL · AMPARO LEGAL: LEI Nº 9.394/96")

    c.setStrokeColor(secondary_col)
    c.setLineWidth(1.5)
    c.line(header_x, header_y - 19, width - margin - 30, header_y - 19)
    c.restoreState()

    # --------------------------------------------------------------------------
    # Coluna Esquerda: Ementa do Curso e Metadados
    # --------------------------------------------------------------------------
    cards_top_y = header_y - 30
    col_left_x = margin + 30
    col_left_y = margin + 80
    col_left_w = 460
    col_left_h = cards_top_y - col_left_y

    # Card da Ementa
    c.saveState()
    c.setFillColor(colors.white)
    c.setStrokeColor(colors.HexColor("#E2E8F0"))
    c.setLineWidth(1)
    c.roundRect(col_left_x, col_left_y, col_left_w, col_left_h, 6, fill=1, stroke=1)

    # Faixa de título do card
    c.setFillColor(colors.HexColor("#F1F5F9"))
    c.roundRect(col_left_x, col_left_y + col_left_h - 28, col_left_w, 28, 6, fill=1, stroke=0)
    c.rect(col_left_x, col_left_y + col_left_h - 28, col_left_w, 10, fill=1, stroke=0)

    c.setFont(get_font_bold(), 9.5)
    c.setFillColor(primary_col)
    c.drawString(col_left_x + 14, col_left_y + col_left_h - 18, "CONTEÚDO PROGRAMÁTICO E COMPETÊNCIAS")
    c.restoreState()

    # Conteúdo da Ementa formatado via Platypus Frame & Paragraph
    ementa_frame = Frame(
        col_left_x + 10,
        col_left_y + 10,
        col_left_w - 20,
        col_left_h - 44,
        topPadding=0,
        bottomPadding=0,
        leftPadding=6,
        rightPadding=6,
        showBoundary=0,
    )

    styles = getSampleStyleSheet()
    p_style = ParagraphStyle(
        name="EmentaStyle",
        fontName=get_font_regular(),
        fontSize=9.5,
        leading=14.5,
        textColor=colors.HexColor("#334155"),
    )
    bold_style = ParagraphStyle(
        name="EmentaBoldStyle",
        fontName=get_font_bold(),
        fontSize=10,
        leading=14,
        textColor=primary_col,
    )

    ementa_text = registro.ementa.strip() if registro.ementa else "Conteúdo programático prático e teórico concluído com êxito conforme plano de ensino."
    # Transforma quebras de linha em tags HTML <br/> para o Paragraph
    ementa_html = ementa_text.replace("\n", "<br/>")

    story = [
        Paragraph(f"<b>Curso:</b> {registro.curso_nome}", bold_style),
        Paragraph(f"<b>Carga Horária Total:</b> {registro.carga_horaria} horas ({registro.carga_horaria_extenso})", p_style),
        Paragraph(f"<b>Modalidade:</b> {registro.modalidade}", p_style),
        Paragraph("<br/><b>Tópicos e Competências Desenvolvidas:</b>", bold_style),
        Paragraph(ementa_html, p_style),
    ]
    ementa_frame.addFromList(story, c)

    # --------------------------------------------------------------------------
    # Coluna Direita: Assento Formal do Livro e Bloco de Autenticidade com QR Code
    # --------------------------------------------------------------------------
    col_right_x = col_left_x + col_left_w + 18
    col_right_w = width - col_right_x - margin - 30
    col_right_h = col_left_h

    # Card 1: Assento no Livro de Registro
    card_book_h = 120
    card_book_y = col_left_y + col_right_h - card_book_h

    c.saveState()
    c.setFillColor(colors.white)
    c.setStrokeColor(colors.HexColor("#E2E8F0"))
    c.setLineWidth(1)
    c.roundRect(col_right_x, card_book_y, col_right_w, card_book_h, 6, fill=1, stroke=1)

    c.setFillColor(colors.HexColor("#F1F5F9"))
    c.roundRect(col_right_x, card_book_y + card_book_h - 26, col_right_w, 26, 6, fill=1, stroke=0)
    c.rect(col_right_x, card_book_y + card_book_h - 26, col_right_w, 10, fill=1, stroke=0)

    c.setFont(get_font_bold(), 9)
    c.setFillColor(primary_col)
    c.drawString(col_right_x + 12, card_book_y + card_book_h - 17, "ASSENTO NO LIVRO DE REGISTRO DIGITAL")

    # Linhas de Livro, Folha e Registro
    c.setFont(get_font_bold(), 9)
    c.setFillColor(colors.HexColor("#475569"))
    c.drawString(col_right_x + 14, card_book_y + 70, "Livro de Registro:")
    c.drawString(col_right_x + 14, card_book_y + 50, "Folha nº:")
    c.drawString(col_right_x + 14, card_book_y + 30, "Registro nº:")
    c.drawString(col_right_x + 14, card_book_y + 10, "Data de Expedição:")

    c.setFont(get_font_mono_bold(), 10)
    c.setFillColor(secondary_col)
    c.drawRightString(col_right_x + col_right_w - 14, card_book_y + 70, f"{registro.livro_numero:02d}")
    c.drawRightString(col_right_x + col_right_w - 14, card_book_y + 50, f"{registro.folha_numero:03d}")
    c.drawRightString(col_right_x + col_right_w - 14, card_book_y + 30, f"{registro.registro_numero:03d}")
    c.setFont(get_font_bold(), 9)
    c.setFillColor(primary_col)
    c.drawRightString(col_right_x + col_right_w - 14, card_book_y + 10, str(registro.data_emissao))
    c.restoreState()

    # Card 2: Autenticidade e Validação Eletrônica (QR Code)
    card_qr_y = col_left_y
    card_qr_h = col_right_h - card_book_h - 14

    c.saveState()
    c.setFillColor(colors.white)
    c.setStrokeColor(colors.HexColor("#E2E8F0"))
    c.setLineWidth(1)
    c.roundRect(col_right_x, card_qr_y, col_right_w, card_qr_h, 6, fill=1, stroke=1)

    c.setFillColor(colors.HexColor("#F1F5F9"))
    c.roundRect(col_right_x, card_qr_y + card_qr_h - 26, col_right_w, 26, 6, fill=1, stroke=0)
    c.rect(col_right_x, card_qr_y + card_qr_h - 26, col_right_w, 10, fill=1, stroke=0)

    c.setFont(get_font_bold(), 9)
    c.setFillColor(primary_col)
    c.drawString(col_right_x + 12, card_qr_y + card_qr_h - 17, "AUTENTICIDADE E VALIDAÇÃO DIGITAL")

    # Desenho do QR Code 100% Vetorial
    validation_url = _build_validation_url(config.validation_base_url, registro.codigo_autenticidade)
    qr_size = 84.0
    qr_x = col_right_x + (col_right_w - qr_size) / 2.0
    qr_y = card_qr_y + card_qr_h - qr_size - 38
    _draw_vector_qr_code(c, qr_x, qr_y, qr_size, validation_url, fill_color=primary_col)

    # Instrução de escaneamento e inicialização sob demanda
    c.setFont(get_font_bold(), 7.2)
    c.setFillColor(colors.HexColor("#475569"))
    c.drawCentredString(
        col_right_x + col_right_w / 2.0,
        qr_y - 12,
        "Validação digital pública via QR Code",
    )

    c.setFont(get_font_regular(), 6.5)
    c.setFillColor(colors.HexColor("#64748B"))
    c.drawCentredString(
        col_right_x + col_right_w / 2.0,
        qr_y - 22,
        "Consulte a autenticidade no portal institucional",
    )

    # Caixa do Código de Autenticidade (SHA-256)
    code_box_y = card_qr_y + 10
    code_box_h = 36
    c.setFillColor(colors.HexColor("#F8FAFC"))
    c.setStrokeColor(colors.HexColor("#CBD5E1"))
    c.setLineWidth(0.7)
    c.roundRect(col_right_x + 10, code_box_y, col_right_w - 20, code_box_h, 4, fill=1, stroke=1)

    c.setFont(get_font_bold(), 6.5)
    c.setFillColor(colors.HexColor("#475569"))
    c.drawString(col_right_x + 16, code_box_y + code_box_h - 10, "CÓDIGO DE AUTENTICIDADE (SHA-256):")

    c.setFont(get_font_mono_bold(), 6.8)
    c.setFillColor(secondary_col)
    code = registro.codigo_autenticidade
    # Quebra o hash em duas metades para não vazar a caixa
    c.drawString(col_right_x + 16, code_box_y + 14, code[:32])
    c.drawString(col_right_x + 16, code_box_y + 4, code[32:])
    c.restoreState()

    # --------------------------------------------------------------------------
    # Faixa Inferior: Minutas Jurídicas Integrais (CF/88, LDB, Dec. 5.154, CC, MP 2.200)
    # --------------------------------------------------------------------------
    c.saveState()
    legal_full_x = margin + 30
    legal_full_y = margin + 14
    legal_full_w = width - (margin + 30) * 2

    c.setFont(get_font_bold(), 7.5)
    c.setFillColor(primary_col)
    entidade_prefix = f"Entidade Emissora: {config.razao_social} (CNPJ: {config.cnpj}) · " if config.cnpj else ""
    c.drawString(legal_full_x, legal_full_y + 48, f"{entidade_prefix}FUNDAMENTAÇÃO JURÍDICA INTEGRAL E EFICÁCIA LEGAL:")

    c.setFont(get_font_regular(), 6.8)
    c.setFillColor(colors.HexColor("#475569"))

    l1 = (
        "1. Base Constitucional e LDB: Certificado expedido em estrita conformidade com o art. 205 da Constituição Federal de 1988, art. 42 da "
        "Lei Federal nº 9.394/1996 (Diretrizes e Bases da Educação Nacional) e arts. 1º e 3º do Decreto Federal nº 5.154/2004, caracterizando-se como "
        "Curso Livre de capacitação profissional."
    )
    l2 = (
        "2. Fé Pública e Validade Probatória: Documento dotado de eficácia e presunção de veracidade entre as partes, com fulcro no art. 219 da "
        "Lei Federal nº 10.406/2002 (Código Civil Brasileiro)."
    )
    l3 = (
        "3. Autenticidade Digital e Presunção de Integridade: Autenticidade assegurada com fundamento no art. 10, § 2º da Medida Provisória nº 2.200-2/2001, "
        "podendo ser verificada publicamente mediante leitura do QR Code ou inserção do Código de Autenticidade no portal de validação."
    )

    c.drawString(legal_full_x, legal_full_y + 34, l1)
    c.drawString(legal_full_x, legal_full_y + 22, l2)
    c.drawString(legal_full_x, legal_full_y + 10, l3)
    c.restoreState()


# ==============================================================================
# Funções Públicas de Geração
# ==============================================================================

def generate_certificate_pdf(
    registro: CertificadoRegistro,
    output_path_or_buffer: Optional[Union[str, Path, io.BytesIO]] = None,
    config: Optional[CertificateRenderConfig] = None,
) -> bytes:
    """
    Gera o PDF individual de um certificado em formato A4 Paisagem (exatamente 2 páginas duplex: Frente e Verso).
    Retorna os bytes do PDF gerado e opcionalmente grava no arquivo/buffer informado.
    """
    if config is None:
        config = CertificateRenderConfig()

    buf = io.BytesIO()
    c = canvas.Canvas(buf, pagesize=landscape(A4))

    # Página 1: Frente (Anverso)
    render_anverso(c, registro, config)
    c.showPage()

    # Página 2: Verso (Reverso)
    render_reverso(c, registro, config)
    c.showPage()

    c.save()
    pdf_bytes = buf.getvalue()

    return save_pdf_bytes(pdf_bytes, output_path_or_buffer)


def generate_batch_certificates(
    registros: Sequence[CertificadoRegistro],
    output_dir: Union[str, Path],
    config: Optional[CertificateRenderConfig] = None,
) -> List[Path]:
    """
    Gera os PDFs individuais duplex para todos os registros de alunos informados,
    salvando-os no diretório indicado com nomenclatura padronizada.
    """
    out_path = Path(output_dir)
    out_path.mkdir(parents=True, exist_ok=True)

    generated: List[Path] = []
    for reg in registros:
        # Sanitiza nome para nome de arquivo seguro
        nome_slug = re.sub(r"[^\w\-]", "_", reg.aluno_nome.strip().lower())
        file_name = f"certificado_{reg.registro_numero:04d}_{nome_slug}.pdf"
        target_file = out_path / file_name
        generate_certificate_pdf(reg, output_path_or_buffer=target_file, config=config)
        generated.append(target_file)

    return generated
