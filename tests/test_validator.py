import pytest
from core.validator import (
    clean_cpf,
    validate_cpf,
    format_cpf,
    mask_cpf,
    normalize_name,
    validar_aluno,
    ValidacaoAluno,
)


def test_clean_cpf():
    assert clean_cpf("123.456.789-01") == "12345678901"
    assert clean_cpf("  123 456 789 01 ") == "12345678901"
    assert clean_cpf("abc-123.456") == "123456"
    assert clean_cpf("") == ""
    assert clean_cpf(None) == ""


def test_validate_cpf_valid():
    # Valid CPFs with correct verification digits
    assert validate_cpf("52998224725") is True
    assert validate_cpf("529.982.247-25") is True
    assert validate_cpf("11144477735") is True
    assert validate_cpf("111.444.777-35") is True
    # Valid CPF starting with zero
    assert validate_cpf("01234567890") is True
    assert validate_cpf("012.345.678-90") is True


def test_validate_cpf_invalid_repeated_digits():
    # CPFs with all identical digits are mathematically invalid in Brazil
    for digit in range(10):
        assert validate_cpf(str(digit) * 11) is False
        assert validate_cpf(f"{digit*3}.{digit*3}.{digit*3}-{digit*2}") is False


def test_validate_cpf_invalid_length_and_characters():
    assert validate_cpf("1234567890") is False  # 10 digits
    assert validate_cpf("123456789012") is False  # 12 digits
    assert validate_cpf("abcdefghijk") is False
    assert validate_cpf("") is False
    assert validate_cpf(None) is False


def test_validate_cpf_invalid_checksum():
    # 52998224725 is valid, let's mutate digits
    assert validate_cpf("52998224726") is False
    assert validate_cpf("52998224715") is False
    assert validate_cpf("11144477736") is False


def test_format_cpf():
    assert format_cpf("52998224725") == "529.982.247-25"
    assert format_cpf("529.982.247-25") == "529.982.247-25"


def test_mask_cpf():
    assert mask_cpf("52998224725") == "***.982.247-**"
    assert mask_cpf("529.982.247-25") == "***.982.247-**"


def test_normalize_name():
    assert normalize_name("MARIA DA SILVA") == "Maria da Silva"
    assert normalize_name("joão dos santos") == "João dos Santos"
    assert normalize_name("pedro de alcântara e silva") == "Pedro de Alcântara e Silva"
    assert normalize_name("  ANA   PAULA   DO   NASCIMENTO  ") == "Ana Paula do Nascimento"
    assert normalize_name("LUCAS DAS DORES") == "Lucas das Dores"
    assert normalize_name("CARLOS EDUARDO") == "Carlos Eduardo"
    assert normalize_name("DE OLIVEIRA SANTOS") == "De Oliveira Santos"
    assert normalize_name("ÉRICA DE SOUZA") == "Érica de Souza"
    assert normalize_name("") == ""
    assert normalize_name(None) == ""


def test_validar_aluno_success():
    result = validar_aluno("MARIA DA SILVA", "529.982.247-25")
    assert isinstance(result, ValidacaoAluno)
    assert result.is_valido is True
    assert result.nome == "Maria da Silva"
    assert result.cpf == "52998224725"
    assert result.cpf_formatado == "529.982.247-25"
    assert result.cpf_mascarado == "***.982.247-**"
    assert result.erros == []


def test_validar_aluno_failure():
    result = validar_aluno("   ", "123.456.789-00")
    assert result.is_valido is False
    assert len(result.erros) >= 2
    assert any("Nome" in err for err in result.erros)
    assert any("CPF" in err for err in result.erros)


def test_validar_aluno_sem_cpf_aceito_por_padrao():
    result = validar_aluno("Lucas Silva", "")
    assert result.is_valido is True
    assert result.nome == "Lucas Silva"
    assert result.cpf == ""
    assert result.cpf_formatado == ""
    assert result.cpf_mascarado == ""
    assert result.erros == []


def test_validar_aluno_sem_cpf_quando_obrigatorio():
    result = validar_aluno("Lucas Silva", "", cpf_obrigatorio=True)
    assert result.is_valido is False
    assert "CPF do aluno é obrigatório." in result.erros


def test_validar_aluno_frequencia_minima():
    """Testa regra de frequência mínima de 75%."""
    # 75% exato -> aprovado
    res_75 = validar_aluno("Lucas Silva", "529.982.247-25", frequencia=75)
    assert res_75.is_valido is True
    assert res_75.frequencia == 75

    # 74% -> reprovado
    res_74 = validar_aluno("Lucas Silva", "529.982.247-25", frequencia=74)
    assert res_74.is_valido is False
    assert any("75%" in err for err in res_74.erros)
    assert res_74.frequencia == 74

    # 100% -> aprovado
    res_100 = validar_aluno("Lucas Silva", "529.982.247-25", frequencia=100)
    assert res_100.is_valido is True
    assert res_100.frequencia == 100


def test_validar_aluno_nome_incompleto():
    """Testa rejeição de nomes simples/monônimos sem sobrenome."""
    res_simples = validar_aluno("Carlos", "529.982.247-25")
    assert res_simples.is_valido is False
    assert any("nome e sobrenome" in err.lower() for err in res_simples.erros)

    res_valido = validar_aluno("Carlos Eduardo", "529.982.247-25")
    assert res_valido.is_valido is True
    assert res_valido.nome == "Carlos Eduardo"


def test_clean_cnpj():
    from core.validator import clean_cnpj
    assert clean_cnpj("11.222.333/0001-81") == "11222333000181"
    assert clean_cnpj(" 11 222 333 / 0001 - 81 ") == "11222333000181"
    assert clean_cnpj("") == ""
    assert clean_cnpj(None) == ""


def test_validate_cnpj():
    from core.validator import validate_cnpj
    # CNPJ válido
    assert validate_cnpj("11.222.333/0001-81") is True
    assert validate_cnpj("11222333000181") is True

    # CNPJs com todos os dígitos iguais são inválidos
    for d in range(10):
        assert validate_cnpj(str(d) * 14) is False

    # Tamanho inválido
    assert validate_cnpj("1122233300018") is False
    assert validate_cnpj("112223330001811") is False
    assert validate_cnpj("") is False
    assert validate_cnpj(None) is False

    # Dígitos verificadores incorretos
    assert validate_cnpj("11.222.333/0001-82") is False
    assert validate_cnpj("11.222.333/0001-91") is False


def test_format_cnpj():
    from core.validator import format_cnpj
    assert format_cnpj("11222333000181") == "11.222.333/0001-81"
    assert format_cnpj("11.222.333/0001-81") == "11.222.333/0001-81"
    assert format_cnpj("123") == "123"

