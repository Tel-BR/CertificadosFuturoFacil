"""Testes unitários para o motor de renderização de certificados em PDF A4 Paisagem (Seam 2)."""

import io
from pathlib import Path
import pytest
import pypdf
from PIL import Image

from core.registry import CertificadoRegistro
from core.renderer import (
    CertificateRenderConfig,
    generate_certificate_pdf,
    generate_batch_certificates,
)


@pytest.fixture
def sample_registro():
    """Certificado de teste com todos os campos preenchidos."""
    return CertificadoRegistro(
        id=1,
        codigo_autenticidade="A1B2C3D4E5F60718293A4B5C6D7E8F901234567890ABCDEF1234567890ABCDEF",
        aluno_nome="Marcos Vinícius de Almeida",
        aluno_cpf="12345678901",
        aluno_cpf_mascarado="***.456.789-**",
        curso_nome="Excel Corporativo & Automação de Rotinas",
        carga_horaria=40,
        carga_horaria_extenso="quarenta horas",
        data_inicio="10/08/2026",
        data_conclusao="28/08/2026",
        data_emissao="28/08/2026",
        modalidade="Curso Livre de Capacitação Profissional",
        instrutor="Prof. Carlos Eduardo Silveira",
        cidade="São Paulo - SP",
        ementa="Módulo 1: Introdução ao Excel; Módulo 2: Fórmulas Avançadas e Procv; Módulo 3: Tabelas Dinâmicas e Dashboards; Módulo 4: Automação com Macros e IA.",
        livro_numero=1,
        folha_numero=42,
        registro_numero=42,
    )


def test_certificate_pdf_page_count_and_dimensions(sample_registro):
    """Verifica que o PDF gerado tem exatamente 2 páginas e dimensões A4 Paisagem (841.89 x 595.27 pt)."""
    pdf_bytes = generate_certificate_pdf(sample_registro)
    reader = pypdf.PdfReader(io.BytesIO(pdf_bytes))

    assert len(reader.pages) == 2, "O certificado individual deve ter exatamente 2 páginas (Frente e Verso)"

    for i, page in enumerate(reader.pages):
        width = float(page.mediabox.width)
        height = float(page.mediabox.height)
        assert pytest.approx(width, abs=1.0) == 841.89, f"Página {i+1} largura deve ser A4 Paisagem (~842 pt)"
        assert pytest.approx(height, abs=1.0) == 595.27, f"Página {i+1} altura deve ser A4 Paisagem (~595 pt)"


def test_certificate_anverso_and_reverso_text_content(sample_registro):
    """Verifica se os textos obrigatórios estão presentes nas páginas do PDF."""
    pdf_bytes = generate_certificate_pdf(sample_registro)
    reader = pypdf.PdfReader(io.BytesIO(pdf_bytes))

    # Página 1: Frente (Anverso)
    page1_text = reader.pages[0].extract_text()
    assert "CERTIFICADO" in page1_text
    assert "Marcos Vinícius de Almeida" in page1_text or "MARCOS VINÍCIUS DE ALMEIDA" in page1_text
    assert "Excel Corporativo" in page1_text
    assert "quarenta horas" in page1_text
    assert "Decreto" in page1_text
    assert "5.154/2004" in page1_text
    assert "170" in page1_text
    assert "Carlos Eduardo Silveira" in page1_text

    # Página 2: Verso (Reverso)
    page2_text = reader.pages[1].extract_text()
    assert "EMENTA" in page2_text or "CONTEÚDO PROGRAMÁTICO" in page2_text
    assert "Módulo 1: Introdução ao Excel" in page2_text
    assert "LIVRO" in page2_text.upper()
    assert "42" in page2_text
    assert "A1B2C3D4" in page2_text
    assert "2.200-2/2001" in page2_text
    assert "Código Civil" in page2_text or "10.406" in page2_text


def test_signature_toggle_with_and_without_image(tmp_path, sample_registro):
    """Verifica a geração do certificado com imagem de assinatura e sem imagem (linha manual)."""
    # 1. Sem imagem de assinatura (linha em branco)
    config_sem_sig = CertificateRenderConfig(signature_image_path=None)
    pdf_sem_sig = generate_certificate_pdf(sample_registro, config=config_sem_sig)
    assert len(pdf_sem_sig) > 1000

    # 2. Com imagem de assinatura PNG temporária
    sig_img_path = tmp_path / "assinatura.png"
    img = Image.new("RGBA", (200, 60), color=(0, 0, 0, 0))
    img.save(sig_img_path)

    config_com_sig = CertificateRenderConfig(signature_image_path=sig_img_path)
    pdf_com_sig = generate_certificate_pdf(sample_registro, config=config_com_sig)
    assert len(pdf_com_sig) > 1000
    assert pdf_com_sig != pdf_sem_sig


def test_logo_support_png_svg_and_fallback(tmp_path, sample_registro):
    """Verifica renderização com logo PNG, SVG e sem logo."""
    # Logo PNG
    png_path = tmp_path / "logo.png"
    img = Image.new("RGB", (120, 120), color=(14, 116, 144))
    img.save(png_path)
    config_png = CertificateRenderConfig(logo_path=png_path)
    pdf_png = generate_certificate_pdf(sample_registro, config=config_png)
    assert len(pdf_png) > 1000

    # Logo SVG
    svg_path = tmp_path / "logo.svg"
    svg_path.write_text('<svg width="100" height="100" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="40" fill="#EA580C"/></svg>', encoding="utf-8")
    config_svg = CertificateRenderConfig(logo_path=svg_path)
    pdf_svg = generate_certificate_pdf(sample_registro, config=config_svg)
    assert len(pdf_svg) > 1000

    # Sem logo (fallback do slot circular e wordmark)
    config_none = CertificateRenderConfig(logo_path=None)
    pdf_none = generate_certificate_pdf(sample_registro, config=config_none)
    assert len(pdf_none) > 1000


def test_generate_batch_certificates(tmp_path, sample_registro):
    """Valida a geração de lote de certificados individuais salvos em disco."""
    reg2 = CertificadoRegistro(
        id=2,
        codigo_autenticidade="B2C3D4E5F60718293A4B5C6D7E8F901234567890ABCDEF1234567890ABCDEF01",
        aluno_nome="Ana Clara Souza",
        aluno_cpf="98765432100",
        aluno_cpf_mascarado="***.654.321-**",
        curso_nome="Excel Corporativo & Automação de Rotinas",
        carga_horaria=40,
        carga_horaria_extenso="quarenta horas",
        data_inicio="10/08/2026",
        data_conclusao="28/08/2026",
        data_emissao="28/08/2026",
        modalidade="Curso Livre de Capacitação Profissional",
        instrutor="Prof. Carlos Eduardo Silveira",
        cidade="São Paulo - SP",
        ementa="Módulo 1: Introdução; Módulo 2: Automação.",
        livro_numero=1,
        folha_numero=43,
        registro_numero=43,
    )

    out_dir = tmp_path / "certificados_individuais"
    out_dir.mkdir()

    generated_paths = generate_batch_certificates([sample_registro, reg2], output_dir=out_dir)

    assert len(generated_paths) == 2
    for p in generated_paths:
        assert p.exists()
        assert p.suffix == ".pdf"
        reader = pypdf.PdfReader(str(p))
        assert len(reader.pages) == 2
