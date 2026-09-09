"""Geração e validação da planilha modelo oficial de alunos."""

import io
from dataclasses import dataclass, field
from pathlib import Path
from typing import List, Union

import openpyxl
from openpyxl.styles import Alignment, Font, PatternFill
import pandas as pd

from core.validator import StudentValidation, validate_student


@dataclass
class SpreadsheetValidationResult:
    """Resultado da auditoria e validação de uma planilha de alunos."""

    total_rows: int
    valid_count: int
    invalid_count: int
    is_valid: bool
    students: List[StudentValidation] = field(default_factory=list)
    global_errors: List[str] = field(default_factory=list)


def generate_template_spreadsheet(
    destination: Union[str, Path, io.BytesIO] = "modelo_alunos.xlsx"
) -> Union[Path, io.BytesIO]:
    """
    Gera a planilha modelo oficial 'modelo_alunos.xlsx' contendo estritamente
    as colunas 'Nome' e 'CPF' com formatação visual profissional e dados de exemplo.
    """
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "Alunos"

    # Estilos visuais
    header_font = Font(name="Calibri", size=11, bold=True, color="FFFFFF")
    header_fill = PatternFill(start_color="1E3A8A", end_color="1E3A8A", fill_type="solid")  # Azul Marinho
    header_align = Alignment(horizontal="center", vertical="center")

    data_font = Font(name="Calibri", size=11)
    data_align_left = Alignment(horizontal="left", vertical="center")
    data_align_center = Alignment(horizontal="center", vertical="center")

    # Cabeçalho oficial estrito
    headers = ["Nome", "CPF"]
    ws.append(headers)

    ws.row_dimensions[1].height = 25
    for col_idx in range(1, 3):
        cell = ws.cell(row=1, column=col_idx)
        cell.font = header_font
        cell.fill = header_fill
        cell.alignment = header_align

    # Exemplos instrucionais válidos
    sample_rows = [
        ("Maria da Silva", "529.982.247-25"),
        ("João dos Santos", "111.444.777-35"),
        ("Ana Paula do Nascimento", "012.345.678-90"),
    ]

    for row_idx, row_data in enumerate(sample_rows, start=2):
        ws.append(list(row_data))
        ws.row_dimensions[row_idx].height = 20
        c1 = ws.cell(row=row_idx, column=1)
        c1.font = data_font
        c1.alignment = data_align_left

        c2 = ws.cell(row=row_idx, column=2)
        c2.font = data_font
        c2.alignment = data_align_center

    # Ajuste de largura das colunas
    ws.column_dimensions["A"].width = 35
    ws.column_dimensions["B"].width = 22

    # Salvar para caminho ou buffer
    if isinstance(destination, io.IOBase):
        wb.save(destination)
        return destination
    else:
        path = Path(destination)
        path.parent.mkdir(parents=True, exist_ok=True)
        wb.save(str(path))
        return path


def read_and_validate_spreadsheet(
    source: Union[str, Path, io.BytesIO, bytes, pd.DataFrame]
) -> SpreadsheetValidationResult:
    """
    Lê e audita uma planilha (Excel ou CSV), verificando a presença estrita das
    colunas 'Nome' e 'CPF' e validando individualmente cada linha de aluno.
    """
    global_errors: List[str] = []

    # Carregar em DataFrame
    df: pd.DataFrame
    try:
        if isinstance(source, pd.DataFrame):
            df = source.copy()
        elif isinstance(source, bytes):
            buffer = io.BytesIO(source)
            try:
                df = pd.read_excel(buffer)
            except Exception:
                buffer.seek(0)
                df = pd.read_csv(buffer)
        elif isinstance(source, (str, Path)):
            path_str = str(source).lower()
            if path_str.endswith(".csv"):
                df = pd.read_csv(source)
            else:
                df = pd.read_excel(source)
        elif isinstance(source, io.IOBase):
            try:
                df = pd.read_excel(source)
            except Exception:
                if hasattr(source, "seek"):
                    source.seek(0)
                df = pd.read_csv(source)
        else:
            return SpreadsheetValidationResult(
                total_rows=0,
                valid_count=0,
                invalid_count=0,
                is_valid=False,
                global_errors=["Fonte de dados inválida ou formato não reconhecido."],
            )
    except Exception as e:
        return SpreadsheetValidationResult(
            total_rows=0,
            valid_count=0,
            invalid_count=0,
            is_valid=False,
            global_errors=[f"Erro ao ler arquivo: {str(e)}"],
        )

    # Normalizar nomes de colunas
    col_map = {}
    for col in df.columns:
        clean_col = str(col).strip().lower()
        if clean_col == "nome":
            col_map[col] = "Nome"
        elif clean_col == "cpf":
            col_map[col] = "CPF"

    if "Nome" not in col_map.values() or "CPF" not in col_map.values():
        missing = []
        if "Nome" not in col_map.values():
            missing.append("'Nome'")
        if "CPF" not in col_map.values():
            missing.append("'CPF'")
        global_errors.append(
            f"Colunas obrigatórias ausentes: {', '.join(missing)}. A planilha deve conter estritamente 'Nome' e 'CPF'."
        )
        return SpreadsheetValidationResult(
            total_rows=len(df),
            valid_count=0,
            invalid_count=len(df),
            is_valid=False,
            global_errors=global_errors,
        )

    # Localizar os nomes reais das colunas
    name_col = [orig for orig, mapped in col_map.items() if mapped == "Nome"][0]
    cpf_col = [orig for orig, mapped in col_map.items() if mapped == "CPF"][0]

    students: List[StudentValidation] = []
    for _, row in df.iterrows():
        raw_name = str(row[name_col]) if pd.notna(row[name_col]) else ""
        raw_cpf = str(row[cpf_col]) if pd.notna(row[cpf_col]) else ""

        # Tratar casos de inteiros lidos pelo pandas (ex: CPF lido como int sem zero à esquerda)
        if isinstance(row[cpf_col], (int, float)) and pd.notna(row[cpf_col]):
            raw_cpf = str(int(row[cpf_col])).zfill(11)

        student = validate_student(raw_name, raw_cpf)
        students.append(student)

    total_rows = len(students)
    valid_count = sum(1 for s in students if s.is_valid)
    invalid_count = total_rows - valid_count
    is_valid = (invalid_count == 0) and (total_rows > 0)

    if total_rows == 0:
        global_errors.append("A planilha está vazia.")

    return SpreadsheetValidationResult(
        total_rows=total_rows,
        valid_count=valid_count,
        invalid_count=invalid_count,
        is_valid=is_valid,
        students=students,
        global_errors=global_errors,
    )
