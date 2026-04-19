-- ============================================================
-- MIGRACIÓN 10: Módulo Programas / Desembolsos / Rendiciones
-- ============================================================

-- 1. PROGRAMAS (vinculados a organismos_financiadores)
CREATE TABLE IF NOT EXISTS programas (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    organismo_id    INT NOT NULL,
    codigo          VARCHAR(60) NOT NULL,
    nombre          VARCHAR(255) NOT NULL,
    descripcion     TEXT,
    fecha_inicio    DATE,
    fecha_fin       DATE,
    monto_total     DECIMAL(18,2) DEFAULT 0,
    moneda          VARCHAR(10) NOT NULL DEFAULT 'USD',
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organismo_id) REFERENCES organismos_financiadores(id),
    INDEX idx_prog_organismo (organismo_id)
) ENGINE=InnoDB;

-- 2. DESEMBOLSOS por programa
CREATE TABLE IF NOT EXISTS programa_desembolsos (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    programa_id  INT NOT NULL,
    fecha        DATE NOT NULL,
    importe      DECIMAL(18,2) NOT NULL,
    moneda       VARCHAR(10) NOT NULL DEFAULT 'USD',
    observaciones TEXT,
    usuario_id   INT,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (programa_id) REFERENCES programas(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id)  REFERENCES usuarios(id),
    INDEX idx_desemb_prog (programa_id)
) ENGINE=InnoDB;

-- 3. RENDICIONES por programa
CREATE TABLE IF NOT EXISTS programa_rendiciones (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    programa_id           INT NOT NULL,
    fecha                 DATE NOT NULL,
    importe_usd           DECIMAL(18,2) NOT NULL DEFAULT 0,
    importe_pesos         DECIMAL(18,2) NOT NULL DEFAULT 0,
    total_fuente_externa  DECIMAL(18,2) NOT NULL DEFAULT 0,
    total_contraparte     DECIMAL(18,2) NOT NULL DEFAULT 0,
    observaciones         TEXT,
    usuario_id            INT,
    created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (programa_id) REFERENCES programas(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id)  REFERENCES usuarios(id),
    INDEX idx_rend_prog (programa_id)
) ENGINE=InnoDB;

-- 4. SALDOS BANCARIOS periódicos por programa
CREATE TABLE IF NOT EXISTS programa_saldos (
    id                       INT AUTO_INCREMENT PRIMARY KEY,
    programa_id              INT NOT NULL,
    fecha                    DATE NOT NULL,
    banco                    VARCHAR(150),
    cuenta                   VARCHAR(100),
    saldo_moneda_extranjera  DECIMAL(18,2) NOT NULL DEFAULT 0,
    moneda_extranjera        VARCHAR(10) NOT NULL DEFAULT 'USD',
    saldo_moneda_nacional    DECIMAL(18,2) NOT NULL DEFAULT 0,
    observaciones            TEXT,
    usuario_id               INT,
    created_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (programa_id) REFERENCES programas(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id)  REFERENCES usuarios(id),
    INDEX idx_saldo_prog (programa_id)
) ENGINE=InnoDB;

-- 5. ARCHIVOS ADJUNTOS (polimórfico)
CREATE TABLE IF NOT EXISTS programa_archivos (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    programa_id      INT NOT NULL,
    entidad_tipo     ENUM('DESEMBOLSO','RENDICION','SALDO','PROGRAMA','PAGO') NOT NULL,
    entidad_id       INT NOT NULL,
    nombre_original  VARCHAR(255) NOT NULL,
    nombre_guardado  VARCHAR(255) NOT NULL,
    mime_type        VARCHAR(100),
    tamanio          INT DEFAULT 0,
    usuario_id       INT,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (programa_id) REFERENCES programas(id) ON DELETE CASCADE,
    INDEX idx_arch_entidad (entidad_tipo, entidad_id),
    INDEX idx_arch_prog (programa_id)
) ENGINE=InnoDB;

-- 6. PAGOS IMPORTADOS desde Excel/CSV
CREATE TABLE IF NOT EXISTS programa_pagos_importados (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    programa_id   INT NOT NULL,
    lote_id       VARCHAR(40) NOT NULL COMMENT 'UUID del lote de importación',
    fila          INT NOT NULL DEFAULT 0,
    col_fecha     VARCHAR(50),
    col_concepto  VARCHAR(500),
    col_importe   VARCHAR(50),
    col_moneda    VARCHAR(20),
    col_referencia VARCHAR(255),
    datos_extra   JSON,
    import_fecha  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    usuario_id    INT,
    FOREIGN KEY (programa_id) REFERENCES programas(id) ON DELETE CASCADE,
    INDEX idx_pagos_prog (programa_id),
    INDEX idx_pagos_lote (lote_id)
) ENGINE=InnoDB;
