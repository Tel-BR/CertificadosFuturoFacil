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
import json
import os
from pathlib import Path
import sqlite3
from typing import Any, Dict, List, Optional, Sequence, Tuple, Union
import uuid

import num2words
import openpyxl
from openpyxl.styles import Alignment, Border, Font, PatternFill, Side
from openpyxl.utils import get_column_letter

from core.validator import ValidacaoAluno, clean_cpf, format_cpf, mask_cpf, normalize_name, validar_aluno


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
    lote_id: Optional[int] = None
    presencas_detalhadas: Optional[str] = None
    horas_presentes: Optional[int] = None
    horas_totais: Optional[int] = None


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
                for col_name, col_def in [
                    ("frequencia", "INTEGER DEFAULT 100"),
                    ("lote_id", "INTEGER"),
                    ("presencas_detalhadas", "TEXT"),
                    ("horas_presentes", "INTEGER"),
                    ("horas_totais", "INTEGER"),
                ]:
                    try:
                        cursor.execute(f"ALTER TABLE registros_certificados ADD COLUMN {col_name} {col_def};")
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
                cursor.execute(
                    "CREATE INDEX IF NOT EXISTS idx_registros_lote ON registros_certificados(lote_id);"
                )

                # Tabela de Turmas / Lotes de Emissão
                schema_turmas = f"""
                CREATE TABLE IF NOT EXISTS turmas_lotes (
                    id {id_col_type},
                    identificador_lote TEXT NOT NULL,
                    curso_nome TEXT NOT NULL,
                    carga_horaria INTEGER NOT NULL,
                    data_inicio TEXT,
                    data_conclusao TEXT NOT NULL,
                    data_emissao TEXT NOT NULL,
                    modalidade TEXT NOT NULL,
                    instrutor TEXT,
                    cidade TEXT,
                    ementa TEXT,
                    total_alunos INTEGER NOT NULL,
                    tem_diario_detalhado BOOLEAN DEFAULT 0,
                    encontros_json TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
                """
                cursor.execute(schema_turmas)
                cursor.execute(
                    "CREATE INDEX IF NOT EXISTS idx_turmas_created ON turmas_lotes(created_at);"
                )

                # Tabela de Auditoria de Consultas de Validação Pública (LGPD Compliant)
                schema_consultas = f"""
                CREATE TABLE IF NOT EXISTS consultas_validacao (
                    id {id_col_type},
                    data_hora TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    codigo_consultado TEXT NOT NULL,
                    status TEXT NOT NULL,
                    aluno_nome TEXT,
                    curso_nome TEXT
                );
                """
                cursor.execute(schema_consultas)
                cursor.execute(
                    "CREATE INDEX IF NOT EXISTS idx_consultas_data ON consultas_validacao(data_hora);"
                )
                cursor.execute(
                    "CREATE INDEX IF NOT EXISTS idx_consultas_codigo ON consultas_validacao(codigo_consultado);"
                )

                # Tabela de Registro de Eventos de Wakeup / Inicialização
                schema_wakeups = f"""
                CREATE TABLE IF NOT EXISTS eventos_wakeup (
                    id {id_col_type},
                    data_hora TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    tipo_evento TEXT NOT NULL,
                    detalhes TEXT
                );
                """
                cursor.execute(schema_wakeups)
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
        lote_id: Optional[int] = None,
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
                    detalhes_presenca = None
                    horas_pres = None
                    horas_tot = None

                    if isinstance(aluno, ValidacaoAluno):
                        nome_aluno = aluno.nome
                        cpf_limpo = aluno.cpf or ""
                        cpf_mascarado = aluno.cpf_mascarado or "-"
                        freq_aluno = getattr(aluno, "frequencia", 100)
                        if getattr(aluno, "detalhes_presenca", None):
                            detalhes_presenca = json.dumps(aluno.detalhes_presenca, ensure_ascii=False)
                        horas_pres = getattr(aluno, "horas_presentes", None)
                        horas_tot = getattr(aluno, "horas_totais", None)
                    elif isinstance(aluno, dict):
                        nome_aluno = normalize_name(aluno.get("nome", ""))
                        cpf_raw = aluno.get("cpf", "")
                        cpf_limpo = aluno.get("cpf_limpo", "") or aluno.get("cpf", "") or ""
                        cpf_mascarado = aluno.get("cpf_mascarado", "") or (mask_cpf(cpf_raw) if cpf_raw else "-")
                        freq_aluno = int(aluno.get("frequencia", 100))
                        if aluno.get("detalhes_presenca"):
                            detalhes_presenca = json.dumps(aluno["detalhes_presenca"], ensure_ascii=False)
                        horas_pres = aluno.get("horas_presentes")
                        horas_tot = aluno.get("horas_totais")
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
                        livro_numero, folha_numero, registro_numero, frequencia,
                        lote_id, presencas_detalhadas, horas_presentes, horas_totais
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                        lote_id,
                        detalhes_presenca,
                        horas_pres,
                        horas_tot,
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
                        lote_id=lote_id,
                        presencas_detalhadas=detalhes_presenca,
                        horas_presentes=horas_pres,
                        horas_totais=horas_tot,
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

    def update_certificate(
        self,
        codigo_autenticidade: str,
        novo_nome: Optional[str] = None,
        novo_cpf: Optional[str] = None,
    ) -> CertificadoRegistro:
        """
        Atualiza dados cadastrais de um certificado existente (retificação de nome ou CPF).
        Revalida matematicamente os dados e atualiza a planilha mestre Excel se configurado.
        """
        reg = self.get_certificate_by_code(codigo_autenticidade)
        if reg is None:
            raise ValueError(f"Certificado não encontrado para o código: {codigo_autenticidade}")

        aluno_nome_final = reg.aluno_nome
        if novo_nome is not None:
            norm_nome = normalize_name(novo_nome)
            if not norm_nome or len(norm_nome.split()) < 2:
                raise ValueError("Nome do aluno deve conter nome e sobrenome (mínimo de duas palavras).")
            aluno_nome_final = norm_nome

        aluno_cpf_final = reg.aluno_cpf
        aluno_cpf_mascarado_final = reg.aluno_cpf_mascarado
        if novo_cpf is not None:
            cpf_limpo = clean_cpf(novo_cpf)
            if cpf_limpo:
                from core.validator import validate_cpf
                if not validate_cpf(cpf_limpo):
                    raise ValueError(f"CPF inválido ou com dígitos verificadores incorretos: {novo_cpf}")
                aluno_cpf_final = cpf_limpo
                aluno_cpf_mascarado_final = mask_cpf(cpf_limpo)
            else:
                aluno_cpf_final = ""
                aluno_cpf_mascarado_final = "-"

        conn = self._get_connection()
        try:
            with conn:
                cursor = conn.cursor()
                query = """
                UPDATE registros_certificados
                SET aluno_nome = ?, aluno_cpf = ?, aluno_cpf_mascarado = ?
                WHERE UPPER(codigo_autenticidade) = ?
                """
                if self.is_postgres:
                    query = query.replace("?", "%s")
                cursor.execute(
                    query,
                    (aluno_nome_final, aluno_cpf_final, aluno_cpf_mascarado_final, reg.codigo_autenticidade.upper()),
                )
        finally:
            conn.close()

        if self.excel_export_path:
            self.export_to_excel(self.excel_export_path)

        updated = self.get_certificate_by_code(codigo_autenticidade)
        assert updated is not None
        return updated

    def reset_database(
        self,
        initial_livro: Optional[int] = None,
        initial_folha: Optional[int] = None,
        initial_registro: Optional[int] = None,
    ) -> None:
        """
        Limpa todos os registros do banco de dados (para reset de testes)
        e opcionalmente reconfigura o ponto de partida numérico do Livro.
        """
        if initial_livro is not None:
            self.initial_livro = max(1, int(initial_livro))
        if initial_folha is not None:
            self.initial_folha = max(1, int(initial_folha))
        if initial_registro is not None:
            self.initial_registro = max(1, int(initial_registro))

        conn = self._get_connection()
        try:
            with conn:
                cursor = conn.cursor()
                cursor.execute("DELETE FROM registros_certificados")
                if not self.is_postgres:
                    try:
                        cursor.execute("DELETE FROM sqlite_sequence WHERE name='registros_certificados'")
                    except Exception:
                        pass
        finally:
            conn.close()

        if self.excel_export_path:
            self.export_to_excel(self.excel_export_path)

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
                lote_id=int(row["lote_id"]) if "lote_id" in row.keys() and row["lote_id"] is not None else None,
                presencas_detalhadas=str(row["presencas_detalhadas"]) if "presencas_detalhadas" in row.keys() and row["presencas_detalhadas"] is not None else None,
                horas_presentes=int(row["horas_presentes"]) if "horas_presentes" in row.keys() and row["horas_presentes"] is not None else None,
                horas_totais=int(row["horas_totais"]) if "horas_totais" in row.keys() and row["horas_totais"] is not None else None,
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
                frequencia=int(row[18]) if len(row) > 18 else 100,
                created_at=str(row[19]) if len(row) > 19 else None,
                lote_id=int(row[20]) if len(row) > 20 and row[20] is not None else None,
                presencas_detalhadas=str(row[21]) if len(row) > 21 and row[21] is not None else None,
                horas_presentes=int(row[22]) if len(row) > 22 and row[22] is not None else None,
                horas_totais=int(row[23]) if len(row) > 23 and row[23] is not None else None,
            )

    def registrar_consulta_validacao(
        self,
        codigo: str,
        status: str,
        aluno_nome: Optional[str] = None,
        curso_nome: Optional[str] = None,
    ) -> None:
        """
        Registra uma tentativa de validação pública no histórico de auditoria.
        Conforme diretrizes de LGPD, não armazena CPF do aluno consultado.
        """
        conn = self._get_connection()
        try:
            with conn:
                cursor = conn.cursor()
                now_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                cursor.execute(
                    """
                    INSERT INTO consultas_validacao (data_hora, codigo_consultado, status, aluno_nome, curso_nome)
                    VALUES (?, ?, ?, ?, ?);
                    """,
                    (now_str, codigo.strip().upper(), status.strip().upper(), aluno_nome, curso_nome),
                )
        except Exception:
            pass  # Auditoria não deve quebrar a consulta pública em caso de falha transitória
        finally:
            conn.close()

    def registrar_evento_wakeup(
        self,
        tipo_evento: str = "INICIALIZACAO_SISTEMA",
        detalhes: Optional[str] = None,
    ) -> None:
        """Registra um evento de wakeup / inicialização do servidor."""
        conn = self._get_connection()
        try:
            with conn:
                cursor = conn.cursor()
                now_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                cursor.execute(
                    """
                    INSERT INTO eventos_wakeup (data_hora, tipo_evento, detalhes)
                    VALUES (?, ?, ?);
                    """,
                    (now_str, tipo_evento.strip().upper(), detalhes),
                )
        except Exception:
            pass
        finally:
            conn.close()

    def obter_historico_consultas(self, limite: int = 100) -> List[Dict[str, Any]]:
        """Recupera as últimas consultas de validação registradas."""
        conn = self._get_connection()
        try:
            cursor = conn.cursor()
            cursor.execute(
                """
                SELECT id, data_hora, codigo_consultado, status, aluno_nome, curso_nome
                FROM consultas_validacao
                ORDER BY id DESC
                LIMIT ?;
                """,
                (limite,),
            )
            rows = cursor.fetchall()
            result = []
            for r in rows:
                if hasattr(r, "keys"):
                    result.append({
                        "id": r["id"],
                        "data_hora": str(r["data_hora"]),
                        "codigo_consultado": r["codigo_consultado"],
                        "status": r["status"],
                        "aluno_nome": r["aluno_nome"] or "-",
                        "curso_nome": r["curso_nome"] or "-",
                    })
                else:
                    result.append({
                        "id": r[0],
                        "data_hora": str(r[1]),
                        "codigo_consultado": r[2],
                        "status": r[3],
                        "aluno_nome": r[4] or "-",
                        "curso_nome": r[5] or "-",
                    })
            return result
        finally:
            conn.close()

    def obter_historico_wakeups(self, limite: int = 20) -> List[Dict[str, Any]]:
        """Recupera os últimos eventos de wakeup e inicialização."""
        conn = self._get_connection()
        try:
            cursor = conn.cursor()
            cursor.execute(
                """
                SELECT id, data_hora, tipo_evento, detalhes
                FROM eventos_wakeup
                ORDER BY id DESC
                LIMIT ?;
                """,
                (limite,),
            )
            rows = cursor.fetchall()
            result = []
            for r in rows:
                if hasattr(r, "keys"):
                    result.append({
                        "id": r["id"],
                        "data_hora": str(r["data_hora"]),
                        "tipo_evento": r["tipo_evento"],
                        "detalhes": r["detalhes"] or "-",
                    })
                else:
                    result.append({
                        "id": r[0],
                        "data_hora": str(r[1]),
                        "tipo_evento": r[2],
                        "detalhes": r[3] or "-",
                    })
            return result
        finally:
            conn.close()

    def obter_estatisticas_auditoria(self) -> Dict[str, Any]:
        """Calcula métricas agregadas de consultas e wakeups."""
        conn = self._get_connection()
        try:
            cursor = conn.cursor()
            cursor.execute("SELECT COUNT(*) FROM consultas_validacao;")
            total_consultas = cursor.fetchone()[0]

            cursor.execute("SELECT COUNT(*) FROM consultas_validacao WHERE status = 'VALIDO';")
            total_validos = cursor.fetchone()[0]

            cursor.execute("SELECT COUNT(*) FROM consultas_validacao WHERE status != 'VALIDO';")
            total_invalidos = cursor.fetchone()[0]

            cursor.execute("SELECT data_hora FROM eventos_wakeup ORDER BY id DESC LIMIT 1;")
            last_wakeup_row = cursor.fetchone()
            ultimo_wakeup = str(last_wakeup_row[0]) if last_wakeup_row else "Nenhum registrado"

            return {
                "total_consultas": total_consultas,
                "total_validos": total_validos,
                "total_invalidos": total_invalidos,
                "ultimo_wakeup": ultimo_wakeup,
            }
        finally:
            conn.close()

    def exportar_historico_consultas_excel(
        self, destination: Optional[Union[str, Path, io.BytesIO]] = None
    ) -> Union[str, Path, io.BytesIO]:
        """Exporta o relatório completo de auditoria de consultas para planilha Excel formatada."""
        if destination is None:
            destination = io.BytesIO()

        consultas = self.obter_historico_consultas(limite=5000)

        wb = openpyxl.Workbook()
        ws = wb.active
        ws.title = "Auditoria de Validações"
        ws.views.sheetView[0].showGridLines = True

        header_font = Font(name="Calibri", size=11, bold=True, color="FFFFFF")
        header_fill = PatternFill(start_color="0E7490", end_color="0E7490", fill_type="solid")
        border_thin = Side(border_style="thin", color="CBD5E1")
        border = Border(left=border_thin, right=border_thin, top=border_thin, bottom=border_thin)

        headers = ["ID", "Data/Hora", "Código Consultado (SHA-256)", "Status", "Aluno", "Curso"]
        for col_idx, text in enumerate(headers, 1):
            cell = ws.cell(row=1, column=col_idx, value=text)
            cell.font = header_font
            cell.fill = header_fill
            cell.alignment = Alignment(horizontal="center", vertical="center")
            cell.border = border

        for row_idx, item in enumerate(consultas, 2):
            ws.cell(row=row_idx, column=1, value=item["id"]).alignment = Alignment(horizontal="center")
            ws.cell(row=row_idx, column=2, value=item["data_hora"]).alignment = Alignment(horizontal="center")
            ws.cell(row=row_idx, column=3, value=item["codigo_consultado"]).alignment = Alignment(horizontal="center")
            ws.cell(row=row_idx, column=4, value=item["status"]).alignment = Alignment(horizontal="center")
            ws.cell(row=row_idx, column=5, value=item["aluno_nome"]).alignment = Alignment(horizontal="left")
            ws.cell(row=row_idx, column=6, value=item["curso_nome"]).alignment = Alignment(horizontal="left")
            for c in range(1, 7):
                ws.cell(row=row_idx, column=c).border = border

        for col_idx in range(1, 7):
            col_letter = get_column_letter(col_idx)
            max_len = max(len(str(ws.cell(row=r, column=col_idx).value or "")) for r in range(1, ws.max_row + 1))
            ws.column_dimensions[col_letter].width = max(max_len + 3, 12)

        ws.freeze_panes = "A2"

        if isinstance(destination, io.IOBase):
            wb.save(destination)
            return destination
        else:
            path = Path(destination)
            path.parent.mkdir(parents=True, exist_ok=True)
            wb.save(str(path))
            return path

    def criar_turma_lote(
        self,
        curso: CursoMetadata,
        total_alunos: int,
        encontros: Optional[List[Any]] = None,
        identificador: Optional[str] = None,
    ) -> int:
        """Cria um registro formal de turma/lote no banco de dados e retorna seu ID."""
        conn = self._get_connection()
        try:
            with conn:
                cursor = conn.cursor()
                cursor.execute("SELECT COUNT(*) FROM turmas_lotes;")
                count_turmas = cursor.fetchone()[0]
                numero_lote = count_turmas + 1

                if not identificador:
                    periodo_str = f"{curso.data_inicio or 'Início'} a {curso.data_conclusao}"
                    identificador = f"Lote #{numero_lote:02d} · {curso.curso_nome} ({periodo_str})"

                tem_diario = bool(encontros and len(encontros) > 0)
                encontros_dados = []
                if encontros:
                    for e in encontros:
                        if hasattr(e, "nome_coluna"):
                            encontros_dados.append({
                                "nome_coluna": e.nome_coluna,
                                "horas": getattr(e, "horas", 4),
                                "data_str": getattr(e, "data_str", str(getattr(e, "data", ""))),
                            })
                        elif isinstance(e, dict):
                            encontros_dados.append(e)
                        else:
                            encontros_dados.append({"nome_coluna": str(e), "horas": 4, "data_str": str(e)})

                encontros_json = json.dumps(encontros_dados, ensure_ascii=False) if encontros_dados else None

                insert_sql = """
                INSERT INTO turmas_lotes (
                    identificador_lote, curso_nome, carga_horaria,
                    data_inicio, data_conclusao, data_emissao,
                    modalidade, instrutor, cidade, ementa,
                    total_alunos, tem_diario_detalhado, encontros_json
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);
                """
                if self.is_postgres:
                    insert_sql = insert_sql.replace("?", "%s")

                cursor.execute(
                    insert_sql,
                    (
                        identificador,
                        curso.curso_nome,
                        curso.carga_horaria,
                        curso.data_inicio or "",
                        curso.data_conclusao,
                        curso.data_emissao or datetime.now().strftime("%d/%m/%Y"),
                        curso.modalidade,
                        curso.instrutor,
                        curso.cidade,
                        curso.ementa,
                        total_alunos,
                        1 if tem_diario else 0,
                        encontros_json,
                    ),
                )
                if self.is_postgres:
                    cursor.execute("SELECT LASTVAL();")
                    lote_id = cursor.fetchone()[0]
                else:
                    lote_id = cursor.lastrowid
                return lote_id
        finally:
            conn.close()

    def obter_turmas_lotes(self) -> List[Dict[str, Any]]:
        """Recupera a lista de todas as turmas/lotes emitidos ordenados do mais recente para o mais antigo."""
        conn = self._get_connection()
        try:
            cursor = conn.cursor()
            cursor.execute("SELECT * FROM turmas_lotes ORDER BY id DESC;")
            rows = cursor.fetchall()
            turmas = []
            for r in rows:
                if hasattr(r, "keys"):
                    d = dict(r)
                else:
                    d = {
                        "id": r[0],
                        "identificador_lote": r[1],
                        "curso_nome": r[2],
                        "carga_horaria": r[3],
                        "data_inicio": r[4],
                        "data_conclusao": r[5],
                        "data_emissao": r[6],
                        "modalidade": r[7],
                        "instrutor": r[8],
                        "cidade": r[9],
                        "ementa": r[10],
                        "total_alunos": r[11],
                        "tem_diario_detalhado": bool(r[12]),
                        "encontros_json": r[13],
                        "created_at": str(r[14]),
                    }
                turmas.append(d)
            return turmas
        finally:
            conn.close()

    def obter_diario_turma(self, lote_id: int) -> Dict[str, Any]:
        """Recupera o diário de classe completo de uma turma (metadados, lista de encontros e alunos com presenças)."""
        conn = self._get_connection()
        try:
            cursor = conn.cursor()
            cursor.execute("SELECT * FROM turmas_lotes WHERE id = ?;", (lote_id,))
            turma_row = cursor.fetchone()
            if not turma_row:
                return {}

            if hasattr(turma_row, "keys"):
                turma = dict(turma_row)
            else:
                turma = {
                    "id": turma_row[0],
                    "identificador_lote": turma_row[1],
                    "curso_nome": turma_row[2],
                    "carga_horaria": turma_row[3],
                    "data_inicio": turma_row[4],
                    "data_conclusao": turma_row[5],
                    "data_emissao": turma_row[6],
                    "modalidade": turma_row[7],
                    "instrutor": turma_row[8],
                    "cidade": turma_row[9],
                    "ementa": turma_row[10],
                    "total_alunos": turma_row[11],
                    "tem_diario_detalhado": bool(turma_row[12]),
                    "encontros_json": turma_row[13],
                    "created_at": str(turma_row[14]),
                }

            encontros = []
            if turma.get("encontros_json"):
                try:
                    encontros = json.loads(turma["encontros_json"])
                except Exception:
                    encontros = []

            cursor.execute(
                "SELECT * FROM registros_certificados WHERE lote_id = ? ORDER BY id ASC;",
                (lote_id,),
            )
            cert_rows = cursor.fetchall()
            alunos = []
            for cr in cert_rows:
                rec = self._row_to_record(cr)
                pres_dict = {}
                if rec.presencas_detalhadas:
                    try:
                        pres_dict = json.loads(rec.presencas_detalhadas)
                    except Exception:
                        pass
                alunos.append({
                    "id": rec.id,
                    "registro_numero": rec.registro_numero,
                    "codigo_autenticidade": rec.codigo_autenticidade,
                    "aluno_nome": rec.aluno_nome,
                    "aluno_cpf_mascarado": rec.aluno_cpf_mascarado,
                    "frequencia": rec.frequencia,
                    "horas_presentes": rec.horas_presentes,
                    "horas_totais": rec.horas_totais,
                    "presencas": pres_dict,
                })

            return {
                "turma": turma,
                "encontros": encontros,
                "alunos": alunos,
            }
        finally:
            conn.close()

    def retificar_frequencia_aluno(
        self,
        codigo_autenticidade: str,
        nova_frequencia: int,
        justificativa: Optional[str] = None,
    ) -> CertificadoRegistro:
        """
        Retifica a frequência de um certificado específico com justificativa legal registrada.
        Atualiza o banco relacional e a planilha mestre se configurada.
        """
        if not (0 <= nova_frequencia <= 100):
            raise ValueError(f"Frequência inválida: {nova_frequencia}%. Deve estar entre 0 e 100.")

        conn = self._get_connection()
        try:
            with conn:
                cursor = conn.cursor()
                update_sql = "UPDATE registros_certificados SET frequencia = ? WHERE codigo_autenticidade = ?;"
                if self.is_postgres:
                    update_sql = update_sql.replace("?", "%s")
                cursor.execute(update_sql, (nova_frequencia, codigo_autenticidade.strip().upper()))

                if justificativa:
                    # Registra a retificação na tabela de auditoria
                    now_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                    cursor.execute(
                        """
                        INSERT INTO consultas_validacao (data_hora, codigo_consultado, status, aluno_nome, curso_nome)
                        VALUES (?, ?, ?, ?, ?);
                        """,
                        (now_str, codigo_autenticidade, "RETIFICACAO_FREQUENCIA", f"Nova Freq: {nova_frequencia}%", justificativa),
                    )
        finally:
            conn.close()

        rec = self.get_certificate_by_code(codigo_autenticidade)
        if self.excel_export_path and Path(self.excel_export_path).exists():
            try:
                self.export_to_excel(self.excel_export_path)
            except Exception:
                pass
        return rec

    def exportar_diario_classe_excel(
        self,
        lote_id: int,
        instituicao_nome: str = "FUTURO FÁCIL",
        razao_social: Optional[str] = None,
        cnpj: Optional[str] = None,
        destination: Optional[Union[str, Path, io.BytesIO]] = None,
    ) -> Union[str, Path, io.BytesIO]:
        """Gera uma planilha Excel oficial diagramada do Diário de Classe da turma para impressão e assinatura."""
        if destination is None:
            destination = io.BytesIO()

        dados = self.obter_diario_turma(lote_id)
        if not dados or not dados.get("turma"):
            raise ValueError(f"Turma {lote_id} não encontrada para exportação do diário.")

        turma = dados["turma"]
        encontros = dados.get("encontros", [])
        alunos = dados.get("alunos", [])

        wb = openpyxl.Workbook()
        ws = wb.active
        ws.title = "Diário de Classe"
        ws.views.sheetView[0].showGridLines = True

        # Paleta institucional
        cor_primaria = "0E7490"  # Petróleo Tech
        header_fill = PatternFill(start_color=cor_primaria, end_color=cor_primaria, fill_type="solid")
        header_font = Font(name="Calibri", size=11, bold=True, color="FFFFFF")
        title_font = Font(name="Calibri", size=14, bold=True, color=cor_primaria)
        sub_font = Font(name="Calibri", size=10, bold=False, color="475569")
        bold_cell_font = Font(name="Calibri", size=10, bold=True, color="1E293B")
        data_font = Font(name="Calibri", size=10, color="334155")
        border_thin = Side(border_style="thin", color="CBD5E1")
        border = Border(left=border_thin, right=border_thin, top=border_thin, bottom=border_thin)

        # Cabeçalho Institucional
        ws.merge_cells("A1:G1")
        cell_t1 = ws.cell(row=1, column=1, value=f"{instituicao_nome.upper()} · DIÁRIO DE CLASSE & REGISTRO DE FREQUÊNCIA")
        cell_t1.font = title_font
        cell_t1.alignment = Alignment(horizontal="left", vertical="center")

        entidade_info = f"Entidade Emissora: {razao_social or instituicao_nome}" + (f" (CNPJ: {cnpj})" if cnpj else "")
        ws.merge_cells("A2:G2")
        cell_t2 = ws.cell(row=2, column=1, value=entidade_info)
        cell_t2.font = sub_font

        info_turma = (
            f"Curso: {turma.get('curso_nome', '')} · Carga Horária: {turma.get('carga_horaria', '')}h · "
            f"Período: {turma.get('data_inicio', '')} a {turma.get('data_conclusao', '')} · "
            f"Instrutor(a): {turma.get('instrutor', '')}"
        )
        ws.merge_cells("A3:G3")
        cell_t3 = ws.cell(row=3, column=1, value=info_turma)
        cell_t3.font = bold_cell_font

        ws.row_dimensions[1].height = 25
        ws.row_dimensions[2].height = 18
        ws.row_dimensions[3].height = 18

        # Linha de Cabeçalho da Tabela
        row_hdr = 5
        headers = ["Nº", "Nome do Aluno", "CPF (LGPD)"]
        if encontros:
            for enc in encontros:
                hdr_enc = enc.get("data_str") or enc.get("nome_coluna")
                headers.append(str(hdr_enc))
        headers.extend(["Horas Presentes", "Frequência (%)", "Situação"])

        for col_idx, h in enumerate(headers, 1):
            cell = ws.cell(row=row_hdr, column=col_idx, value=h)
            cell.font = header_font
            cell.fill = header_fill
            cell.alignment = Alignment(horizontal="center", vertical="center")
            cell.border = border
        ws.row_dimensions[row_hdr].height = 24

        # Linhas de Alunos
        current_row = row_hdr + 1
        for idx, a in enumerate(alunos, 1):
            ws.cell(row=current_row, column=1, value=idx).alignment = Alignment(horizontal="center")
            ws.cell(row=current_row, column=2, value=a["aluno_nome"]).alignment = Alignment(horizontal="left")
            ws.cell(row=current_row, column=3, value=a["aluno_cpf_mascarado"]).alignment = Alignment(horizontal="center")

            col_pos = 4
            if encontros:
                pres_map = a.get("presencas", {})
                for enc in encontros:
                    enc_key = enc.get("nome_coluna")
                    is_p = pres_map.get(enc_key, False)
                    p_cell = ws.cell(row=current_row, column=col_pos, value="P" if is_p else "F")
                    p_cell.alignment = Alignment(horizontal="center")
                    if is_p:
                        p_cell.fill = PatternFill(start_color="DCFCE7", end_color="DCFCE7", fill_type="solid")
                        p_cell.font = Font(name="Calibri", size=10, bold=True, color="166534")
                    else:
                        p_cell.fill = PatternFill(start_color="FEE2E2", end_color="FEE2E2", fill_type="solid")
                        p_cell.font = Font(name="Calibri", size=10, bold=True, color="991B1B")
                    col_pos += 1

            ws.cell(row=current_row, column=col_pos, value=a.get("horas_presentes") or "-").alignment = Alignment(horizontal="center")
            ws.cell(row=current_row, column=col_pos + 1, value=f"{a['frequencia']}%").alignment = Alignment(horizontal="center")

            is_aprovado = int(a["frequencia"]) >= 75
            sit_cell = ws.cell(row=current_row, column=col_pos + 2, value="APROVADO" if is_aprovado else "REPROVADO")
            sit_cell.alignment = Alignment(horizontal="center")
            sit_cell.font = Font(name="Calibri", size=10, bold=True, color="166534" if is_aprovado else "991B1B")

            for c in range(1, len(headers) + 1):
                if not ws.cell(row=current_row, column=c).border.left.style:
                    ws.cell(row=current_row, column=c).border = border
            current_row += 1

        # Linha de Assinatura do Instrutor
        current_row += 2
        ws.cell(row=current_row, column=2, value="___________________________________________________").font = bold_cell_font
        current_row += 1
        ws.cell(row=current_row, column=2, value=f"Prof(a). {turma.get('instrutor', 'Instrutor(a) Responsável')}").font = bold_cell_font
        current_row += 1
        ws.cell(row=current_row, column=2, value=f"Instrutor(a) Responsável · Expedido em {turma.get('data_emissao', '')} · {turma.get('cidade', 'Goiânia')}").font = sub_font

        for col_idx in range(1, len(headers) + 1):
            col_letter = get_column_letter(col_idx)
            max_len = max(len(str(ws.cell(row=r, column=col_idx).value or "")) for r in range(row_hdr, current_row - 3))
            ws.column_dimensions[col_letter].width = max(max_len + 4, 12)

        if isinstance(destination, io.IOBase):
            wb.save(destination)
            return destination
        else:
            path = Path(destination)
            path.parent.mkdir(parents=True, exist_ok=True)
            wb.save(str(path))
            return path


