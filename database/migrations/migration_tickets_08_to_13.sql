-- Delta de Migração SQL para Staging / Produção (Tickets 08 a 13)
-- Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil

-- 1. Suporte a Soft-Delete para Turmas e Encontros (Ticket 10)
ALTER TABLE `turmas` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME NULL DEFAULT NULL COMMENT 'Timestamp de soft-delete para a Lixeira';
ALTER TABLE `encontros` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME NULL DEFAULT NULL COMMENT 'Timestamp de soft-delete herdado da turma';

-- 2. Suporte a Deslocamentos Logísticos fora de Goiânia (Ticket 08)
ALTER TABLE `encontros` ADD COLUMN IF NOT EXISTS `tipo` ENUM('aula', 'deslocamento') NOT NULL DEFAULT 'aula' COMMENT 'aula = Encontro pedagógico regular, deslocamento = Bloqueio de viagem logística';

-- 3. Suporte a Dados de Faturamento e Geração de Texto para NFS-e (Ticket 13)
ALTER TABLE `turmas` ADD COLUMN IF NOT EXISTS `razao_social` VARCHAR(255) NULL COMMENT 'Razão Social do Tomador do Serviço';
ALTER TABLE `turmas` ADD COLUMN IF NOT EXISTS `cnpj_tomador` VARCHAR(20) NULL COMMENT 'CNPJ ou CPF do Tomador do Serviço';
ALTER TABLE `turmas` ADD COLUMN IF NOT EXISTS `cidade_uf` VARCHAR(100) NULL COMMENT 'Município e UF de Faturamento / Prestação';
ALTER TABLE `turmas` ADD COLUMN IF NOT EXISTS `email_financeiro` VARCHAR(255) NULL COMMENT 'E-mail para envio de NFS-e e cobrança';
ALTER TABLE `turmas` ADD COLUMN IF NOT EXISTS `numero_os_contrato` VARCHAR(50) NULL COMMENT 'Número da Ordem de Serviço ou Contrato';
ALTER TABLE `turmas` ADD COLUMN IF NOT EXISTS `valor_unitario` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Valor unitário da hora-aula, aluno ou base';

-- 4. Tabela de Bloqueios de Agenda e Feriados Nacionais (Ticket 08)
CREATE TABLE IF NOT EXISTS `bloqueios_agenda` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `data` DATE NOT NULL,
    `descricao` VARCHAR(255) NOT NULL,
    `tipo` ENUM('feriado_nacional', 'bloqueio_pessoal') NOT NULL DEFAULT 'feriado_nacional',
    `bloqueante` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=Bloqueia agendamento por padrão, 0=Informativo',
    `permite_excecao` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=Permite exceção consciente confirmada pelo operador',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_bloqueios_data` (`data`),
    INDEX `idx_bloqueios_tipo` (`tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
