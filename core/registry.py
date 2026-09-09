"""Livro de Registro Digital com persistência relacional e exportação Excel.

Gerencia o sequenciamento contínuo e imutável de Livro, Folha e Registro,
aplicando a virada de livro a cada 100 folhas (1 registro por folha),
gerando códigos de autenticidade SHA-256 e exportando a planilha mestre
livro_registro_certificados.xlsx.
"""

from dataclasses import asdict, dataclass, field
from datetime import datetime
import hashlib
import io
import os
from pathlib import Path
import sqlite3
from typing import Any, Dict, List, Optional, Sequence, Tuple, Union
import uuid

import num2words
import openpyxl
from openpyxl.styles import Alignment, Border, Font, PatternFill, Side
from openpyxl.utils import get_column_letter

from core.validator import ValidacaoAluno, format_cpf, mask_cpf, normalize_name, validar_aluno


def formatar_carga_horaria_extenso(horas: int) -> str:
    """
    Converte a carga horária em horas para texto por extenso em português.
    Aplica concordância de gênero no feminino ('uma hora', 'vinte e uma horas').
    """
    if not isinstance(horas, int) or horas <= 0:
        raise ValueError(f"Carga horária deve ser um número inteiro positivo: {horas}")

    if horas == 1:
        return "uma hora"

    ext = num2words.num2words(horas, lang="pt_BR")

    # Ajuste de concordância feminina em português para horas
    if ext == "um":
        return "uma hora"
    elif ext.endswith(" e um"):
        ext = ext[:-5] + " e uma"
    elif ext.endswith(" um"):
        ext = ext[:-3] + " uma"

    return f"{ext} horas"


def generate_authenticity_code(
    aluno_cpf: str,
    curso_nome: str,
    registro_num: int,
    salt: Optional[str] = None,
    aluno_nome: Optional[str] = None,
) -> str:
    """
    Gera o Código de Autenticidade imutável baseado em hash criptográfico SHA-256
    exclusivo para cada certificado. Retorna uma string hexadecimal de 64 caracteres em maiúsculas.
    Se o aluno não possuir CPF, utiliza o nome do aluno como identificador.
    """
    nonce = salt if salt is not None else uuid.uuid4().hex
    clean_cpf = aluno_cpf.replace(".", "").replace("-", "").strip() if aluno_cpf else ""
    identificador = clean_cpf if clean_cpf else (aluno_nome.strip() if aluno_nome else "ALUNO_SEM_CPF")
    payload = f"{identificador}|{curso_nome.strip()}|{registro_num}|{nonce}"
    return hashlib.sha256(payload.encode("utf-8")).hexdigest().upper()


@dataclass
class CursoMetadata:
    """Metadados do curso e da turma para a emissão dos certificados."""

    curso_nome: str
    carga_horaria: int
    data_conclusao: str
    data_inicio: Optional[str] = None
    data_emissao: Optional[str] = None
    modalidade: str = "Curso Livre de Capacitação Profissional"
    instrutor: str = "Tel Santana Leite"
    cidade: str = "Goiânia"
    ementa: str = ""
    frequencia_minima: int = 75


@dataclass
class CertificadoRegistro:
    """Modelo do assento oficial no Livro de Registro Digital."""

    id: Optional[int]
    codigo_autenticidade: str
    aluno_nome: str
    aluno_cpf: str
    aluno_cpf_mascarado: str
    curso_nome: str
    carga_horaria: int
    carga_horaria_extenso: str
    data_inicio: Optional[str]
    data_conclusao: str
    data_emissao: str
    modalidade: str
    instrutor: str
    cidade: str
    ementa: str
    livro_numero: int
    folha_numero: int
    registro_numero: int
    frequencia: int = 100
    created_at: Optional[str] = None


