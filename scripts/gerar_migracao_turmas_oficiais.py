"""Gera a carga SQL transacional de turmas e encontros a partir da planilha oficial."""

from __future__ import annotations

import argparse
import collections
import datetime as dt
import hashlib
import re
import unicodedata
from pathlib import Path

import openpyxl


def sql(value: object | None) -> str:
    if value is None or value == "":
        return "NULL"
    return "'" + str(value).replace("'", "''") + "'"


def minutes(value: dt.time) -> int:
    return value.hour * 60 + value.minute


def workload_hours(row: tuple[object, ...]) -> int:
    start, end, interval = row[6], row[7], row[8]
    return int((minutes(end) - minutes(start) - int(interval.total_seconds() / 60)) / 60)


def shift(row: tuple[object, ...]) -> str:
    start, end = minutes(row[6]), minutes(row[7])
    if end - start >= 360 or (start < 720 and end > 840):
        return "D"
    if start >= 1080:
        return "N"
    if start >= 720:
        return "V"
    return "M"


def modality(rows: list[tuple[object, ...]]) -> str:
    values = {str(row[11]).strip().lower() for row in rows}
    if len(values) > 1 or "híbrido" in values or "hibrido" in values:
        return "Híbrido"
    return "Remoto / Ao Vivo" if "remotas" in values else "Presencial"


def event_date(row: tuple[object, ...]) -> str:
    return row[5].date().isoformat()


def cohort_token(value: str) -> str:
    """Mantém a identificação de turma legível dentro do código de importação."""
    normalized = unicodedata.normalize("NFKD", value).encode("ascii", "ignore").decode("ascii")
    token = re.sub(r"[^A-Za-z0-9]+", "", normalized).upper()
    return token[:12] or "SEMCOORTE"


def generate(source: Path, output: Path, as_of: dt.date) -> None:
    workbook = openpyxl.load_workbook(source, data_only=True)
    rows = list(workbook.active.iter_rows(min_row=2, values_only=True))
    groups: collections.OrderedDict[tuple[str, str, str, str, str], list[tuple[object, ...]]] = collections.OrderedDict()
    for row in rows:
        key = (
            str(row[0]).strip() if row[0] else "",
            str(row[1]).strip(),
            str(row[2]).strip(),
            str(row[3]).strip(),
            str(row[4]).strip(),
        )
        groups.setdefault(key, []).append(row)

    statements = [
        "ALTER TABLE `encontros` ADD COLUMN IF NOT EXISTS `intervalo_minutos` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Intervalo não pedagógico descontado da carga horária do encontro' AFTER `horario_fim`;\n",
        "START TRANSACTION;\n",
        "DELETE FROM `turmas`;\n",
    ]
    columns = [
        "codigo_turma", "curso_nome", "cliente_nome", "ordem_servico", "numero_os_contrato",
        "tipo_cobranca", "valor_hora_aula", "valor_unitario", "valor_total", "carga_horaria",
        "data_inicio", "data_conclusao", "turno_padrao", "status", "chave_acesso", "modalidade",
        "instrutor", "cidade",
    ]
    for number, (key, events) in enumerate(groups.items(), start=1):
        order, client, course, cohort, city = key
        start, end = min(map(event_date, events)), max(map(event_date, events))
        total_hours = sum(workload_hours(row) for row in events)
        standard_shift = collections.Counter(shift(row) for row in events).most_common(1)[0][0]
        status = "concluida" if dt.date.fromisoformat(end) < as_of else "prevista"
        digest = hashlib.sha256("|".join((*key, start)).encode("utf-8")).hexdigest()[:8]
        code = f"OFI-{start.replace('-', '')}-T{cohort_token(cohort)}-{digest}"
        access_key = f"oficial-{start.replace('-', '')}-{digest}"
        hourly_rate = events[0][10] or 0
        values = [
            code, course, client, order or None, order or None, "hora_aula", hourly_rate, hourly_rate,
            float(hourly_rate) * total_hours, total_hours, start, end, standard_shift, status, access_key,
            modality(events), "Tel Santana Leite", city,
        ]
        statements.append(
            "INSERT INTO `turmas` (" + ", ".join(f"`{column}`" for column in columns) + ") VALUES ("
            + ", ".join(sql(value) for value in values) + ");\n"
        )
        statements.append(f"SET @turma_{number} = LAST_INSERT_ID();\n")
        for sequence, row in enumerate(sorted(events, key=lambda item: (event_date(item), minutes(item[6]))), start=1):
            event_values = [
                f"@turma_{number}", sequence, event_date(row), shift(row), row[6].strftime("%H:%M:%S"),
                row[7].strftime("%H:%M:%S"), int(row[8].total_seconds() / 60),
            ]
            rendered = [value if isinstance(value, int) or str(value).startswith("@") else sql(value) for value in event_values]
            statements.append(
                "INSERT INTO `encontros` (`turma_id`, `numero_encontro`, `data_encontro`, `turno`, `horario_inicio`, `horario_fim`, `intervalo_minutos`) VALUES ("
                + ", ".join(map(str, rendered)) + ");\n"
            )
    statements.extend([
        "COMMIT;\n",
        "-- Carga oficial gerada de turmas-oficiais.xlsx.\n",
        "-- Remove turmas e dependências. Registros de certificados permanecem com turma_id nulo.\n",
        "-- Controles esperados: 17 turmas, 77 encontros e 365 horas líquidas.\n",
    ])
    output.write_text("".join(statements), encoding="utf-8", newline="\n")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("source", type=Path)
    parser.add_argument("output", type=Path)
    parser.add_argument("--as-of", type=dt.date.fromisoformat, default=dt.date.today())
    args = parser.parse_args()
    generate(args.source, args.output, args.as_of)
