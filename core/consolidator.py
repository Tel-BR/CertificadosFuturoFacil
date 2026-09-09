"""Consolidador de certificados em PDF duplex para gráfica rápida.

Gera um único documento unificado contendo todos os certificados do lote com
intercalação duplex sequencial ordenada:
Página 1: Frente Aluno 1
Página 2: Verso Aluno 1
Página 3: Frente Aluno 2
Página 4: Verso Aluno 2
...
Total de páginas = 2 * N.
"""

import io
from pathlib import Path
from typing import Optional, Sequence, Union

import pypdf
from reportlab.lib.pagesizes import A4, landscape
from reportlab.pdfgen import canvas

from core.registry import CertificadoRegistro
from core.renderer import (
    CertificateRenderConfig,
    render_anverso,
    render_reverso,
)


def consolidate_duplex_pdf(
    registros: Sequence[CertificadoRegistro],
    output_path_or_buffer: Optional[Union[str, Path, io.BytesIO]] = None,
    config: Optional[CertificateRenderConfig] = None,
) -> bytes:
    """
    Gera um único arquivo PDF consolidado com todos os alunos do lote,
    intercalando estritamente:
    Página ímpar: Frente do Aluno
    Página par: Verso do Aluno correspondente.
    """
    if config is None:
        config = CertificateRenderConfig()

    buf = io.BytesIO()
    c = canvas.Canvas(buf, pagesize=landscape(A4))

    for reg in registros:
        # Página Ímpar: Frente (Anverso)
        render_anverso(c, reg, config)
        c.showPage()

        # Página Par: Verso (Reverso)
        render_reverso(c, reg, config)
        c.showPage()

    c.save()
    pdf_bytes = buf.getvalue()

    if output_path_or_buffer is not None:
        if isinstance(output_path_or_buffer, io.BytesIO):
            output_path_or_buffer.write(pdf_bytes)
        else:
            p = Path(output_path_or_buffer)
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_bytes(pdf_bytes)

    return pdf_bytes


def merge_duplex_files(
    individual_pdf_paths: Sequence[Union[str, Path]],
    output_path: Union[str, Path],
) -> Path:
    """
    Funde uma sequência ordenada de PDFs individuais de 2 páginas em um único
    documento duplex consolidado pronto para gráfica, utilizando pypdf.
    """
    writer = pypdf.PdfWriter()

    for p in individual_pdf_paths:
        path_obj = Path(p)
        if not path_obj.exists():
            raise FileNotFoundError(f"Arquivo de certificado não encontrado: {path_obj}")
        reader = pypdf.PdfReader(str(path_obj))
        for page in reader.pages:
            writer.add_page(page)

    target_path = Path(output_path)
    target_path.parent.mkdir(parents=True, exist_ok=True)
    with open(target_path, "wb") as f:
        writer.write(f)

    return target_path
