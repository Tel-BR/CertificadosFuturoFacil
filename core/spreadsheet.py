"""Geração e validação da planilha modelo oficial de alunos."""

import io
from dataclasses import dataclass, field
from pathlib import Path
from typing import List, Union

import openpyxl
from openpyxl.styles import Alignment, Font, PatternFill
import pandas as pd

from core.validator import ValidacaoAluno, validar_aluno


@dataclass
class SpreadsheetValidationResult:
    """Resultado da auditoria e validação de uma planilha de alunos."""

    total_rows: int
    valid_count: int
    invalid_count: int
    is_valid: bool
    alunos: List[ValidacaoAluno] = field(default_factory=list)
    global_errors: List[str] = field(default_factory=list)

    @property
    def students(self) -> List[ValidacaoAluno]:
        """Alias para compatibilidade de nomenclatura."""
        return self.alunos


def generate_template_spreadsheet(
    destination: Union[str, Path, io.BytesIO] = "modelo_alunos.xlsx"
) -> Union[Path, io.BytesIO]:
    """
    Gera a planilha modelo oficial 'modelo_alunos.xlsx' contendo estritamente
    as colunas 'Nome' e 'CPF' com formatação visual profissional.
    Não insere linhas de dados de teste para evitar emissões acidentais.
    """
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "Alunos"

    # Estilos visuais para cabeçalho
    header_font = Font(name="Calibri", size=11, bold=True, color="FFFFFF")
    header_fill = PatternFill(start_color="1E3A8A", end_color="1E3A8A", fill_type="solid")  # Azul Marinho
    header_align = Alignment(horizontal="center", vertical="center")

    # Cabeçalho oficial estrito
    headers = ["Nome", "CPF"]
    ws.append(headers)

    ws.row_dimensions[1].height = 25
    for col_idx in range(1, 3):
        cell = ws.cell(row=1, column=col_idx)
        cell.font = header_font
        cell.fill = header_fill
        cell.alignment = header_align

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


def _carregar_dataframe(source: Union[str, Path, io.BytesIO, bytes, pd.DataFrame]) -> pd.DataFrame:
    """Carrega dados tabulares de arquivo Excel ou CSV (com suporte a vírgula ou ponto-e-vírgula)."""
    if isinstance(source, pd.DataFrame):
        return source.copy()

    if isinstance(source, bytes):
        buffer = io.BytesIO(source)
        try:
            return pd.read_excel(buffer)
        except Exception:
            buffer.seek(0)
            return pd.read_csv(buffer, sep=None, engine="python")

    if isinstance(source, (str, Path)):
        path_str = str(source).lower()
        if path_str.endswith(".csv"):
            return pd.read_csv(source, sep=None, engine="python")
        return pd.read_excel(source)

    if isinstance(source, io.IOBase):
        try:
            return pd.read_excel(source)
        except Exception:
            if hasattr(source, "seek"):
                source.seek(0)
            return pd.read_csv(source, sep=None, engine="python")

    raise ValueError("Fonte de dados inválida ou formato não reconhecido.")


def read_and_validate_spreadsheet(
    source: Union[str, Path, io.BytesIO, bytes, pd.DataFrame]
) -> SpreadsheetValidationResult:
    """
    Lê e audita uma planilha (Excel ou CSV), verificando a presença estrita
    e exclusiva das colunas 'Nome' e 'CPF' e validando cada Aluno.
    """
    global_errors: List[str] = []

    try:
        df = _carregar_dataframe(source)
    except Exception as e:
        return SpreadsheetValidationResult(
            total_rows=0,
            valid_count=0,
            invalid_count=0,
            is_valid=False,
            global_errors=[f"Erro ao ler arquivo: {str(e)}"],
        )

    # Verificação estrita de duas colunas
    col_map = {}
    colunas_extras = []
    for col in df.columns:
        clean_col = str(col).strip().lower()
        if clean_col == "nome":
            col_map[col] = "Nome"
        elif clean_col == "cpf":
            col_map[col] = "CPF"
        else:
            colunas_extras.append(str(col))

    # A especificação exige estritamente duas colunas: Nome e CPF
    if colunas_extras:
        global_errors.append(
            f"A planilha contém colunas não permitidas: {', '.join(colunas_extras)}. "
            "A planilha deve conter estritamente as colunas 'Nome' e 'CPF'."
        )

    if "Nome" not in col_map.values() or "CPF" not in col_map.values():
        missing = []
        if "Nome" not in col_map.values():
            missing.append("'Nome'")
        if "CPF" not in col_map.values():
            missing.append("'CPF'")
        global_errors.append(
            f"Colunas obrigatórias ausentes: {', '.join(missing)}. "
            "A planilha deve conter estritamente as colunas 'Nome' e 'CPF'."
        )

    if global_errors:
        return SpreadsheetValidationResult(
            total_rows=len(df),
            valid_count=0,
            invalid_count=len(df),
            is_valid=False,
            global_errors=global_errors,
        )

    # Localizar os nomes reais das colunas mapeadas
    nome_col = [orig for orig, mapped in col_map.items() if mapped == "Nome"][0]
    cpf_col = [orig for orig, mapped in col_map.items() if mapped == "CPF"][0]

    alunos: List[ValidacaoAluno] = []
    for _, row in df.iterrows():
        raw_name = str(row[nome_col]) if pd.notna(row[nome_col]) else ""
        val_cpf = row[cpf_col]

        # Trata inteiros do pandas onde o zero à esquerda foi suprimido pelo Excel (ex: 1234567890 -> 01234567890)
        if isinstance(val_cpf, (int, float)) and pd.notna(val_cpf):
            str_cpf = str(int(val_cpf))
            raw_cpf = str_cpf.zfill(11) if len(str_cpf) == 10 else str_cpf
        elif pd.notna(val_cpf):
            raw_cpf = str(val_cpf)
        else:
            raw_cpf = ""

        aluno = validar_aluno(raw_name, raw_cpf)
        alunos.append(aluno)

    total_rows = len(alunos)
    valid_count = sum(1 for a in alunos if a.is_valido)
    invalid_count = total_rows - valid_count
    is_valid = (invalid_count == 0) and (total_rows > 0)

    if total_rows == 0:
        global_errors.append("A planilha está vazia.")
        is_valid = False

    return SpreadsheetValidationResult(
        total_rows=total_rows,
        valid_count=valid_count,
        invalid_count=invalid_count,
        is_valid=is_valid,
        alunos=alunos,
        global_errors=global_errors,
    )
