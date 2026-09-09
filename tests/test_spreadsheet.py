import io
from pathlib import Path
import openpyxl
import pandas as pd
import pytest

from core.spreadsheet import (
    generate_template_spreadsheet,
    read_and_validate_spreadsheet,
    SpreadsheetValidationResult,
)


def test_generate_template_to_path(tmp_path: Path):
    target_file = tmp_path / "modelo_alunos.xlsx"
    res_path = generate_template_spreadsheet(target_file)
    assert Path(res_path).exists()

    wb = openpyxl.load_workbook(target_file)
    sheet = wb.active
    assert sheet is not None

    headers = [cell.value for cell in sheet[1]]
    assert headers[:2] == ["Nome", "CPF"]

    # Modelo deve conter estritamente o cabeçalho (sem dados de teste residuais)
    assert sheet.max_row == 1
    wb.close()


def test_generate_template_to_bytes():
    buffer = io.BytesIO()
    generate_template_spreadsheet(buffer)
    buffer.seek(0)

    wb = openpyxl.load_workbook(buffer)
    sheet = wb.active
    assert sheet is not None
    headers = [cell.value for cell in sheet[1]]
    assert headers[:2] == ["Nome", "CPF"]
    assert sheet.max_row == 1
    wb.close()


def test_read_and_validate_valid_spreadsheet(tmp_path: Path):
    test_file = tmp_path / "alunos_validos.xlsx"
    df = pd.DataFrame(
        {
            "Nome": ["MARIA DA SILVA", "joão dos santos"],
            "CPF": ["529.982.247-25", "111.444.777-35"],
        }
    )
    df.to_excel(test_file, index=False)

    result = read_and_validate_spreadsheet(test_file)
    assert isinstance(result, SpreadsheetValidationResult)
    assert result.is_valid is True
    assert result.total_rows == 2
    assert result.valid_count == 2
    assert result.invalid_count == 0
    assert len(result.alunos) == 2
    assert result.alunos[0].nome == "Maria da Silva"
    assert result.alunos[0].cpf_formatado == "529.982.247-25"
    assert result.alunos[1].nome == "João dos Santos"


def test_read_and_validate_with_invalid_rows(tmp_path: Path):
    test_file = tmp_path / "alunos_misto.xlsx"
    df = pd.DataFrame(
        {
            "Nome": ["Carlos Silva", "   ", "Ana Clara"],
            "CPF": ["529.982.247-25", "11144477735", "123.456.789-00"],
        }
    )
    df.to_excel(test_file, index=False)

    result = read_and_validate_spreadsheet(test_file)
    assert result.is_valid is False
    assert result.total_rows == 3
    assert result.valid_count == 1
    assert result.invalid_count == 2
    assert result.alunos[0].is_valido is True
    assert result.alunos[1].is_valido is False  # Nome vazio
    assert result.alunos[2].is_valido is False  # CPF inválido


def test_read_and_validate_missing_columns(tmp_path: Path):
    test_file = tmp_path / "colunas_erradas.xlsx"
    df = pd.DataFrame(
        {
            "Participante": ["Carlos Silva"],
            "Documento": ["529.982.247-25"],
        }
    )
    df.to_excel(test_file, index=False)

    result = read_and_validate_spreadsheet(test_file)
    assert result.is_valid is False
    assert len(result.global_errors) > 0
    assert any("Nome" in err or "CPF" in err for err in result.global_errors)


def test_read_and_validate_rejects_extra_columns(tmp_path: Path):
    # Requisito: estritamente duas colunas (Nome e CPF)
    test_file = tmp_path / "colunas_extras.xlsx"
    df = pd.DataFrame(
        {
            "Nome": ["Carlos Silva"],
            "CPF": ["529.982.247-25"],
            "Email": ["carlos@exemplo.com"],
        }
    )
    df.to_excel(test_file, index=False)

    result = read_and_validate_spreadsheet(test_file)
    assert result.is_valid is False
    assert any("não permitidas" in err for err in result.global_errors)


def test_read_and_validate_csv_with_semicolon(tmp_path: Path):
    test_file = tmp_path / "alunos_semicolon.csv"
    test_file.write_text("Nome;CPF\nLucas Souza;529.982.247-25\n", encoding="utf-8")

    result = read_and_validate_spreadsheet(test_file)
    assert result.is_valid is True
    assert result.total_rows == 1
    assert result.alunos[0].nome == "Lucas Souza"


def test_read_and_validate_numeric_cpf_handling(tmp_path: Path):
    test_file = tmp_path / "alunos_numericos.xlsx"
    df = pd.DataFrame(
        {
            "Nome": ["Aluno Zero", "Aluno Curto"],
            # 1234567890 tem 10 dígitos (zero inicial foi comido pelo Excel -> 01234567890 válido)
            # 123 é curto demais e não deve sofrer padding artificial
            "CPF": [1234567890, 123],
        }
    )
    df.to_excel(test_file, index=False)

    result = read_and_validate_spreadsheet(test_file)
    assert result.alunos[0].cpf == "01234567890"
    assert result.alunos[0].is_valido is True
    assert result.alunos[1].cpf == "123"
    assert result.alunos[1].is_valido is False
