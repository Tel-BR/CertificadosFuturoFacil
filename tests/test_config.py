"""Testes do módulo de configurações institucionais e dados da empresa."""

import json
from pathlib import Path
import pytest

from core.config import (
    InstituicaoConfig,
    load_instituicao_config,
    save_instituicao_config,
    validate_instituicao_config,
)


def test_instituicao_config_defaults():
    cfg = InstituicaoConfig()
    assert cfg.nome_fantasia == "Futuro Fácil"
    assert cfg.primary_color == "#0E7490"
    assert cfg.secondary_color == "#EA580C"
    assert cfg.initial_livro == 1
    assert cfg.initial_folha == 1
    assert cfg.initial_registro == 1


def test_save_and_load_instituicao_config(tmp_path):
    config_file = tmp_path / "config" / "instituicao.json"
    cfg = InstituicaoConfig(
        razao_social="Futuro Fácil Treinamentos LTDA",
        nome_fantasia="Futuro Fácil",
        cnpj="11.222.333/0001-81",
        cidade_padrao="Anápolis",
        uf_padrao="GO",
        instrutor_padrao="Tel Santana",
        initial_livro=2,
        initial_folha=15,
        initial_registro=115,
    )

    saved_path = save_instituicao_config(cfg, config_file)
    assert saved_path.exists()

    loaded = load_instituicao_config(config_file)
    assert loaded.razao_social == "Futuro Fácil Treinamentos LTDA"
    assert loaded.cidade_padrao == "Anápolis"
    assert loaded.initial_livro == 2
    assert loaded.initial_folha == 15
    assert loaded.initial_registro == 115


def test_load_instituicao_config_fallback_nonexistent(tmp_path):
    missing_file = tmp_path / "inexistente.json"
    cfg = load_instituicao_config(missing_file)
    assert isinstance(cfg, InstituicaoConfig)
    assert cfg.nome_fantasia == "Futuro Fácil"


def test_validate_instituicao_config():
    # Válido
    cfg_valido = InstituicaoConfig(
        razao_social="Empresa Modelo ME",
        cnpj="11.222.333/0001-81",
        cidade_padrao="Goiânia",
        instrutor_padrao="Professor Silva",
    )
    erros = validate_instituicao_config(cfg_valido)
    assert erros == []

    # CNPJ inválido e Razão Social vazia
    cfg_invalido = InstituicaoConfig(
        razao_social="   ",
        cnpj="11.222.333/0001-99",
    )
    erros = validate_instituicao_config(cfg_invalido)
    assert any("Razão Social" in e for e in erros)
    assert any("CNPJ" in e for e in erros)
