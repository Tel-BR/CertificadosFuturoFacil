"""Testes do serviço de emissão em lote e empacotamento ZIP (core/batch_service.py)."""

import io
from pathlib import Path
import zipfile
import pytest
import pypdf

from core.batch_service import (
    BatchEmissionResult,
    emitir_lote_certificados,
)
from core.registry import CursoMetadata, LivroRegistroManager
from core.renderer import CertificateRenderConfig
from core.validator import validar_aluno


@pytest.fixture
def temp_manager(tmp_path):
    db_path = tmp_path / "test_batch.db"
    excel_path = tmp_path / "livro_test.xlsx"
    return LivroRegistroManager(
        db_url_or_path=str(db_path),
        excel_export_path=excel_path,
        initial_livro=1,
        initial_folha=1,
        initial_registro=1,
    )


@pytest.fixture
def sample_curso():
    return CursoMetadata(
        curso_nome="Python para Automação",
        carga_horaria=40,
        data_conclusao="10/09/2026",
        data_inicio="01/09/2026",
        data_emissao="10/09/2026",
        instrutor="Tel Santana Leite",
        cidade="Goiânia",
        ementa="Módulo 1: Fundamentos\nMódulo 2: Automação Web",
    )


def test_emitir_lote_certificados_completo(temp_manager, sample_curso, tmp_path):
    alunos = [
        validar_aluno("João da Silva", "529.982.247-25"),
        validar_aluno("Maria Oliveira Santos", "111.444.777-35"),
    ]

    zip_file = tmp_path / "pacote_teste.zip"
    progress_logs = []

    def callback(current, total, msg):
        progress_logs.append((current, total, msg))

    result = emitir_lote_certificados(
        alunos=alunos,
        curso=sample_curso,
        manager=temp_manager,
        zip_output_path_or_buffer=zip_file,
        progress_callback=callback,
    )

    assert isinstance(result, BatchEmissionResult)
    assert result.total_emitidos == 2
    assert len(result.registros) == 2
    assert zip_file.exists()
    assert len(progress_logs) > 0

    # Verifica estrutura interna do arquivo ZIP
    with zipfile.ZipFile(zip_file, "r") as zf:
        namelist = zf.namelist()
        assert "certificado_consolidado_grafica.pdf" in namelist
        assert "livro_registro_certificados.xlsx" in namelist
        # Verifica se os PDFs individuais estão na pasta certificados_individuais/
        individuais = [f for f in namelist if f.startswith("certificados_individuais/")]
        assert len(individuais) == 2

        # Verifica integridade do PDF consolidado duplex (2 alunos x 2 páginas = 4 páginas)
        consolidado_bytes = zf.read("certificado_consolidado_grafica.pdf")
        reader = pypdf.PdfReader(io.BytesIO(consolidado_bytes))
        assert len(reader.pages) == 4


def test_emitir_lote_certificados_em_memoria(temp_manager, sample_curso):
    alunos = [
        validar_aluno("Lucas das Dores", "529.982.247-25"),
    ]

    result = emitir_lote_certificados(
        alunos=alunos,
        curso=sample_curso,
        manager=temp_manager,
    )

    assert result.total_emitidos == 1
    assert isinstance(result.zip_bytes, bytes)
    assert len(result.zip_bytes) > 0

    # Lê o ZIP gerado diretamente da memória
    with zipfile.ZipFile(io.BytesIO(result.zip_bytes), "r") as zf:
        namelist = zf.namelist()
        assert "certificado_consolidado_grafica.pdf" in namelist
        assert "livro_registro_certificados.xlsx" in namelist
        assert any(f.startswith("certificados_individuais/") for f in namelist)


def test_emitir_lote_certificados_sem_alunos(temp_manager, sample_curso):
    with pytest.raises(ValueError, match="Nenhum aluno válido"):
        emitir_lote_certificados([], sample_curso, manager=temp_manager)
