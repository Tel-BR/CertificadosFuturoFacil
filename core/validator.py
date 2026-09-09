"""Validação matemática e higienização de dados de alunos e CPFs."""

import re
from dataclasses import dataclass, field
from typing import List, Optional, Sequence

PREPOSICOES = {"de", "da", "do", "dos", "das", "e"}


@dataclass
class ValidacaoAluno:
    """Resultado da validação e higienização de dados de um Aluno."""

    nome: str
    cpf: str
    cpf_formatado: str
    cpf_mascarado: str
    is_valido: bool
    erros: List[str] = field(default_factory=list)

    # Aliases de conveniência para compatibilidade com inglês
    @property
    def name(self) -> str:
        return self.nome

    @property
    def formatted_cpf(self) -> str:
        return self.cpf_formatado

    @property
    def masked_cpf(self) -> str:
        return self.cpf_mascarado

    @property
    def is_valid(self) -> bool:
        return self.is_valido

    @property
    def errors(self) -> List[str]:
        return self.erros


# Alias de domínio
StudentValidation = ValidacaoAluno


def clean_cpf(cpf: Optional[str]) -> str:
    """Remove caracteres não numéricos do CPF."""
    if not cpf:
        return ""
    return re.sub(r"\D", "", str(cpf))


def _calcular_digito_verificador(digitos: Sequence[int], pesos: Sequence[int]) -> int:
    """Calcula dígito verificador segundo o algoritmo módulo 11."""
    soma = sum(d * p for d, p in zip(digitos, pesos))
    resto = soma % 11
    return 0 if resto < 2 else 11 - resto


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

    digitos = [int(c) for c in cleaned]

    # Primeiro dígito verificador (pesos 10 a 2)
    digito_1 = _calcular_digito_verificador(digitos[:9], range(10, 1, -1))
    if digitos[9] != digito_1:
        return False

    # Segundo dígito verificador (pesos 11 a 2)
    digito_2 = _calcular_digito_verificador(digitos[:10], range(11, 1, -1))
    if digitos[10] != digito_2:
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
    Normaliza o nome do Aluno em Title Case preservando
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
        if i > 0 and lower_word in PREPOSICOES:
            normalized_words.append(lower_word)
        else:
            normalized_words.append(lower_word.capitalize())

    return " ".join(normalized_words)


def validar_aluno(
    nome: Optional[str],
    cpf: Optional[str],
    cpf_obrigatorio: bool = False,
) -> ValidacaoAluno:
    """
    Valida e normaliza os dados de um Aluno.
    Quando cpf_obrigatorio=False (padrão flexível), aceita CPF em branco/vazio.
    Se o CPF for fornecido, a validação matemática de 11 dígitos continua obrigatória.
    Retorna uma instância de ValidacaoAluno com status e mensagens de erro.
    """
    erros: List[str] = []

    nome_norm = normalize_name(nome)
    if not nome_norm:
        erros.append("Nome do aluno não pode ser vazio.")

    cpf_limpo = clean_cpf(cpf)

    if not cpf_limpo:
        if cpf_obrigatorio:
            erros.append("CPF do aluno é obrigatório.")
        formatado = ""
        mascarado = ""
    else:
        cpf_valido = validate_cpf(cpf_limpo)
        if not cpf_valido:
            erros.append("CPF inválido ou com dígitos verificadores incorretos.")
        formatado = format_cpf(cpf_limpo) if len(cpf_limpo) == 11 else cpf_limpo
        mascarado = mask_cpf(cpf_limpo) if len(cpf_limpo) == 11 else cpf_limpo

    return ValidacaoAluno(
        nome=nome_norm,
        cpf=cpf_limpo,
        cpf_formatado=formatado,
        cpf_mascarado=mascarado,
        is_valido=len(erros) == 0,
        erros=erros,
    )


# Alias para compatibilidade
validate_student = validar_aluno
