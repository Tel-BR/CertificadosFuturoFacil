import pytest
from core.validator import (
    clean_cpf,
    validate_cpf,
    format_cpf,
    mask_cpf,
    normalize_name,
    validate_student,
    StudentValidation,
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



def test_validate_student_success():
    result = validate_student("MARIA DA SILVA", "529.982.247-25")
    assert isinstance(result, StudentValidation)
    assert result.is_valid is True
    assert result.name == "Maria da Silva"
    assert result.cpf == "52998224725"
    assert result.formatted_cpf == "529.982.247-25"
    assert result.masked_cpf == "***.982.247-**"
    assert result.errors == []


def test_validate_student_failure():
    result = validate_student("   ", "123.456.789-00")
    assert result.is_valid is False
    assert len(result.errors) >= 2
    assert any("Nome" in err for err in result.errors)
    assert any("CPF" in err for err in result.errors)
