"""Testes unitários e de integração para o serviço de validação pública e QR Code."""

import io
from pathlib import Path
from PIL import Image
import pytest

from core.registry import CertificadoRegistro, CursoMetadata, LivroRegistroManager
from core.validator import ValidacaoAluno
from core.validator_service import (
    DEFAULT_VALIDATION_BASE_URL,
    ResultadoValidacaoPublica,
    ValidadorPublicoService,
    build_validation_url,
    generate_qr_code_bytes,
    generate_qr_code_image,
    validar_certificado,
)


def test_build_validation_url():
    """Valida a construção robusta da URL de validação evitando barras ou parâmetros duplicados."""
    code = "A1B2C3D4E5F6"

    # Caso padrão com barra e ?validar=
    url1 = build_validation_url("https://meusite.com/?validar=", code)
    assert url1 == f"https://meusite.com/?validar={code}"

    # Caso base sem barra final e sem validar
    url2 = build_validation_url("https://meusite.com", code)
    assert url2 == f"https://meusite.com/?validar={code}"

    # Caso base com barra final sem query string
    url3 = build_validation_url("https://meusite.com/", code)
    assert url3 == f"https://meusite.com/?validar={code}"

    # Caso base com outro parâmetro existente
    url4 = build_validation_url("https://meusite.com/portal?app=certificados", code)
    assert url4 == f"https://meusite.com/portal?app=certificados&validar={code}"

    # Caso padrão padrão da aplicação
    url_default = build_validation_url(DEFAULT_VALIDATION_BASE_URL, code)
    assert url_default == f"{DEFAULT_VALIDATION_BASE_URL}{code}"


def test_generate_qr_code_image_and_bytes():
    """Valida a geração de QR Code em imagem PIL e bytes PNG válidos."""
    code = "E3B0C44298FC1C149AFBF4C8996FB92427AE41E4649B934CA495991B7852B855"

    # Imagem PIL
    img = generate_qr_code_image(code)
    assert isinstance(img, Image.Image)
    assert img.size[0] > 50 and img.size[1] > 50

    # Bytes PNG
    png_bytes = generate_qr_code_bytes(code)
    assert isinstance(png_bytes, bytes)
    assert len(png_bytes) > 100
    # Verifica assinatura mágica PNG: \x89PNG\r\n\x1a\n
    assert png_bytes.startswith(b"\x89PNG\r\n\x1a\n")


def test_validar_codigo_autentico(tmp_path: Path):
    """Valida consulta de certificado autêntico com garantia de mascaramento LGPD de CPF."""
    db_file = tmp_path / "test_registros.db"
    manager = LivroRegistroManager(db_url_or_path=str(db_file))

    curso = CursoMetadata(
        curso_nome="Python para Ciência de Dados",
        carga_horaria=40,
        data_conclusao="09/09/2026",
        data_inicio="01/09/2026",
        data_emissao="09/09/2026",
        modalidade="Curso Livre de Capacitação Profissional",
        instrutor="Prof. Alan Turing",
        cidade="São Paulo - SP",
        ementa="Módulo 1: Pandas e NumPy.\nMódulo 2: Estatística descritiva.",
    )

    aluno = ValidacaoAluno(
        nome="MARIA SILVA SANTOS",
        cpf="12345678909",
        cpf_formatado="123.456.789-09",
        cpf_mascarado="***.456.789-**",
        is_valido=True,
    )

    registro = manager.register_certificate(aluno, curso)
    codigo = registro.codigo_autenticidade

    service = ValidadorPublicoService(registry_manager=manager)
    resultado = service.validar_codigo(codigo)

    assert isinstance(resultado, ResultadoValidacaoPublica)
    assert resultado.autentico is True
    assert "autêntico" in resultado.mensagem.lower()
    assert resultado.aluno_nome == "Maria Silva Santos"
    # Garantia estrita de LGPD: CPF público deve estar mascarado e nunca exposto sem máscara
    assert resultado.aluno_cpf_mascarado == "***.456.789-**"
    assert resultado.curso_nome == "Python para Ciência de Dados"
    assert resultado.carga_horaria == 40
    assert resultado.carga_horaria_extenso == "quarenta horas"
    assert resultado.livro_numero == 1
    assert resultado.folha_numero == 1
    assert resultado.registro_numero == 1
    assert resultado.instrutor == "Prof. Alan Turing"
    assert resultado.cidade == "São Paulo - SP"
    assert "Pandas e NumPy" in resultado.ementa
    assert resultado.frequencia == 100

    # Teste de serialização segura to_dict()
    data_dict = resultado.to_dict()
    assert data_dict["autentico"] is True
    assert data_dict["aluno_cpf_mascarado"] == "***.456.789-**"
    assert data_dict["frequencia"] == 100
    # Certificar ausência total de chaves com CPF cru ou download de arquivo
    assert "aluno_cpf" not in data_dict
    assert "cpf" not in data_dict
    assert "pdf_url" not in data_dict
    assert "pdf_bytes" not in data_dict


def test_validar_codigo_aluno_sem_cpf(tmp_path: Path):
    """Valida consulta de certificado de aluno que não informou CPF."""
    db_file = tmp_path / "test_sem_cpf.db"
    manager = LivroRegistroManager(db_url_or_path=str(db_file))

    curso = CursoMetadata(
        curso_nome="Excel Básico",
        carga_horaria=8,
        data_conclusao="05/09/2026",
    )

    aluno = ValidacaoAluno(
        nome="JOÃO DE SOUZA",
        cpf="",
        cpf_formatado="",
        cpf_mascarado="",
        is_valido=True,
    )

    registro = manager.register_certificate(aluno, curso)
    resultado = validar_certificado(registro.codigo_autenticidade, manager=manager)

    assert resultado.autentico is True
    assert resultado.aluno_nome == "João de Souza"
    assert resultado.aluno_cpf_mascarado in ("Não informado", "")


