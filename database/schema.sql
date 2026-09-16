-- =============================================================================
-- Schema Relacional da Plataforma Integrada de Gestão Pedagógica, Diário e Certificados
-- Compatível com MariaDB 10.4+ / MySQL 8.0+
-- Charset: utf8mb4 | Collation: utf8mb4_unicode_ci | Engine: InnoDB
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 1. Tabela: turmas
-- Metadados da turma, controle de capacidade por turno, suporte a faturamento/NFS-e
-- e chave de acesso para proteção de conteúdos de alunos (ADR-0008)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `turmas` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `codigo_turma` VARCHAR(50) NOT NULL UNIQUE,
    `curso_nome` VARCHAR(255) NOT NULL,
    `cliente_nome` VARCHAR(255) NULL,
    `ordem_servico` VARCHAR(50) NULL COMMENT 'Identificador de OS, contrato ou ordem de faturamento',
    `modalidade` VARCHAR(50) NOT NULL DEFAULT 'Presencial' COMMENT 'Presencial, Ao Vivo Online, Híbrido',
    `cliente_tipo` VARCHAR(20) NOT NULL DEFAULT 'PJ' COMMENT 'PJ=Pessoa Jurídica, PF=Pessoa Física',
    `cliente_cidade` VARCHAR(100) NULL,
    `cliente_uf` VARCHAR(2) NULL,
    `cliente_cnpj` VARCHAR(20) NULL COMMENT 'CNPJ ou CPF do tomador para fins de NFS-e',
    `tipo_cobranca` VARCHAR(50) NOT NULL DEFAULT 'hora_aula' COMMENT 'hora_aula, valor_fechado, por_aluno, gratuito',
    `valor_hora_aula` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `valor_total` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `carga_horaria` INT UNSIGNED NOT NULL DEFAULT 0,
    `carga_horaria_extenso` VARCHAR(100) NULL,
    `data_inicio` DATE NOT NULL,
    `data_conclusao` DATE NOT NULL,
    `turno_padrao` ENUM('M', 'V', 'N', 'D') NOT NULL DEFAULT 'V' COMMENT 'M=Matutino, V=Vespertino, N=Noturno, D=Diurno/Integral',
    `status` ENUM('prevista', 'em_andamento', 'concluida', 'cancelada') NOT NULL DEFAULT 'prevista',
    `chave_acesso` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Chave alfanumérica única para acesso do aluno em /turmas',
    `instrutor` VARCHAR(255) NULL,
    `cidade` VARCHAR(100) NULL,
    `ementa` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_turmas_status` (`status`),
    INDEX `idx_turmas_datas` (`data_inicio`, `data_conclusao`),
    INDEX `idx_turmas_chave` (`chave_acesso`),
    INDEX `idx_turmas_os` (`ordem_servico`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. Tabela: encontros
-- Sessões de aula com horários, plano dinâmico e flag de abono coletivo
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `encontros` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `turma_id` INT UNSIGNED NOT NULL,
    `numero_encontro` INT UNSIGNED NOT NULL,
    `data_encontro` DATE NOT NULL,
    `turno` ENUM('M', 'V', 'N', 'D') NOT NULL DEFAULT 'V',
    `horario_inicio` TIME NULL,
    `horario_fim` TIME NULL,
    `conteudo_previsto` TEXT NULL,
    `conteudo_ministrado` TEXT NULL,
    `tipo` ENUM('aula', 'deslocamento') NOT NULL DEFAULT 'aula' COMMENT 'aula = Encontro pedagógico regular, deslocamento = Bloqueio de viagem logística',
    `abonado` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Aula abonada coletivamente para todos os alunos',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_encontros_turma` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_turma_encontro` (`turma_id`, `numero_encontro`),
    INDEX `idx_encontros_data` (`data_encontro`, `turno`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. Tabela: alunos
-- Cadastro de alunos vinculados à turma
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alunos` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `turma_id` INT UNSIGNED NOT NULL,
    `nome_completo` VARCHAR(255) NOT NULL,
    `cpf` VARCHAR(14) NULL COMMENT 'CPF formatado ou conforme fornecido',
    `cpf_limpo` VARCHAR(11) NULL COMMENT 'Apenas os 11 dígitos numéricos',
    `cpf_mascarado` VARCHAR(20) NULL COMMENT 'Formato LGPD: ***.XXX.XXX-**',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_alunos_turma` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`) ON DELETE CASCADE,
    INDEX `idx_alunos_turma` (`turma_id`),
    INDEX `idx_alunos_nome` (`nome_completo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. Tabela: frequencias
-- Presenças binárias por aluno e encontro
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `frequencias` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `encontro_id` INT UNSIGNED NOT NULL,
    `aluno_id` INT UNSIGNED NOT NULL,
    `presente` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Presente, 0 = Falta',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_frequencias_encontro` FOREIGN KEY (`encontro_id`) REFERENCES `encontros` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_frequencias_aluno` FOREIGN KEY (`aluno_id`) REFERENCES `alunos` (`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_encontro_aluno` (`encontro_id`, `aluno_id`),
    INDEX `idx_frequencias_aluno` (`aluno_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. Tabela: materiais_turma
-- Materiais didáticos protegidos por chave de acesso (ADR-0008)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `materiais_turma` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `turma_id` INT UNSIGNED NOT NULL,
    `titulo` VARCHAR(255) NOT NULL,
    `descricao` TEXT NULL,
    `tipo` ENUM('apostila', 'slide', 'exercicio', 'link', 'outro') NOT NULL DEFAULT 'apostila',
    `caminho_arquivo` VARCHAR(500) NULL COMMENT 'Caminho físico relativo no diretório protegido fora do alcance público',
    `url_externa` VARCHAR(500) NULL COMMENT 'URL externa opcional',
    `tamanho_bytes` BIGINT UNSIGNED NULL,
    `ordem` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_materiais_turma` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`) ON DELETE CASCADE,
    INDEX `idx_materiais_turma` (`turma_id`, `ordem`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. Tabela: registros_certificados
-- Livro de Registro Digital canônico e histórico perpétuo de emissões
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `registros_certificados` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `turma_id` INT UNSIGNED NULL COMMENT 'Vinculação opcional com turma geradora',
    `codigo_autenticidade` VARCHAR(64) NOT NULL UNIQUE COMMENT 'Hash SHA-256 hexadecimal de 64 caracteres',
    `aluno_nome` VARCHAR(255) NOT NULL,
    `aluno_cpf` VARCHAR(14) NULL,
    `aluno_cpf_mascarado` VARCHAR(20) NULL,
    `curso_nome` VARCHAR(255) NOT NULL,
    `carga_horaria` INT UNSIGNED NOT NULL,
    `carga_horaria_extenso` VARCHAR(100) NULL,
    `data_inicio` VARCHAR(50) NULL,
    `data_conclusao` VARCHAR(50) NOT NULL,
    `data_emissao` VARCHAR(50) NOT NULL,
    `modalidade` VARCHAR(50) NOT NULL DEFAULT 'Presencial',
    `instrutor` VARCHAR(255) NULL,
    `cidade` VARCHAR(100) NULL,
    `ementa` TEXT NULL,
    `livro_numero` INT UNSIGNED NOT NULL,
    `folha_numero` INT UNSIGNED NOT NULL,
    `registro_numero` INT UNSIGNED NOT NULL,
    `frequencia` INT UNSIGNED NOT NULL DEFAULT 100,
    `lote_id` VARCHAR(50) NULL,
    `presencas_detalhadas` TEXT NULL,
    `horas_presentes` INT UNSIGNED NULL,
    `horas_totais` INT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_registros_turma` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`) ON DELETE SET NULL,
    UNIQUE KEY `uk_livro_folha_registro` (`livro_numero`, `folha_numero`, `registro_numero`),
    INDEX `idx_codigo_autenticidade` (`codigo_autenticidade`),
    INDEX `idx_aluno_nome` (`aluno_nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. Tabela: usuarios_admin
-- Operador institucional único com senha em bcrypt
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `usuarios_admin` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `nome` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `ultimo_login` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8. Tabela: tentativas_login
-- Controle de rate-limiting e mitigação de ataques de força bruta
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tentativas_login` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL,
    `username` VARCHAR(100) NOT NULL,
    `tentativas` INT UNSIGNED NOT NULL DEFAULT 1,
    `bloqueado_ate` TIMESTAMP NULL DEFAULT NULL,
    `ultimo_erro` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_tentativas_ip_user` (`ip_address`, `username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
