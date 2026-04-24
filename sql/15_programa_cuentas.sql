-- ============================================================
-- MIGRACIÓN 15: Cuentas bancarias por programa
-- ============================================================

CREATE TABLE IF NOT EXISTS programa_cuentas (
    id                       INT AUTO_INCREMENT PRIMARY KEY,
    programa_id              INT NOT NULL,
    banco                    VARCHAR(150) NOT NULL,
    cbu                      VARCHAR(30)  NULL,
    alias                    VARCHAR(80)  NULL,
    nro_cuenta               VARCHAR(60)  NULL,
    servicio_administrativo  VARCHAR(150) NULL,
    denominacion             VARCHAR(200) NULL,
    moneda                   VARCHAR(10)  DEFAULT 'ARS',
    activa                   TINYINT(1)   DEFAULT 1,
    observaciones            TEXT         NULL,
    usuario_id               INT          NULL,
    created_at               TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_cuenta_programa FOREIGN KEY (programa_id) REFERENCES programas(id) ON DELETE CASCADE,
    INDEX idx_cuenta_programa (programa_id)
) ENGINE=InnoDB;

-- Vincular saldos a cuentas (opcional por compatibilidad con saldos viejos)
ALTER TABLE programa_saldos
    ADD COLUMN cuenta_id INT NULL AFTER programa_id,
    ADD CONSTRAINT fk_saldo_cuenta FOREIGN KEY (cuenta_id) REFERENCES programa_cuentas(id) ON DELETE SET NULL,
    ADD INDEX idx_saldo_cuenta (cuenta_id);
