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
    """Verifica se os textos obrigatórios e novos padrões estão presentes nas páginas do PDF."""
    pdf_bytes = generate_certificate_pdf(sample_registro)
    reader = pypdf.PdfReader(io.BytesIO(pdf_bytes))

    # Página 1: Frente (Anverso)
    page1_text = reader.pages[0].extract_text()
    assert "CERTIFICADO" in page1_text
    # Subtítulo redundante removido
    assert "DOCUMENTO OFICIAL DE CONCLUSÃO" not in page1_text
    assert "Marcos Vinícius de Almeida" in page1_text or "MARCOS VINÍCIUS DE ALMEIDA" in page1_text
    assert "Excel Corporativo" in page1_text
    assert "quarenta horas" in page1_text
    assert "Futuro Fácil" in page1_text
    assert "frequência" in page1_text
    assert "Discente" in page1_text
    assert "Instrutor" in page1_text
    assert "Carlos Eduardo Silveira" in page1_text
    assert "Decreto" in page1_text
    assert "5.154/2004" in page1_text
    assert "170" in page1_text

    # Página 2: Verso (Reverso)
    page2_text = reader.pages[1].extract_text()
    assert "EMENTA" in page2_text or "CONTEÚDO PROGRAMÁTICO" in page2_text
    assert "Módulo 1: Introdução ao Excel" in page2_text
    assert "LIVRO" in page2_text.upper()
    assert "42" in page2_text
    assert "A1B2C3D4" in page2_text
    assert "2.200-2/2001" in page2_text
    assert "Código Civil" in page2_text or "10.406" in page2_text


def test_certificate_default_instructor_and_city():
    """Verifica os valores padrão institucionais: Tel Santana Leite e Goiânia."""
    registro_default = CertificadoRegistro(
        id=99,
        codigo_autenticidade="00112233445566778899AABBCCDDEEFF00112233445566778899AABBCCDDEEFF",
        aluno_nome="Beatriz Souza",
        aluno_cpf="12345678901",
        aluno_cpf_mascarado="***.456.789-**",
        curso_nome="Introdução à Informática Prática",
        carga_horaria=20,
        carga_horaria_extenso="vinte horas",
        data_inicio="01/08/2026",
        data_conclusao="05/08/2026",
        data_emissao="05/08/2026",
        modalidade="Curso Livre de Capacitação Profissional",
        instrutor="",  # Vazio -> usa default Tel Santana Leite
        cidade="",     # Vazio -> usa default Goiânia
        ementa="Módulo 1: Introdução ao Computador.",
        livro_numero=1,
        folha_numero=1,
        registro_numero=1,
        frequencia=90,
    )
    pdf_bytes = generate_certificate_pdf(registro_default)
    reader = pypdf.PdfReader(io.BytesIO(pdf_bytes))
    page1_text = reader.pages[0].extract_text()

    assert "Tel Santana Leite" in page1_text
    assert "Goiânia" in page1_text
    assert "Discente" in page1_text
    assert "Instrutor" in page1_text
    assert "90% de frequência" in page1_text



def test_format_date_pt_extenso():
    """Verifica conversão de datas para extenso formal em português."""
    from core.renderer import format_date_pt_extenso
    from datetime import date

    assert format_date_pt_extenso("28/08/2026") == "28 de agosto de 2026"
    assert format_date_pt_extenso("2026-08-18") == "18 de agosto de 2026"
    assert format_date_pt_extenso("2026/ago/08") == "8 de agosto de 2026"
    assert format_date_pt_extenso("18 de dezembro de 2025") == "18 de dezembro de 2025"
    assert format_date_pt_extenso(date(2026, 1, 15)) == "15 de janeiro de 2026"
    assert format_date_pt_extenso("") == ""
    assert format_date_pt_extenso(None) == ""



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


def test_certificate_dynamic_wrapping_and_right_aligned_date():
    """Verifica comportamento com textos longos (quebra dinâmica de linha) e alinhamento de data."""
    reg_long = CertificadoRegistro(
        id=3,
        codigo_autenticidade="C3D4E5F60718293A4B5C6D7E8F901234567890ABCDEF1234567890ABCDEF0123",
        aluno_nome="Danilo Alves de Oliveira da Costa e Silva de Albuquerque",
        aluno_cpf="12345678901",
        aluno_cpf_mascarado="***.456.789-**",
        curso_nome="Capacitação Prática em Inteligência Artificial Generativa, Engenharia de Prompts e Automação Avançada de Processos",
        carga_horaria=120,
        carga_horaria_extenso="cento e vinte horas de atividades práticas e mentorias individuais",
        data_inicio="08/08/2026",
        data_conclusao="18/11/2026",
        data_emissao="18/11/2026",
        modalidade="Curso Livre de Capacitação Profissional",
        instrutor="Tel Santana Leite",
        cidade="Goiânia",
        ementa="Ementa extensa.",
        livro_numero=1,
        folha_numero=5,
        registro_numero=5,
        frequencia=100,
    )

    pdf_bytes = generate_certificate_pdf(reg_long)
    reader = pypdf.PdfReader(io.BytesIO(pdf_bytes))
    assert len(reader.pages) == 2

    p1_text = reader.pages[0].extract_text()
    assert "DANILO ALVES DE OLIVEIRA" in p1_text
    assert "Inteligência Artificial Generativa" in p1_text
    assert "Goiânia, 18 de novembro de 2026." in p1_text
    assert "Tel Santana Leite" in p1_text
    assert "Instrutor" in p1_text
    assert "Discente" in p1_text


def test_certificate_rendering_without_cpf():
    """Verifica que aluno sem CPF não imprime 'não informado' e exibe 'concluiu o treinamento prático de'."""
    registro_sem_cpf = CertificadoRegistro(
        id=101,
        codigo_autenticidade="A" * 64,
        aluno_nome="Carlos Eduardo Pereira",
        aluno_cpf="",
        aluno_cpf_mascarado="-",
        curso_nome="Oficina de Fotografia Digital",
        carga_horaria=16,
        carga_horaria_extenso="dezesseis horas",
        data_inicio="01/09/2026",
        data_conclusao="05/09/2026",
        data_emissao="05/09/2026",
        modalidade="Curso Livre de Capacitação Profissional",
        instrutor="Prof. Instrutor",
        cidade="Goiânia",
        ementa="Fotografia básica.",
        livro_numero=1,
        folha_numero=1,
        registro_numero=1,
        frequencia=100,
    )
    pdf_bytes = generate_certificate_pdf(registro_sem_cpf)
    reader = pypdf.PdfReader(io.BytesIO(pdf_bytes))
    page1_text = reader.pages[0].extract_text()

    assert "CARLOS EDUARDO PEREIRA" in page1_text
    assert "concluiu o treinamento prático de" in page1_text
    assert "não informado" not in page1_text.lower()
    assert "inscrito(a) no cpf" not in page1_text.lower()


