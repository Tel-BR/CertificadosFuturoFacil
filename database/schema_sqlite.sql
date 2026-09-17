-- =============================================================================
-- Schema SQLite para Testes e Validações Locais
-- Compatível com SQLite 3 via PDO SQLite
-- =============================================================================

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS turmas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    codigo_turma TEXT NOT NULL UNIQUE,
    curso_nome TEXT NOT NULL,
    cliente_nome TEXT NULL,
    ordem_servico TEXT NULL,
    modalidade TEXT NOT NULL DEFAULT 'Presencial',
    cliente_tipo TEXT NOT NULL DEFAULT 'PJ',
    cliente_cidade TEXT NULL,
    cliente_uf TEXT NULL,
    cliente_cnpj TEXT NULL,
    tipo_cobranca TEXT NOT NULL DEFAULT 'hora_aula',
    valor_hora_aula REAL NOT NULL DEFAULT 0.00,
    valor_total REAL NOT NULL DEFAULT 0.00,
    carga_horaria INTEGER NOT NULL DEFAULT 0,
    carga_horaria_extenso TEXT NULL,
    data_inicio TEXT NOT NULL,
    data_conclusao TEXT NOT NULL,
    turno_padrao TEXT NOT NULL DEFAULT 'V',
    status TEXT NOT NULL DEFAULT 'prevista',
    chave_acesso TEXT NOT NULL UNIQUE,
    portal_certificados_modo TEXT NOT NULL DEFAULT 'nenhum',
    instrutor TEXT NULL,
    cidade TEXT NULL,
    ementa TEXT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS encontros (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    turma_id INTEGER NOT NULL,
    numero_encontro INTEGER NOT NULL,
    data_encontro TEXT NOT NULL,
    turno TEXT NOT NULL DEFAULT 'V',
    horario_inicio TEXT NULL,
    horario_fim TEXT NULL,
    conteudo_previsto TEXT NULL,
    conteudo_ministrado TEXT NULL,
    tipo TEXT NOT NULL DEFAULT 'aula',
    abonado INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (turma_id) REFERENCES turmas (id) ON DELETE CASCADE,
    UNIQUE (turma_id, numero_encontro)
);

CREATE TABLE IF NOT EXISTS alunos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    turma_id INTEGER NOT NULL,
    nome_completo TEXT NOT NULL,
    cpf TEXT NULL,
    cpf_limpo TEXT NULL,
    cpf_mascarado TEXT NULL,
    justificado INTEGER NOT NULL DEFAULT 0,
    justificativa_texto TEXT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (turma_id) REFERENCES turmas (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS frequencias (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    encontro_id INTEGER NOT NULL,
    aluno_id INTEGER NOT NULL,
    presente INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (encontro_id) REFERENCES encontros (id) ON DELETE CASCADE,
    FOREIGN KEY (aluno_id) REFERENCES alunos (id) ON DELETE CASCADE,
    UNIQUE (encontro_id, aluno_id)
);

CREATE TABLE IF NOT EXISTS materiais_turma (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    turma_id INTEGER NOT NULL,
    titulo TEXT NOT NULL,
    descricao TEXT NULL,
    tipo TEXT NOT NULL DEFAULT 'apostila',
    caminho_arquivo TEXT NULL,
    url_externa TEXT NULL,
    tamanho_bytes INTEGER NULL,
    ordem INTEGER NOT NULL DEFAULT 0,
    ativo INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (turma_id) REFERENCES turmas (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS registros_certificados (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    turma_id INTEGER NULL,
    codigo_autenticidade TEXT NOT NULL UNIQUE,
    aluno_nome TEXT NOT NULL,
    aluno_cpf TEXT NULL,
    aluno_cpf_mascarado TEXT NULL,
    curso_nome TEXT NOT NULL,
    carga_horaria INTEGER NOT NULL,
    carga_horaria_extenso TEXT NULL,
    data_inicio TEXT NULL,
    data_conclusao TEXT NOT NULL,
    data_emissao TEXT NOT NULL,
    modalidade TEXT NOT NULL DEFAULT 'Presencial',
    instrutor TEXT NULL,
    cidade TEXT NULL,
    ementa TEXT NULL,
    livro_numero INTEGER NOT NULL,
    folha_numero INTEGER NOT NULL,
    registro_numero INTEGER NOT NULL,
    frequencia INTEGER NOT NULL DEFAULT 100,
    lote_id TEXT NULL,
    presencas_detalhadas TEXT NULL,
    horas_presentes INTEGER NULL,
    horas_totais INTEGER NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (turma_id) REFERENCES turmas (id) ON DELETE SET NULL,
    UNIQUE (livro_numero, folha_numero, registro_numero)
);

CREATE INDEX IF NOT EXISTS idx_reg_codigo ON registros_certificados(codigo_autenticidade);

CREATE TABLE IF NOT EXISTS usuarios_admin (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    nome TEXT NOT NULL,
    email TEXT NOT NULL,
    ultimo_login TEXT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS tentativas_login (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_address TEXT NOT NULL,
    username TEXT NOT NULL,
    tentativas INTEGER NOT NULL DEFAULT 1,
    bloqueado_ate TEXT NULL,
    ultimo_erro TEXT NOT NULL DEFAULT (datetime('now')),
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_tentativas_ip_user ON tentativas_login(ip_address, username);

CREATE TABLE IF NOT EXISTS bloqueios_agenda (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    data TEXT NOT NULL,
    descricao TEXT NOT NULL,
    tipo TEXT NOT NULL DEFAULT 'feriado_nacional',
    bloqueante INTEGER NOT NULL DEFAULT 1,
    permite_excecao INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_bloqueios_data ON bloqueios_agenda(data);
CREATE INDEX IF NOT EXISTS idx_bloqueios_tipo ON bloqueios_agenda(tipo);

