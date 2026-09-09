"""Validação matemática e higienização de dados de alunos e CPFs."""

import re
from dataclasses import dataclass, field
from typing import List, Optional

PREPOSITIONS = {"de", "da", "do", "dos", "das", "e"}


@dataclass
class StudentValidation:
    """Resultado da validação e higienização de um aluno."""

    name: str
    cpf: str
    formatted_cpf: str
    masked_cpf: str
    is_valid: bool
    errors: List[str] = field(default_factory=list)


def clean_cpf(cpf: Optional[str]) -> str:
    """Remove caracteres não numéricos do CPF."""
    if not cpf:
        return ""
    return re.sub(r"\D", "", str(cpf))


def validate_cpf(cpf: Optional[str]) -> bool:
    """
    Valida matematicamente um CPF brasileiro segundo o algoritmo módulo 11.
    Rejeita tamanhos incorretos, caracteres inválidos e sequências de dígitos repetidos.
    """
    cleaned = clean_cpf(cpf)

    if len(cleaned) != 11:
        return False

    # Rejeita sequências com todos os dígitos idênticos (ex: 11111111111)
    if cleaned == cleaned[0] * 11:
        return False

    # Validação do primeiro dígito verificador
    soma_1 = sum(int(cleaned[i]) * (10 - i) for i in range(9))
    resto_1 = soma_1 % 11
    digito_1 = 0 if resto_1 < 2 else 11 - resto_1

    if int(cleaned[9]) != digito_1:
        return False

    # Validação do segundo dígito verificador
    soma_2 = sum(int(cleaned[i]) * (11 - i) for i in range(10))
    resto_2 = soma_2 % 11
    digito_2 = 0 if resto_2 < 2 else 11 - resto_2

    if int(cleaned[10]) != digito_2:
        return False

    return True


def format_cpf(cpf: Optional[str]) -> str:
    """Formata CPF no padrão 000.000.000-00."""
    cleaned = clean_cpf(cpf)
    if len(cleaned) == 11:
        return f"{cleaned[:3]}.{cleaned[3:6]}.{cleaned[6:9]}-{cleaned[9:]}"
    return cleaned


def mask_cpf(cpf: Optional[str]) -> str:
    """Aplica máscara LGPD no CPF (***.000.000-**)."""
    cleaned = clean_cpf(cpf)
    if len(cleaned) == 11:
        return f"***.{cleaned[3:6]}.{cleaned[6:9]}-**"
    return cleaned


def normalize_name(name: Optional[str]) -> str:
    """
    Normaliza o nome do aluno em Title Case preservando
    preposições em minúsculas ('de', 'da', 'do', 'dos', 'das', 'e').
    """
    if not name:
        return ""

    words = str(name).strip().split()
    if not words:
        return ""

    normalized_words = []
    for i, word in enumerate(words):
        lower_word = word.lower()
        if i > 0 and lower_word in PREPOSITIONS:
            normalized_words.append(lower_word)
        else:
            normalized_words.append(lower_word.capitalize())

    return " ".join(normalized_words)


def validate_student(name: Optional[str], cpf: Optional[str]) -> StudentValidation:
    """
    Valida e normaliza os dados de um aluno.
    Retorna uma instância de StudentValidation com status e mensagens de erro.
    """
    errors: List[str] = []

    norm_name = normalize_name(name)
    if not norm_name:
        errors.append("Nome do aluno não pode ser vazio.")

    cleaned_cpf = clean_cpf(cpf)
    cpf_valid = validate_cpf(cleaned_cpf)
    if not cpf_valid:
        errors.append("CPF inválido ou com dígitos verificadores incorretos.")

    formatted = format_cpf(cleaned_cpf) if len(cleaned_cpf) == 11 else cleaned_cpf
    masked = mask_cpf(cleaned_cpf) if len(cleaned_cpf) == 11 else cleaned_cpf

    return StudentValidation(
        name=norm_name,
        cpf=cleaned_cpf,
        formatted_cpf=formatted,
        masked_cpf=masked,
        is_valid=len(errors) == 0,
        errors=errors,
    )
