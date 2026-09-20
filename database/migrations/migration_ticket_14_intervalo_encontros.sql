-- Ticket 14: preservação de intervalo e cálculo de carga horária líquida.
-- Seguro para reaplicação no MariaDB 10.4+.

ALTER TABLE `encontros`
    ADD COLUMN IF NOT EXISTS `intervalo_minutos` INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Intervalo não pedagógico descontado da carga horária do encontro'
    AFTER `horario_fim`;
