"""Testes unitários para o módulo de curvas paramétricas e guilloché vetorial contemporâneo."""

import io
import pytest
from reportlab.pdfgen import canvas
from reportlab.lib.pagesizes import landscape, A4
from reportlab.lib import colors

from core.guilloche import (
    draw_guilloche_frame,
    draw_modern_wave_ribbon,
    draw_security_seal,
    resolve_color,
)


def test_resolve_color_from_hex_and_color():
    """Valida a resolução correta de cores hexadecimais e objetos Color do ReportLab."""
    c1 = resolve_color("#0E7490")
    assert isinstance(c1, colors.Color)
    assert pytest.approx(c1.red, abs=0.01) == 0.055
    assert pytest.approx(c1.green, abs=0.01) == 0.455
    assert pytest.approx(c1.blue, abs=0.01) == 0.565

    c2 = resolve_color(colors.red)
    assert c2 == colors.red


def test_draw_modern_wave_ribbon_emits_valid_paths():
    """Verifica que a fita de ondas harmônicas desenha caminhos vetoriais sem exceções."""
    buf = io.BytesIO()
    c = canvas.Canvas(buf, pagesize=landscape(A4))
    
    # Desenho vertical e horizontal
    draw_modern_wave_ribbon(
        c,
        x_start=30,
        y_start=30,
        x_end=30,
        y_end=565,
        num_waves=6,
        amplitude=12.0,
        primary_color="#0E7490",
        secondary_color="#EA580C",
        orientation="vertical"
    )

    draw_modern_wave_ribbon(
        c,
        x_start=30,
        y_start=550,
        x_end=812,
        y_end=550,
        num_waves=4,
        amplitude=8.0,
        primary_color="#0E7490",
        secondary_color="#EA580C",
        orientation="horizontal"
    )

    c.showPage()
    c.save()
    pdf_bytes = buf.getvalue()
    assert len(pdf_bytes) > 500
    assert pdf_bytes.startswith(b"%PDF-")


def test_draw_security_seal_emits_parametric_rosette():
    """Valida a geração do selo numismático contemporâneo baseado em curvas paramétricas."""
    buf = io.BytesIO()
    c = canvas.Canvas(buf, pagesize=landscape(A4))

    draw_security_seal(
        c,
        cx=740,
        cy=100,
        radius=28.0,
        primary_color="#0E7490",
        secondary_color="#EA580C"
    )

    c.showPage()
    c.save()
    pdf_bytes = buf.getvalue()
    assert len(pdf_bytes) > 500
    assert pdf_bytes.startswith(b"%PDF-")


def test_draw_guilloche_frame_renders_complete_frame():
    """Valida que a moldura guilloché perimétrica compõe o anverso sem erros."""
    buf = io.BytesIO()
    c = canvas.Canvas(buf, pagesize=landscape(A4))

    draw_guilloche_frame(
        c,
        width=841.89,
        height=595.27,
        margin=24.0,
        primary_color="#0E7490",
        secondary_color="#EA580C"
    )

    c.showPage()
    c.save()
    pdf_bytes = buf.getvalue()
    assert len(pdf_bytes) > 1000
    assert pdf_bytes.startswith(b"%PDF-")
