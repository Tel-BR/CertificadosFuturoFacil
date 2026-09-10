#!/usr/bin/env python3
"""Utilitário de Linha de Comando (CLI) para Emissão em Lote de Certificados Futuro Fácil."""

import argparse
from datetime import datetime
import os
from pathlib import Path
import re
import sys
from typing import Optional

from core.batch_service import emitir_lote_certificados
from core.config import load_instituicao_config
from core.registry import CursoMetadata, LivroRegistroManager
from core.renderer import CertificateRenderConfig
from core.spreadsheet import read_and_validate_spreadsheet


def _curso_slug(nome: str) -> str:
    """Gera um slug limpo para nomes de arquivo a partir do nome do curso."""
    slug = re.sub(r"[^\w\-]", "_", nome.strip().lower())
    return re.sub(r"_+", "_", slug).strip("_")


def main():
    parser = argparse.ArgumentParser(
        description="Emissor de Certificados Futuro Fácil - Automação Headless de Emissão em Lote",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )

    # Parâmetros Obrigatórios da Turma
    parser.add_argument(
        "-p", "--planilha",
        required=True,
        help="Caminho do arquivo Excel (.xlsx) ou CSV com os dados dos alunos (Nome e CPF).",
    )
    parser.add_argument(
        "-c", "--curso",
        required=True,
        help="Nome oficial do curso livre.",
    )
    parser.add_argument(
        "-ch", "--carga-horaria",
        type=int,
        required=True,
        help="Carga horária total em horas (inteiro positivo).",
    )
    parser.add_argument(
        "-dc", "--data-conclusao",
        required=True,
        help="Data de conclusão do curso (ex: 15/09/2026 ou 2026-09-15).",
    )

    # Parâmetros Opcionais de Metadados
    parser.add_argument(
        "-di", "--data-inicio",
        default=None,
        help="Data de início do curso.",
    )
    parser.add_argument(
        "-de", "--data-emissao",
        default=None,
        help="Data de emissão formal dos certificados (padrão: data atual).",
    )
    parser.add_argument(
        "-m", "--modalidade",
        default="Curso Livre de Capacitação Profissional",
        help="Modalidade de ensino do curso.",
    )
    parser.add_argument(
        "-i", "--instrutor",
        default=None,
        help="Nome do(a) instrutor(a) responsável (padrão: das configurações institucionais).",
    )
    parser.add_argument(
        "--cidade",
        default=None,
        help="Cidade de realização do curso (padrão: das configurações institucionais).",
    )
    parser.add_argument(
        "-e", "--ementa",
        default="",
        help="Texto da ementa detalhada ou caminho para um arquivo de texto (.txt/.md).",
    )
    parser.add_argument(
        "-o", "--saida-zip",
        default=None,
        help="Caminho do arquivo .zip gerado (padrão: certificados_{curso}_{data}.zip).",
    )
    parser.add_argument(
        "--cpf-obrigatorio",
        action="store_true",
        help="Exige estritamente o CPF preenchido para todos os alunos.",
    )
    parser.add_argument(
        "--frequencia-minima",
        type=int,
        default=75,
        help="Percentual de frequência mínima exigida para aprovação.",
    )
    parser.add_argument(
        "--db-url",
        default=None,
        help="URL ou caminho do banco relacional (SQLite ou PostgreSQL via DATABASE_URL).",
    )

    # Customização Visual
    parser.add_argument(
        "--primary-color",
        default=None,
        help="Cor primária em formato hexadecimal (ex: #0E7490).",
    )
    parser.add_argument(
        "--secondary-color",
        default=None,
        help="Cor secundária em formato hexadecimal (ex: #EA580C).",
    )
    parser.add_argument(
        "--logo",
        default=None,
        help="Caminho da imagem do logotipo institucional (SVG ou PNG).",
    )
    parser.add_argument(
        "--assinatura",
        default=None,
        help="Caminho da imagem da assinatura digitalizada (PNG).",
    )
    parser.add_argument(
        "--url-base",
        default=None,
        help="URL base da consulta pública de autenticidade gravada no QR Code.",
    )
    parser.add_argument(
        "--planilha-registro",
        default="livro_registro_certificados.xlsx",
        help="Caminho do arquivo Excel de auditoria para sincronização contínua do Livro de Registro.",
    )

    args = parser.parse_args()

    # Carrega configurações institucionais salvas
    inst_cfg = load_instituicao_config()

    # Validação do arquivo de planilha
    planilha_path = Path(args.planilha)
    if not planilha_path.exists():
        sys.stderr.write(f"ERRO: Arquivo de planilha não encontrado: {planilha_path}\n")
        sys.exit(1)

    # Auditoria prévia da planilha
    print(f"[*] Auditando planilha: {planilha_path}...")
    audit_result = read_and_validate_spreadsheet(
        planilha_path,
        cpf_obrigatorio=args.cpf_obrigatorio,
        frequencia_minima=args.frequencia_minima,
    )

    if not audit_result.is_valid:
        sys.stderr.write("\n========================================================\n")
        sys.stderr.write("ERRO DE AUDITORIA: A planilha contém inconsistências!\n")
        sys.stderr.write("========================================================\n")
        for g_err in audit_result.global_errors:
            sys.stderr.write(f"- {g_err}\n")

        for idx, aluno in enumerate(audit_result.alunos, start=2):
            if not aluno.is_valido:
                motivos = "; ".join(aluno.erros)
                sys.stderr.write(f"- Linha {idx}: '{aluno.nome}' -> {motivos}\n")

        sys.stderr.write("\nCorrija a planilha antes de prosseguir com a emissão.\n")
        sys.exit(1)

    print(f"[+] Auditoria aprovada! {audit_result.valid_count} alunos válidos identificados.")

    # Resolução de ementa (se for caminho de arquivo existente, lê o conteúdo)
    ementa_texto = args.ementa
    if args.ementa and Path(args.ementa).is_file():
        ementa_texto = Path(args.ementa).read_text(encoding="utf-8")

    data_emissao_final = args.data_emissao or datetime.now().strftime("%d/%m/%Y")
    instrutor_final = args.instrutor or inst_cfg.instrutor_padrao
    cidade_final = args.cidade or inst_cfg.cidade_padrao
    primary_color_final = args.primary_color or inst_cfg.primary_color
    secondary_color_final = args.secondary_color or inst_cfg.secondary_color
    url_base_final = args.url_base or inst_cfg.validation_base_url
    logo_final = args.logo or inst_cfg.logo_path
    assinatura_final = args.assinatura or inst_cfg.signature_path

    # Construção dos modelos de domínio
    curso = CursoMetadata(
        curso_nome=args.curso,
        carga_horaria=args.carga_horaria,
        data_conclusao=args.data_conclusao,
        data_inicio=args.data_inicio,
        data_emissao=data_emissao_final,
        modalidade=args.modalidade,
        instrutor=instrutor_final,
        cidade=cidade_final,
        ementa=ementa_texto,
        frequencia_minima=args.frequencia_minima,
    )

    render_config = CertificateRenderConfig(
        primary_color=primary_color_final,
        secondary_color=secondary_color_final,
        logo_path=logo_final,
        signature_image_path=assinatura_final,
        validation_base_url=url_base_final,
        institution_name=inst_cfg.nome_fantasia,
        institution_tagline=inst_cfg.tagline,
        instructor_default=instrutor_final,
        city_default=cidade_final,
        cnpj=inst_cfg.cnpj,
        razao_social=inst_cfg.razao_social,
    )

    manager = LivroRegistroManager(
        db_url_or_path=args.db_url,
        excel_export_path=args.planilha_registro,
        initial_livro=inst_cfg.initial_livro,
        initial_folha=inst_cfg.initial_folha,
        initial_registro=inst_cfg.initial_registro,
    )

    # Definição do arquivo de saída ZIP
    if args.saida_zip:
        zip_target = Path(args.saida_zip)
    else:
        hoje_iso = datetime.now().strftime("%Y-%m-%d")
        slug = _curso_slug(args.curso)
        zip_target = Path(f"certificados_{slug}_{hoje_iso}.zip")

    print(f"[*] Iniciando emissão do lote para {len(audit_result.alunos)} alunos...")

    def progress_terminal(current: int, total: int, msg: str):
        if total > 0:
            pct = int((current / total) * 100)
            print(f"[{pct:3d}%] {msg}")
        else:
            print(f"[*] {msg}")

    result = emitir_lote_certificados(
        alunos=audit_result.alunos,
        curso=curso,
        config=render_config,
        manager=manager,
        zip_output_path_or_buffer=zip_target,
        progress_callback=progress_terminal,
    )

    reg_inicial = result.registros[0]
    reg_final = result.registros[-1]

    print("\n" + "=" * 70)
    print("CERTIFICADOS FUTURO FÁCIL - CERTIFICADOS EMITIDOS COM SUCESSO!")
    print("=" * 70)
    print(f"Curso: {curso.curso_nome}")
    print(f"Carga Horária: {curso.carga_horaria} horas ({reg_inicial.carga_horaria_extenso})")
    print(f"Total de Certificados Emitidos: {result.total_emitidos}")
    print("Assentos no Livro de Registro Digital:")
    print(f"  - Livro Nº: {reg_inicial.livro_numero}")
    print(f"  - Folhas: {reg_inicial.folha_numero:03d} a {reg_final.folha_numero:03d}")
    print(f"  - Registros: {reg_inicial.registro_numero:03d} a {reg_final.registro_numero:03d}")
    print(f"Pacote ZIP consolidado: {zip_target.resolve()}")
    print("=" * 70 + "\n")

    sys.exit(0)


if __name__ == "__main__":
    main()
