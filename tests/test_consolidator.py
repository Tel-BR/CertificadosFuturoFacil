"""Testes unitários para o módulo consolidador duplex para gráfica rápida (Seam 3)."""

import io
from pathlib import Path
import pytest
import pypdf

from core.registry import CertificadoRegistro
from core.renderer import CertificateRenderConfig, generate_certificate_pdf
from core.consolidator import consolidate_duplex_pdf, merge_duplex_files


@pytest.fixture
def sample_batch():
    """Lote de 3 alunos para teste de intercalação duplex."""
    regs = []
    for i in range(1, 4):
        regs.append(
            CertificadoRegistro(
                id=i,
                codigo_autenticidade=f"HASH{i:02d}ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF12345678",
                aluno_nome=f"Aluno Teste {i}",
                aluno_cpf=f"{i:011d}",
                aluno_cpf_mascarado=f"***.{i:03d}.{i:03d}-**",
                curso_nome="Excel Corporativo & Automação",
                carga_horaria=40,
                carga_horaria_extenso="quarenta horas",
                data_inicio="10/08/2026",
                data_conclusao="28/08/2026",
                data_emissao="28/08/2026",
                modalidade="Curso Livre de Capacitação Profissional",
                instrutor="Prof. Carlos Eduardo Silveira",
                cidade="São Paulo - SP",
                ementa=f"Módulo 1: Introdução Aluno {i}; Módulo 2: Avançado.",
                livro_numero=1,
                folha_numero=10 + i,
                registro_numero=10 + i,
            )
        )
    return regs


def test_consolidate_duplex_pdf_exact_page_count_and_ordering(sample_batch):
    """
    Verifica que para N alunos, o PDF consolidado possui exatamente 2*N páginas
    e que a intercalação segue estritamente Frente 1, Verso 1, Frente 2, Verso 2...
    """
    pdf_bytes = consolidate_duplex_pdf(sample_batch)
    reader = pypdf.PdfReader(io.BytesIO(pdf_bytes))

    # 3 alunos -> 6 páginas
    assert len(reader.pages) == 6

    for i, reg in enumerate(sample_batch):
        frente_idx = 2 * i
        verso_idx = 2 * i + 1

        frente_text = reader.pages[frente_idx].extract_text()
        verso_text = reader.pages[verso_idx].extract_text()

        # Página Ímpar (1, 3, 5) -> Frente do Aluno i
        assert "CERTIFICADO" in frente_text
        assert reg.aluno_nome.upper() in frente_text

        # Página Par (2, 4, 6) -> Verso do Aluno i
        assert f"Módulo 1: Introdução Aluno {reg.id}" in verso_text
        assert str(reg.registro_numero) in verso_text


def test_merge_duplex_files_from_disk(tmp_path, sample_batch):
    """Verifica que a fusão a partir de PDFs individuais em disco produz o mesmo ordenamento duplex."""
    pdf_files = []
    for reg in sample_batch:
        file_path = tmp_path / f"indiv_{reg.id}.pdf"
        generate_certificate_pdf(reg, output_path_or_buffer=file_path)
        pdf_files.append(file_path)

    merged_out = tmp_path / "consolidado_grafica.pdf"
    res_path = merge_duplex_files(pdf_files, output_path=merged_out)

    assert res_path.exists()
    reader = pypdf.PdfReader(str(res_path))
    assert len(reader.pages) == 6

    # Verifica primeira e última página
    assert "Aluno Teste 1".upper() in reader.pages[0].extract_text()
    assert "Módulo 1: Introdução Aluno 3" in reader.pages[5].extract_text()
