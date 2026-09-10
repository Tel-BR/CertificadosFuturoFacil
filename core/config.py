"""Gerenciamento e persistência das configurações institucionais e dados da empresa."""

from dataclasses import asdict, dataclass, field
import json
import os
from pathlib import Path
from typing import Any, Dict, List, Optional, Union

from core.validator import clean_cnpj, format_cnpj, validate_cnpj


CONFIG_DIR = Path(__file__).resolve().parent.parent / "config"
DEFAULT_CONFIG_PATH = CONFIG_DIR / "instituicao.json"


@dataclass
class InstituicaoConfig:
    """Configurações institucionais permanentes da entidade emissora de certificados."""

    razao_social: str = "Futuro Fácil Capacitação Digital"
    nome_fantasia: str = "Futuro Fácil"
    cnpj: str = "11.222.333/0001-81"
    cidade_padrao: str = "Goiânia"
    uf_padrao: str = "GO"
    instrutor_padrao: str = "Tel Santana Leite"
    tagline: str = "CAPACITAÇÃO DIGITAL SOB MEDIDA"
    primary_color: str = "#0E7490"  # Petróleo Tech
    secondary_color: str = "#EA580C"  # Coral Solar
    initial_livro: int = 1
    initial_folha: int = 1
    initial_registro: int = 1
    validation_base_url: str = "https://certificados-futurofacil.streamlit.app/?validar="
    logo_path: Optional[str] = None
    signature_path: Optional[str] = "assets/assinatura.png"

    def to_dict(self) -> Dict[str, Any]:
        """Serializa os parâmetros institucionais para dicionário."""
        return asdict(self)

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "InstituicaoConfig":
        """Instancia as configurações a partir de um dicionário."""
        valid_keys = {f.name for f in cls.__dataclass_fields__.values()}
        filtered = {k: v for k, v in data.items() if k in valid_keys}
        return cls(**filtered)


def get_default_config_path() -> Path:
    """Retorna o caminho padrão do arquivo JSON de configuração institucional."""
    return DEFAULT_CONFIG_PATH


def load_instituicao_config(path: Optional[Union[str, Path]] = None) -> InstituicaoConfig:
    """
    Carrega as configurações institucionais salvas do arquivo JSON.
    Se o arquivo não existir ou for inválido, retorna a configuração padrão.
    """
    config_path = Path(path) if path is not None else get_default_config_path()

    if not config_path.exists():
        return InstituicaoConfig()

    try:
        data = json.loads(config_path.read_text(encoding="utf-8"))
        return InstituicaoConfig.from_dict(data)
    except Exception:
        return InstituicaoConfig()


def save_instituicao_config(
    config: InstituicaoConfig, path: Optional[Union[str, Path]] = None
) -> Path:
    """
    Persiste as configurações institucionais no arquivo JSON de destino,
    criando os diretórios pais se necessário.
    """
    config_path = Path(path) if path is not None else get_default_config_path()
    config_path.parent.mkdir(parents=True, exist_ok=True)

    config_path.write_text(
        json.dumps(config.to_dict(), indent=2, ensure_ascii=False),
        encoding="utf-8",
    )
    return config_path


def validate_instituicao_config(config: InstituicaoConfig) -> List[str]:
    """
    Valida os dados cadastrais da instituição.
    Retorna uma lista de mensagens de erro caso haja inconsistências.
    """
    erros: List[str] = []

    if not config.razao_social or not config.razao_social.strip():
        erros.append("Razão Social da instituição é obrigatória.")

    if not config.nome_fantasia or not config.nome_fantasia.strip():
        erros.append("Nome Fantasia da instituição é obrigatório.")

    if not config.cnpj or not config.cnpj.strip():
        erros.append("CNPJ da instituição é obrigatório.")
    elif not validate_cnpj(config.cnpj):
        erros.append("CNPJ da instituição é inválido ou possui dígitos verificadores incorretos.")

    if not config.cidade_padrao or not config.cidade_padrao.strip():
        erros.append("Cidade padrão da instituição é obrigatória.")

    if not config.instrutor_padrao or not config.instrutor_padrao.strip():
        erros.append("Instrutor padrão responsável é obrigatório.")

    if config.initial_livro < 1:
        erros.append("Número inicial do Livro deve ser maior ou igual a 1.")

    if config.initial_folha < 1:
        erros.append("Número inicial da Folha deve ser maior ou igual a 1.")

    if config.initial_registro < 1:
        erros.append("Número inicial do Registro deve ser maior ou igual a 1.")

    return erros
