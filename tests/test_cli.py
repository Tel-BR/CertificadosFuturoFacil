"""Testes para o utilitário de linha de comando cli.py."""

import openpyxl
from pathlib import Path
import pytest
import subprocess
import sys
import zipfile


@pytest.fixture
def sample_xlsx(tmp_path):
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "Alunos"
    ws.append(["Nome", "CPF"])
    ws.append(["Carlos Drummond", "529.982.247-25"])
    ws.append(["Clarice Lispector", "111.444.777-35"])
    xlsx_path = tmp_path / "turma.xlsx"
    wb.save(xlsx_path)
    return xlsx_path


@pytest.fixture
def invalid_xlsx(tmp_path):
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "Alunos"
    ws.append(["Nome", "CPF"])
    ws.append(["Aluno Sem Sobrenome", "111.111.111-11"])  # CPF inválido
    xlsx_path = tmp_path / "turma_invalida.xlsx"
    wb.save(xlsx_path)
    return xlsx_path


def test_cli_help():
    result = subprocess.run(
        [sys.executable, "cli.py", "--help"],
        capture_output=True,
        text=True,
    )
    assert result.returncode == 0
    assert "Emissor de Certificados Futuro Fácil" in result.stdout or "help" in result.stdout.lower()


def test_cli_emissao_lote_sucesso(sample_xlsx, tmp_path):
    zip_out = tmp_path / "saida_cli.zip"
    db_out = tmp_path / "cli_registros.db"

    cmd = [
        sys.executable,
        "cli.py",
        "--planilha",
        str(sample_xlsx),
        "--curso",
        "Python para Inteligência Artificial",
        "--carga-horaria",
        "60",
        "--data-conclusao",
        "15/09/2026",
        "--data-inicio",
        "01/09/2026",
        "--saida-zip",
        str(zip_out),
        "--db-url",
        str(db_out),
    ]

    result = subprocess.run(cmd, capture_output=True, text=True)
    assert result.returncode == 0
    assert "emitidos com sucesso" in result.stdout.lower()
    assert zip_out.exists()

    with zipfile.ZipFile(zip_out, "r") as zf:
        namelist = zf.namelist()
        assert "certificado_consolidado_grafica.pdf" in namelist
        assert "livro_registro_certificados.xlsx" in namelist
        assert len([f for f in namelist if f.startswith("certificados_individuais/")]) == 2


def test_cli_rejeita_planilha_invalida(invalid_xlsx, tmp_path):
    zip_out = tmp_path / "saida_invalida.zip"
    cmd = [
        sys.executable,
        "cli.py",
        "--planilha",
        str(invalid_xlsx),
        "--curso",
        "Curso Teste",
        "--carga-horaria",
        "20",
        "--data-conclusao",
        "10/09/2026",
        "--saida-zip",
        str(zip_out),
    ]

    result = subprocess.run(cmd, capture_output=True, text=True)
    assert result.returncode == 1
    assert not zip_out.exists()
    assert "erro" in (result.stderr + result.stdout).lower()
