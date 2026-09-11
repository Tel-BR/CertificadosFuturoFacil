"""Serviço central de emissão de certificados em lote e empacotamento ZIP.

Orquestra:
1. Validação e registro atômico no Livro de Registro Digital.
2. Geração dos PDFs individuais duplex para cada aluno.
3. Consolidação duplex sequencial para gráfica rápida (2 * N páginas).
4. Exportação e atualização contínua da planilha Excel mestre (livro_registro_certificados.xlsx).
5. Empacotamento estruturado em arquivo ZIP contendo todos os artefatos.
"""

from dataclasses import dataclass, field
import io
from pathlib import Path
import re
from typing import Any, Callable, Dict, List, Optional, Sequence, Union
import zipfile

from core.consolidator import consolidate_duplex_pdf
from core.registry import (
    CertificadoRegistro,
    CursoMetadata,
    LivroRegistroManager,
)
from core.renderer import (
    CertificateRenderConfig,
    generate_certificate_pdf,
)
from core.validator import ValidacaoAluno, validar_aluno


@dataclass
class BatchEmissionResult:
    """Resultado da emissão de um lote completo de certificados."""

    registros: List[CertificadoRegistro]
    zip_bytes: bytes
    consolidated_pdf_bytes: bytes
    excel_bytes: bytes
    individual_pdfs: Dict[str, bytes]
    total_emitidos: int
    lote_id: Optional[int] = None


def _aluno_to_slug(nome: str) -> str:
    """Converte o nome do aluno em um identificador seguro para nome de arquivo."""
    slug = re.sub(r"[^\w\-]", "_", nome.strip().lower())
    return re.sub(r"_+", "_", slug).strip("_")


def create_zip_package(
    individual_pdfs: Dict[str, bytes],
    consolidated_pdf_bytes: bytes,
    excel_bytes: bytes,
    output_path_or_buffer: Optional[Union[str, Path, io.BytesIO]] = None,
) -> bytes:
    """
    Empacota os certificados individuais, o PDF consolidado para gráfica
    e a planilha Excel de registro em um único arquivo ZIP compactado.
    """
    zip_buffer = io.BytesIO()

    with zipfile.ZipFile(zip_buffer, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        # 1. PDFs individuais dentro da pasta certificados_individuais/
        for filename, pdf_data in individual_pdfs.items():
            zf.writestr(f"certificados_individuais/{filename}", pdf_data)

        # 2. PDF consolidado duplex na raiz do pacote
        zf.writestr("certificado_consolidado_grafica.pdf", consolidated_pdf_bytes)

        # 3. Planilha Excel do Livro de Registro na raiz do pacote
        zf.writestr("livro_registro_certificados.xlsx", excel_bytes)

    zip_bytes = zip_buffer.getvalue()

    if output_path_or_buffer is not None:
        if isinstance(output_path_or_buffer, io.BytesIO):
            output_path_or_buffer.write(zip_bytes)
        else:
            p = Path(output_path_or_buffer)
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_bytes(zip_bytes)

    return zip_bytes


def emitir_lote_certificados(
    alunos: Sequence[Union[ValidacaoAluno, Dict[str, Any]]],
    curso: CursoMetadata,
    config: Optional[CertificateRenderConfig] = None,
    manager: Optional[LivroRegistroManager] = None,
    zip_output_path_or_buffer: Optional[Union[str, Path, io.BytesIO]] = None,
    progress_callback: Optional[Callable[[int, int, str], None]] = None,
    encontros: Optional[List[Any]] = None,
    identificador_turma: Optional[str] = None,
) -> BatchEmissionResult:
    """
    Processa e emite um lote completo de certificados para os alunos informados.
    
    Etapas executadas:
    1. Filtra apenas alunos válidos (caso haja dicionários brutos, normaliza e valida).
    2. Cria o registro da turma/lote em turmas_lotes.
    3. Registra o lote no banco relacional gerando sequenciamento atômico de Livro, Folha e Registro.
    4. Gera cada PDF individual duplex.
    5. Gera o documento consolidado intercalado para gráfica (2 * N páginas).
    6. Exporta o Livro de Registro atualizado em Excel.
    7. Monta o pacote ZIP final contendo todos os arquivos.
    """
    if config is None:
        config = CertificateRenderConfig()

    if manager is None:
        manager = LivroRegistroManager()

    # Normalização de alunos válidos
    alunos_validos = []
    for a in alunos:
        if isinstance(a, ValidacaoAluno):
            if a.is_valido:
                alunos_validos.append(a)
        elif isinstance(a, dict):
            nome = a.get("nome") or a.get("aluno_nome", "")
            cpf = a.get("cpf") or a.get("aluno_cpf", "")
            freq = a.get("frequencia", 100)
            val = validar_aluno(nome, cpf, frequencia=freq)
            if val.is_valido:
                alunos_validos.append(val)

    if not alunos_validos:
        raise ValueError("Nenhum aluno válido para emissão do lote de certificados.")

    total_alunos = len(alunos_validos)

    if progress_callback:
        progress_callback(0, total_alunos, "Registrando assentos no Livro de Registro Digital...")

    # Criação da turma/lote no banco de dados
    lote_id = None
    try:
        lote_id = manager.criar_turma_lote(
            curso=curso,
            total_alunos=total_alunos,
            encontros=encontros,
            identificador=identificador_turma,
        )
    except Exception:
        pass

    # Registro atômico no banco com vinculação ao lote
    registros = manager.register_batch(alunos_validos, curso, lote_id=lote_id)

    # Geração dos PDFs individuais
    individual_pdfs: Dict[str, bytes] = {}
    for idx, reg in enumerate(registros, start=1):
        if progress_callback:
            progress_callback(
                idx,
                total_alunos,
                f"Gerando certificado {idx}/{total_alunos}: {reg.aluno_nome}...",
            )

        pdf_bytes = generate_certificate_pdf(reg, config=config)
        nome_slug = _aluno_to_slug(reg.aluno_nome)
        filename = f"certificado_{reg.registro_numero:04d}_{nome_slug}.pdf"
        individual_pdfs[filename] = pdf_bytes

    if progress_callback:
        progress_callback(total_alunos, total_alunos, "Consolidando documento duplex para gráfica...")

    # Geração do PDF consolidado duplex
    consolidated_bytes = consolidate_duplex_pdf(registros, config=config)

    # Exportação da planilha Excel do Livro de Registro
    excel_buf = io.BytesIO()
    manager.export_to_excel(excel_buf)
    excel_bytes = excel_buf.getvalue()

    if progress_callback:
        progress_callback(total_alunos, total_alunos, "Montando pacote ZIP final...")

    # Montagem do arquivo ZIP
    zip_bytes = create_zip_package(
        individual_pdfs=individual_pdfs,
        consolidated_pdf_bytes=consolidated_bytes,
        excel_bytes=excel_bytes,
        output_path_or_buffer=zip_output_path_or_buffer,
    )

    if progress_callback:
        progress_callback(total_alunos, total_alunos, "Lote concluído com sucesso!")

    return BatchEmissionResult(
        registros=registros,
        zip_bytes=zip_bytes,
        consolidated_pdf_bytes=consolidated_bytes,
        excel_bytes=excel_bytes,
        individual_pdfs=individual_pdfs,
        total_emitidos=len(registros),
        lote_id=lote_id,
    )
