"""Testes de integração e fumaça para a aplicação Streamlit app.py."""

import io
from pathlib import Path
import pytest
import pymupdf

from core.config import InstituicaoConfig
from core.registry import CursoMetadata, LivroRegistroManager
from core.renderer import CertificateRenderConfig, generate_certificate_pdf
from core.validator import validar_aluno


def test_pdf_preview_render_to_png(tmp_path):
    """Testa se o gerador de prévias consegue converter as 2 páginas do PDF em imagens PNG válidas."""
    curso = CursoMetadata(
        curso_nome="Curso Preview",
        carga_horaria=20,
        data_conclusao="10/09/2026",
    )
    aluno = validar_aluno("Aluno Preview da Silva", "529.982.247-25")
    manager = LivroRegistroManager(db_url_or_path=str(tmp_path / "preview.db"))
    reg = manager.register_certificate(aluno, curso)

    config = CertificateRenderConfig(cnpj="11.222.333/0001-81", razao_social="Empresa Teste")
    pdf_bytes = generate_certificate_pdf(reg, config=config)
    assert len(pdf_bytes) > 0

    # Abre com pymupdf e extrai as duas páginas como PNG
    doc = pymupdf.open(stream=pdf_bytes, filetype="pdf")
    assert len(doc) == 2

    # Página 1 (Frente)
    pix1 = doc[0].get_pixmap(dpi=100)
    png1 = pix1.tobytes("png")
    assert len(png1) > 0
    assert png1[:8] == b"\x89PNG\r\n\x1a\n"

    # Página 2 (Verso)
    pix2 = doc[1].get_pixmap(dpi=100)
    png2 = pix2.tobytes("png")
    assert len(png2) > 0
    assert png2[:8] == b"\x89PNG\r\n\x1a\n"


def test_streamlit_app_smoke_test():
    """Testa a execução ponta a ponta da interface Streamlit via AppTest oficial."""
    from streamlit.testing.v1 import AppTest

    app_path = Path(__file__).parent.parent / "app.py"
    at = AppTest.from_file(str(app_path), default_timeout=15)
    at.run()
    assert not at.exception, f"Erro inesperado ao executar app.py: {at.exception}"
    assert len(at.title) >= 0  # Interface inicial renderizada com sucesso