def test_validar_codigo_inexistente(tmp_path: Path):
    """Valida rejeição segura de código de autenticidade inexistente (mas com 64 chars hexadecimais válidos)."""
    db_file = tmp_path / "test_empty.db"
    service = ValidadorPublicoService(db_url_or_path=str(db_file))

    codigo_falso = "0" * 64
    resultado = service.validar_codigo(codigo_falso)

    assert resultado.autentico is False
    assert resultado.codigo_autenticidade == codigo_falso
    assert "não registrado" in resultado.mensagem.lower() or "não localizado" in resultado.mensagem.lower()
    assert resultado.aluno_nome is None
    assert resultado.aluno_cpf_mascarado is None


def test_validar_codigo_adulterado_ou_formato_invalido(tmp_path: Path):
    """Valida identificação clara de código com adulteração estrutural ou formato inválido."""
    db_file = tmp_path / "test_tampered.db"
    service = ValidadorPublicoService(db_url_or_path=str(db_file))

    # Tamanho truncado (código adulterado)
    codigo_truncado = "E3B0C44298FC1C149AFBF4C8996FB92427AE"
    resultado = service.validar_codigo(codigo_truncado)
    assert resultado.autentico is False
    assert "inválido" in resultado.mensagem.lower() or "adulterado" in resultado.mensagem.lower()

    # Caracteres não hexadecimais (código corrompido)
    codigo_corrompido = "Z" * 64
    resultado_corrompido = service.validar_codigo(codigo_corrompido)
    assert resultado_corrompido.autentico is False
    assert "inválido" in resultado_corrompido.mensagem.lower() or "adulterado" in resultado_corrompido.mensagem.lower()


def test_validar_codigo_vazio_ou_invalido(tmp_path: Path):
    """Valida tratamento seguro para entradas vazias, nulas ou em branco."""
    service = ValidadorPublicoService(db_url_or_path=str(tmp_path / "empty.db"))

    for entrada in ["", "   ", None]:
        resultado = service.validar_codigo(entrada)
        assert resultado.autentico is False
        assert "não informado" in resultado.mensagem.lower() or "inválido" in resultado.mensagem.lower()


def test_mascaramento_lgpd_resiliente_com_cpf_nao_mascarado_no_banco():
    """Valida proteção estrita LGPD caso o assento contenha CPF formatado sem máscara ou digitos puros."""
    registro_desmascarado = CertificadoRegistro(
        id=1,
        codigo_autenticidade="A" * 64,
        aluno_nome="teste da silva",
        aluno_cpf="12345678901",
        # Simula caso em que o banco possuía CPF desmascarado no campo mascarado
        aluno_cpf_mascarado="123.456.789-01",
        curso_nome="Curso Teste",
        carga_horaria=10,
        carga_horaria_extenso="dez horas",
        data_inicio=None,
        data_conclusao="01/01/2026",
        data_emissao="01/01/2026",
        modalidade="Curso Livre",
        instrutor="Instrutor",
        cidade="Cidade",
        ementa="Ementa",
        livro_numero=1,
        folha_numero=1,
        registro_numero=1,
    )

    resultado = ResultadoValidacaoPublica.from_registro(registro_desmascarado)
    assert resultado.aluno_cpf_mascarado == "***.456.789-**"
    assert "123" not in resultado.aluno_cpf_mascarado[:3]
    assert resultado.to_dict()["aluno_cpf_mascarado"] == "***.456.789-**"


def test_core_package_exports():
    """Valida que todos os componentes do validador são devidamente exportados pelo pacote core."""
    import core

    assert hasattr(core, "ResultadoValidacaoPublica")
    assert hasattr(core, "ValidadorPublicoService")
    assert hasattr(core, "validar_certificado")
    assert hasattr(core, "build_validation_url")
    assert hasattr(core, "generate_qr_code_image")
    assert hasattr(core, "generate_qr_code_bytes")
    assert hasattr(core, "DEFAULT_VALIDATION_BASE_URL")


def test_validar_codigo_espacos_e_minusculas(tmp_path: Path):
    """Valida que a busca tolera espaços em branco e letras minúsculas no código."""
    db_file = tmp_path / "test_spaces.db"
    manager = LivroRegistroManager(db_url_or_path=str(db_file))

    curso = CursoMetadata(
        curso_nome="Gestão Financeira",
        carga_horaria=20,
        data_conclusao="01/09/2026",
    )
    aluno = ValidacaoAluno(
        nome="CARLOS PEREIRA",
        cpf="12345678909",
        cpf_formatado="123.456.789-09",
        cpf_mascarado="***.456.789-**",
        is_valido=True,
    )
    registro = manager.register_certificate(aluno, curso)
    codigo = registro.codigo_autenticidade

    # Passa com espaços nas pontas e em minúsculas
    codigo_input = f"  {codigo.lower()}  "
    service = ValidadorPublicoService(registry_manager=manager)
    resultado = service.validar_codigo(codigo_input)

    assert resultado.autentico is True
    assert resultado.codigo_autenticidade == codigo.upper()
    assert resultado.aluno_nome == "Carlos Pereira"