class LivroRegistroManager:
    """
    Gerenciador documental do Livro de Registro Digital com persistência
    relacional (SQLite local com suporte a DATABASE_URL) e exportação contínua
    para planilha Excel mestre (livro_registro_certificados.xlsx).
    """

    def __init__(
        self,
        db_url_or_path: Optional[str] = None,
        folhas_por_livro: int = 100,
        excel_export_path: Optional[Union[str, Path]] = None,
        initial_livro: int = 1,
        initial_folha: int = 1,
        initial_registro: int = 1,
    ):
        self.folhas_por_livro = max(1, int(folhas_por_livro))
        self.excel_export_path = str(excel_export_path) if excel_export_path else None
        self.initial_livro = max(1, int(initial_livro))
        self.initial_folha = max(1, int(initial_folha))
        self.initial_registro = max(1, int(initial_registro))

        # Resolução da string de conexão
        if db_url_or_path:
            self.db_url = str(db_url_or_path)
        else:
            self.db_url = os.getenv("DATABASE_URL", "registros.db")

        self.is_postgres = self.db_url.startswith(("postgresql://", "postgres://"))
        self._init_db()

    def _get_connection(self):
        """Retorna uma conexão ativa com o banco de dados configurado."""
        if self.is_postgres:
            try:
                import psycopg2
                import psycopg2.extras
                conn = psycopg2.connect(self.db_url)
                return conn
            except ImportError as e:
                raise ImportError(
                    "O driver 'psycopg2' é necessário para conexões PostgreSQL/Supabase via DATABASE_URL. "
                    "Instale com 'pip install psycopg2-binary'."
                ) from e
        else:
            # SQLite
            sqlite_path = self.db_url
            if sqlite_path.startswith("sqlite:///"):
                sqlite_path = sqlite_path.replace("sqlite:///", "")
            elif sqlite_path.startswith("sqlite://"):
                sqlite_path = sqlite_path.replace("sqlite://", "")

            conn = sqlite3.connect(sqlite_path)
            conn.row_factory = sqlite3.Row
            return conn

    def _init_db(self):
        """Inicializa a estrutura relacional de tabelas e índices se não existirem."""
        conn = self._get_connection()
        try:
            with conn:
                cursor = conn.cursor()
                if self.is_postgres:
                    id_col_type = "SERIAL PRIMARY KEY"
                else:
                    id_col_type = "INTEGER PRIMARY KEY AUTOINCREMENT"

                schema_sql = f"""
                CREATE TABLE IF NOT EXISTS registros_certificados (
                    id {id_col_type},
                    codigo_autenticidade TEXT UNIQUE NOT NULL,
                    aluno_nome TEXT NOT NULL,
                    aluno_cpf TEXT NOT NULL,
                    aluno_cpf_mascarado TEXT NOT NULL,
                    curso_nome TEXT NOT NULL,
                    carga_horaria INTEGER NOT NULL,
                    carga_horaria_extenso TEXT NOT NULL,
                    data_inicio TEXT,
                    data_conclusao TEXT NOT NULL,
                    data_emissao TEXT NOT NULL,
                    modalidade TEXT NOT NULL,
                    instrutor TEXT,
                    cidade TEXT,
                    ementa TEXT,
                    livro_numero INTEGER NOT NULL,
                    folha_numero INTEGER NOT NULL,
                    registro_numero INTEGER NOT NULL,
                    frequencia INTEGER DEFAULT 100,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
                """
                cursor.execute(schema_sql)
                try:
                    cursor.execute("ALTER TABLE registros_certificados ADD COLUMN frequencia INTEGER DEFAULT 100;")
                except Exception:
                    pass
                cursor.execute(
                    "CREATE INDEX IF NOT EXISTS idx_registros_codigo ON registros_certificados(codigo_autenticidade);"
                )
                cursor.execute(
                    "CREATE INDEX IF NOT EXISTS idx_registros_livro_folha ON registros_certificados(livro_numero, folha_numero);"
                )
                cursor.execute(
                    "CREATE INDEX IF NOT EXISTS idx_registros_numero ON registros_certificados(registro_numero);"
                )
        finally:
            conn.close()

    def get_last_record(self) -> Optional[CertificadoRegistro]:
        """Obtém o último assento formal registrado no banco de dados."""
        conn = self._get_connection()
        try:
            cursor = conn.cursor()
            cursor.execute(
                "SELECT * FROM registros_certificados ORDER BY registro_numero DESC, id DESC LIMIT 1"
            )
            row = cursor.fetchone()
            if not row:
                return None
            return self._row_to_record(row)
        finally:
            conn.close()

    def get_next_numbers(self) -> Tuple[int, int, int]:
        """
        Calcula a próxima trinca sequencial de (Livro, Folha, Registro).
        Aplica estritamente a regra de 1 registro por folha e virada de livro
        ao alcançar a capacidade máxima (100 folhas por padrão).
        """
        last = self.get_last_record()
        if last is None:
            return (self.initial_livro, self.initial_folha, self.initial_registro)

        next_registro = last.registro_numero + 1
        if last.folha_numero >= self.folhas_por_livro:
            next_livro = last.livro_numero + 1
            next_folha = 1
        else:
            next_livro = last.livro_numero
            next_folha = last.folha_numero + 1

        return (next_livro, next_folha, next_registro)

    def register_certificate(
        self,
        aluno: Union[ValidacaoAluno, Dict[str, Any]],
        curso: CursoMetadata,
        salt: Optional[str] = None,
    ) -> CertificadoRegistro:
        """Registra um único certificado gerando numeração sequencial atômica."""
        results = self.register_batch([aluno], curso, salt=salt)
        return results[0]

    def register_batch(
        self,
        alunos: Sequence[Union[ValidacaoAluno, Dict[str, Any]]],
        curso: CursoMetadata,
        salt: Optional[str] = None,
    ) -> List[CertificadoRegistro]:
        """
        Registra um lote de alunos em uma transação atômica única,
        garantindo integridade sequencial ininterrupta entre livros e folhas.
        Atualiza a planilha mestre Excel se configurado.
        """
        if not alunos:
            return []

        conn = self._get_connection()
        registered: List[CertificadoRegistro] = []

        try:
            with conn:
                cursor = conn.cursor()

                # Busca o último registro de forma bloqueante na transação
                cursor.execute(
                    "SELECT livro_numero, folha_numero, registro_numero "
                    "FROM registros_certificados "
                    "ORDER BY registro_numero DESC, id DESC LIMIT 1"
                )
                last_row = cursor.fetchone()

                if last_row is None:
                    curr_livro = self.initial_livro
                    curr_folha = self.initial_folha
                    curr_reg = self.initial_registro
                    is_first = True
                else:
                    curr_livro = last_row[0]
                    curr_folha = last_row[1]
                    curr_reg = last_row[2]
                    is_first = False

                data_emissao = curso.data_emissao or datetime.now().strftime("%d/%m/%Y")
                extenso_carga = formatar_carga_horaria_extenso(curso.carga_horaria)

                for aluno in alunos:
                    if is_first:
                        livro = curr_livro
                        folha = curr_folha
                        reg_num = curr_reg
                        is_first = False
                    else:
                        reg_num = curr_reg + 1
                        if curr_folha >= self.folhas_por_livro:
                            livro = curr_livro + 1
                            folha = 1
                        else:
                            livro = curr_livro
                            folha = curr_folha + 1

                    # Atualiza ponteiro para a próxima iteração
                    curr_livro = livro
                    curr_folha = folha
                    curr_reg = reg_num

                    # Extração de dados do aluno
                    freq_aluno = 100
                    if isinstance(aluno, ValidacaoAluno):
                        nome_aluno = aluno.nome
                        cpf_limpo = aluno.cpf or ""
                        cpf_mascarado = aluno.cpf_mascarado or "Não informado"
                        freq_aluno = getattr(aluno, "frequencia", 100)
                    elif isinstance(aluno, dict):
                        nome_aluno = normalize_name(aluno.get("nome", ""))
                        cpf_raw = aluno.get("cpf", "")
                        cpf_limpo = aluno.get("cpf_limpo", "") or aluno.get("cpf", "") or ""
                        cpf_mascarado = aluno.get("cpf_mascarado", "") or (mask_cpf(cpf_raw) if cpf_raw else "Não informado")
                        freq_aluno = int(aluno.get("frequencia", 100))
                    else:
                        raise ValueError(f"Tipo de aluno inválido para registro: {type(aluno)}")

                    codigo_autenticidade = generate_authenticity_code(
                        aluno_cpf=cpf_limpo,
                        curso_nome=curso.curso_nome,
                        registro_num=reg_num,
                        salt=salt,
                        aluno_nome=nome_aluno,
                    )

                    insert_sql = """
                    INSERT INTO registros_certificados (
                        codigo_autenticidade, aluno_nome, aluno_cpf, aluno_cpf_mascarado,
                        curso_nome, carga_horaria, carga_horaria_extenso,
                        data_inicio, data_conclusao, data_emissao,
                        modalidade, instrutor, cidade, ementa,
                        livro_numero, folha_numero, registro_numero, frequencia
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    """
                    if self.is_postgres:
                        insert_sql = insert_sql.replace("?", "%s")

                    params = (
                        codigo_autenticidade,
                        nome_aluno,
                        cpf_limpo,
                        cpf_mascarado,
                        curso.curso_nome,
                        curso.carga_horaria,
                        extenso_carga,
                        curso.data_inicio or "",
                        curso.data_conclusao,
                        data_emissao,
                        curso.modalidade,
                        curso.instrutor,
                        curso.cidade,
                        curso.ementa,
                        livro,
                        folha,
                        reg_num,
                        freq_aluno,
                    )
                    cursor.execute(insert_sql, params)

                    registro_obj = CertificadoRegistro(
                        id=None,
                        codigo_autenticidade=codigo_autenticidade,
                        aluno_nome=nome_aluno,
                        aluno_cpf=cpf_limpo,
                        aluno_cpf_mascarado=cpf_mascarado,
                        curso_nome=curso.curso_nome,
                        carga_horaria=curso.carga_horaria,
                        carga_horaria_extenso=extenso_carga,
                        data_inicio=curso.data_inicio,
                        data_conclusao=curso.data_conclusao,
                        data_emissao=data_emissao,
                        modalidade=curso.modalidade,
                        instrutor=curso.instrutor,
                        cidade=curso.cidade,
                        ementa=curso.ementa,
                        livro_numero=livro,
                        folha_numero=folha,
                        registro_numero=reg_num,
                        frequencia=freq_aluno,
                    )
                    registered.append(registro_obj)

        finally:
            conn.close()

        # Exportação automática e síncrona da planilha de auditoria
        if self.excel_export_path:
            self.export_to_excel(self.excel_export_path)

        return registered

    def get_certificate_by_code(self, codigo: str) -> Optional[CertificadoRegistro]:
        """
        Consulta um certificado no Livro de Registro Digital pelo Código de Autenticidade.
        A busca é insensível a maiúsculas/minúsculas.
        """
        if not codigo:
            return None

        clean_code = str(codigo).strip().upper()
        conn = self._get_connection()
        try:
            cursor = conn.cursor()
            query = "SELECT * FROM registros_certificados WHERE UPPER(codigo_autenticidade) = ?"
            if self.is_postgres:
                query = query.replace("?", "%s")

            cursor.execute(query, (clean_code,))
            row = cursor.fetchone()
            if not row:
                return None
            return self._row_to_record(row)
        finally:
            conn.close()

    def list_all_certificates(self) -> List[CertificadoRegistro]:
        """Retorna todos os assentos registrados em ordem cronológica de registro."""
        conn = self._get_connection()
        try:
            cursor = conn.cursor()
            cursor.execute(
                "SELECT * FROM registros_certificados ORDER BY registro_numero ASC, id ASC"
            )
            rows = cursor.fetchall()
            return [self._row_to_record(r) for r in rows]
        finally:
            conn.close()

    def export_to_excel(
        self, destination: Union[str, Path, io.BytesIO] = "livro_registro_certificados.xlsx"
    ) -> Union[Path, io.BytesIO]:
        """
        Exporta todos os assentos do Livro de Registro Digital para a planilha oficial
        'livro_registro_certificados.xlsx' com formatação notarial profissional.
        """
        registros = self.list_all_certificates()

        wb = openpyxl.Workbook()
        ws = wb.active
        ws.title = "Livro de Registro"

        # Estilos notariais e corporativos
        header_font = Font(name="Calibri", size=11, bold=True, color="FFFFFF")
        header_fill = PatternFill(start_color="1E3A8A", end_color="1E3A8A", fill_type="solid")  # Azul Marinho
        header_align = Alignment(horizontal="center", vertical="center", wrap_text=True)

        data_font = Font(name="Calibri", size=10)
        zebra_fill = PatternFill(start_color="F8FAFC", end_color="F8FAFC", fill_type="solid")
        white_fill = PatternFill(start_color="FFFFFF", end_color="FFFFFF", fill_type="solid")

        thin_border_side = Side(style="thin", color="D1D5DB")
        border = Border(
            left=thin_border_side,
            right=thin_border_side,
            top=thin_border_side,
            bottom=thin_border_side,
        )

        headers = [
            "Livro Nº",
            "Folha Nº",
            "Registro Nº",
            "Código de Autenticidade",
            "Nome do Aluno",
            "CPF",
            "Curso",
            "Carga Horária",
            "Data de Início",
            "Data de Conclusão",
            "Data de Emissão",
            "Modalidade",
            "Instrutor",
            "Cidade",
        ]

        ws.append(headers)
        ws.row_dimensions[1].height = 28

        for col_idx in range(1, len(headers) + 1):
            cell = ws.cell(row=1, column=col_idx)
            cell.font = header_font
            cell.fill = header_fill
            cell.alignment = header_align
            cell.border = border

        # População dos registros
        for row_idx, reg in enumerate(registros, start=2):
            cpf_exibicao = format_cpf(reg.aluno_cpf) if len(reg.aluno_cpf) == 11 else (reg.aluno_cpf if reg.aluno_cpf else "-")
            row_data = [
                reg.livro_numero,
                reg.folha_numero,
                reg.registro_numero,
                reg.codigo_autenticidade,
                reg.aluno_nome,
                cpf_exibicao,
                reg.curso_nome,
                f"{reg.carga_horaria}h",
                reg.data_inicio or "-",
                reg.data_conclusao,
                reg.data_emissao,
                reg.modalidade,
                reg.instrutor or "-",
                reg.cidade or "-",
            ]
            ws.append(row_data)
            ws.row_dimensions[row_idx].height = 20

            current_fill = zebra_fill if (row_idx % 2 == 0) else white_fill
            for col_idx, val in enumerate(row_data, start=1):
                cell = ws.cell(row=row_idx, column=col_idx)
                cell.font = data_font
                cell.fill = current_fill
                cell.border = border

                # Alinhamentos contextuais
                if col_idx in (1, 2, 3, 4, 6, 8, 9, 10, 11):
                    cell.alignment = Alignment(horizontal="center", vertical="center")
                else:
                    cell.alignment = Alignment(horizontal="left", vertical="center")

        # Ajuste inteligente de largura de colunas
        for col_idx in range(1, len(headers) + 1):
            col_letter = get_column_letter(col_idx)
            max_len = max(
                len(str(ws.cell(row=r, column=col_idx).value or ""))
                for r in range(1, ws.max_row + 1)
            )
            ws.column_dimensions[col_letter].width = max(max_len + 4, 12)

        # Congelar painel na linha de cabeçalho
        ws.freeze_panes = "A2"

        # Salvar para caminho ou buffer
        if isinstance(destination, io.IOBase):
            wb.save(destination)
            return destination
        else:
            path = Path(destination)
            path.parent.mkdir(parents=True, exist_ok=True)
            wb.save(str(path))
            return path

    def _row_to_record(self, row: Any) -> CertificadoRegistro:
        """Converte uma tupla ou sqlite3.Row em um objeto CertificadoRegistro."""
        if hasattr(row, "keys"):
            # sqlite3.Row ou psycopg2 DictRow
            return CertificadoRegistro(
                id=row["id"],
                codigo_autenticidade=row["codigo_autenticidade"],
                aluno_nome=row["aluno_nome"],
                aluno_cpf=row["aluno_cpf"],
                aluno_cpf_mascarado=row["aluno_cpf_mascarado"],
                curso_nome=row["curso_nome"],
                carga_horaria=int(row["carga_horaria"]),
                carga_horaria_extenso=row["carga_horaria_extenso"],
                data_inicio=row["data_inicio"],
                data_conclusao=row["data_conclusao"],
                data_emissao=row["data_emissao"],
                modalidade=row["modalidade"],
                instrutor=row["instrutor"],
                cidade=row["cidade"],
                ementa=row["ementa"],
                livro_numero=int(row["livro_numero"]),
                folha_numero=int(row["folha_numero"]),
                registro_numero=int(row["registro_numero"]),
                frequencia=int(row["frequencia"]) if "frequencia" in row.keys() and row["frequencia"] is not None else 100,
                created_at=str(row["created_at"]) if row["created_at"] else None,
            )
        else:
            # Fallback para tupla posicional
            return CertificadoRegistro(
                id=row[0],
                codigo_autenticidade=row[1],
                aluno_nome=row[2],
                aluno_cpf=row[3],
                aluno_cpf_mascarado=row[4],
                curso_nome=row[5],
                carga_horaria=int(row[6]),
                carga_horaria_extenso=row[7],
                data_inicio=row[8],
                data_conclusao=row[9],
                data_emissao=row[10],
                modalidade=row[11],
                instrutor=row[12],
                cidade=row[13],
                ementa=row[14],
                livro_numero=int(row[15]),
                folha_numero=int(row[16]),
                registro_numero=int(row[17]),
                frequencia=int(row[18]) if len(row) > 19 else 100,
                created_at=str(row[19]) if len(row) > 19 else (str(row[18]) if len(row) > 18 else None),
            )
