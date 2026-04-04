-- ============================================================
-- MIGRACIÓN 06: Organismos, Líneas de Crédito, Regiones, UTE
-- ============================================================

-- 1. REGIONES (configurable)
CREATE TABLE IF NOT EXISTS regiones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

INSERT IGNORE INTO regiones (nombre) VALUES
    ('Alto Neuquén'),
    ('Del Pehuén'),
    ('De los Lagos del Sur'),
    ('Vaca Muerta'),
    ('Del Limay'),
    ('De la Comarca'),
    ('Confluencia');


-- 2. ORGANISMOS FINANCIADORES (ya puede existir)
CREATE TABLE IF NOT EXISTS organismos_financiadores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_organismo VARCHAR(120) NOT NULL,
    descripcion_programa TEXT,
    activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- 3. LÍNEAS DE CRÉDITO (hijo de organismos)
CREATE TABLE IF NOT EXISTS lineas_credito (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organismo_id INT NOT NULL,
    codigo VARCHAR(60) NOT NULL,
    descripcion VARCHAR(255),
    activo TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (organismo_id) REFERENCES organismos_financiadores(id),
    INDEX idx_lc_organismo (organismo_id)
) ENGINE=InnoDB;

-- 4. COMPOSICIÓN UTE (vinculada a empresas, no a obras)
CREATE TABLE IF NOT EXISTS empresa_ute_integrantes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT NOT NULL COMMENT 'La empresa UTE padre',
    integrante_empresa_id INT NULL COMMENT 'FK a empresas si el integrante ya existe',
    cuit VARCHAR(20) NOT NULL,
    denominacion VARCHAR(200) NOT NULL,
    porcentaje DECIMAL(5,2) NOT NULL DEFAULT 0,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    INDEX idx_ute_empresa (empresa_id)
) ENGINE=InnoDB;

-- 5. AGREGAR es_ute A EMPRESAS
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'es_ute');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE empresas ADD COLUMN es_ute TINYINT(1) NOT NULL DEFAULT 0 AFTER cuit', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. AGREGAR COLUMNAS A OBRAS (si no existen)
-- Cubre columnas de migración 05 que usan IF NOT EXISTS (solo MariaDB)
-- y columnas nuevas de migración 06.

-- empresa_id
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obras' AND COLUMN_NAME = 'empresa_id');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE obras ADD COLUMN empresa_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- anticipo_pct
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obras' AND COLUMN_NAME = 'anticipo_pct');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE obras ADD COLUMN anticipo_pct DECIMAL(6,3) DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- anticipo_monto
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obras' AND COLUMN_NAME = 'anticipo_monto');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE obras ADD COLUMN anticipo_monto DECIMAL(18,2) DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- periodo_base
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obras' AND COLUMN_NAME = 'periodo_base');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE obras ADD COLUMN periodo_base VARCHAR(7) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- region: si existe como ENUM convertir a VARCHAR, si no existe crear como VARCHAR
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obras' AND COLUMN_NAME = 'region');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE obras ADD COLUMN region VARCHAR(100) NULL', 
    'ALTER TABLE obras MODIFY COLUMN region VARCHAR(100) NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- organismo_requirente
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obras' AND COLUMN_NAME = 'organismo_requirente');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE obras ADD COLUMN organismo_requirente VARCHAR(200) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- titularidad_terreno
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obras' AND COLUMN_NAME = 'titularidad_terreno');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE obras ADD COLUMN titularidad_terreno VARCHAR(200) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- superficie_desarrollo
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obras' AND COLUMN_NAME = 'superficie_desarrollo');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE obras ADD COLUMN superficie_desarrollo VARCHAR(100) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- caracteristicas_obra
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obras' AND COLUMN_NAME = 'caracteristicas_obra');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE obras ADD COLUMN caracteristicas_obra TEXT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- memoria_objetivo
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obras' AND COLUMN_NAME = 'memoria_objetivo');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE obras ADD COLUMN memoria_objetivo TEXT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- latitud
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obras' AND COLUMN_NAME = 'latitud');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE obras ADD COLUMN latitud VARCHAR(20) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- longitud
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obras' AND COLUMN_NAME = 'longitud');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE obras ADD COLUMN longitud VARCHAR(20) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- geojson_data
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obras' AND COLUMN_NAME = 'geojson_data');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE obras ADD COLUMN geojson_data LONGTEXT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- organismo_financiador_id
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obras' AND COLUMN_NAME = 'organismo_financiador_id');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE obras ADD COLUMN organismo_financiador_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- linea_credito_id
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obras' AND COLUMN_NAME = 'linea_credito_id');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE obras ADD COLUMN linea_credito_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6b. TABLA obra_fuentes_config (puede faltar de migración 05)
CREATE TABLE IF NOT EXISTS obra_fuentes_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    obra_id INT NOT NULL,
    fuente_id INT NOT NULL,
    porcentaje DECIMAL(8,3) NOT NULL DEFAULT 0,
    INDEX idx_ofc_obra (obra_id)
) ENGINE=InnoDB;

-- 7. AGREGAR SEGUNDO CÓDIGO PROVEEDOR A EMPRESAS
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'codigo_proveedor_2');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE empresas ADD COLUMN codigo_proveedor_2 VARCHAR(50) NULL AFTER codigo_proveedor', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- 8. COLUMNAS FALTANTES DE MIGRACIÓN 05 (usa IF NOT EXISTS = solo MariaDB)
--    Convertidas a patrón INFORMATION_SCHEMA para MySQL estándar
-- ============================================================

-- 8a. fuentes_financiamiento.codigo
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fuentes_financiamiento' AND COLUMN_NAME = 'codigo');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE fuentes_financiamiento ADD COLUMN codigo VARCHAR(20) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8b. curva_version
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curva_version' AND COLUMN_NAME = 'monto_presupuesto');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE curva_version ADD COLUMN monto_presupuesto DECIMAL(18,2) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curva_version' AND COLUMN_NAME = 'fecha_creacion');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE curva_version ADD COLUMN fecha_creacion DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8c. curva_detalle
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curva_detalle' AND COLUMN_NAME = 'monto_bruto_plan');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE curva_detalle ADD COLUMN monto_bruto_plan DECIMAL(18,2) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curva_detalle' AND COLUMN_NAME = 'anticipo_pago_plan');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE curva_detalle ADD COLUMN anticipo_pago_plan DECIMAL(18,2) DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curva_detalle' AND COLUMN_NAME = 'anticipo_pago_modo');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE curva_detalle ADD COLUMN anticipo_pago_modo VARCHAR(20) DEFAULT ''PARIPASSU''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curva_detalle' AND COLUMN_NAME = 'anticipo_pago_fuente_id');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE curva_detalle ADD COLUMN anticipo_pago_fuente_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curva_detalle' AND COLUMN_NAME = 'anticipo_recupero_plan');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE curva_detalle ADD COLUMN anticipo_recupero_plan DECIMAL(18,2) DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curva_detalle' AND COLUMN_NAME = 'anticipo_rec_modo');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE curva_detalle ADD COLUMN anticipo_rec_modo VARCHAR(20) DEFAULT ''PARIPASSU''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curva_detalle' AND COLUMN_NAME = 'anticipo_rec_fuente_id');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE curva_detalle ADD COLUMN anticipo_rec_fuente_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curva_detalle' AND COLUMN_NAME = 'monto_neto_plan');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE curva_detalle ADD COLUMN monto_neto_plan DECIMAL(18,2) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8d. curva_items.porcentaje_real
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curva_items' AND COLUMN_NAME = 'porcentaje_real');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE curva_items ADD COLUMN porcentaje_real DECIMAL(8,4) DEFAULT 0 AFTER porcentaje_fisico', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8e. certificados
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificados' AND COLUMN_NAME = 'empresa_id');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE certificados ADD COLUMN empresa_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificados' AND COLUMN_NAME = 'curva_item_id');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE certificados ADD COLUMN curva_item_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificados' AND COLUMN_NAME = 'nro_certificado');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE certificados ADD COLUMN nro_certificado INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificados' AND COLUMN_NAME = 'tipo');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE certificados ADD COLUMN tipo VARCHAR(20) DEFAULT ''ORDINARIO''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificados' AND COLUMN_NAME = 'fecha_medicion');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE certificados ADD COLUMN fecha_medicion DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificados' AND COLUMN_NAME = 'monto_basico');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE certificados ADD COLUMN monto_basico DECIMAL(18,2) DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificados' AND COLUMN_NAME = 'monto_redeterminado');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE certificados ADD COLUMN monto_redeterminado DECIMAL(18,2) DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificados' AND COLUMN_NAME = 'monto_bruto');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE certificados ADD COLUMN monto_bruto DECIMAL(18,2) DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificados' AND COLUMN_NAME = 'fri');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE certificados ADD COLUMN fri DECIMAL(12,6) DEFAULT 1.000000', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificados' AND COLUMN_NAME = 'fondo_reparo_pct');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE certificados ADD COLUMN fondo_reparo_pct DECIMAL(6,3) DEFAULT 5.000', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificados' AND COLUMN_NAME = 'fondo_reparo_sustituido');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE certificados ADD COLUMN fondo_reparo_sustituido TINYINT(1) DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificados' AND COLUMN_NAME = 'fondo_reparo_monto');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE certificados ADD COLUMN fondo_reparo_monto DECIMAL(18,2) DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificados' AND COLUMN_NAME = 'anticipo_pct_aplicado');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE certificados ADD COLUMN anticipo_pct_aplicado DECIMAL(6,3) DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificados' AND COLUMN_NAME = 'anticipo_descuento');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE certificados ADD COLUMN anticipo_descuento DECIMAL(18,2) DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificados' AND COLUMN_NAME = 'multas_monto');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE certificados ADD COLUMN multas_monto DECIMAL(18,2) DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificados' AND COLUMN_NAME = 'monto_neto_pagar');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE certificados ADD COLUMN monto_neto_pagar DECIMAL(18,2) DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificados' AND COLUMN_NAME = 'avance_fisico_mensual');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE certificados ADD COLUMN avance_fisico_mensual DECIMAL(8,4) DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8f. Índice certificados.curva_item_id (solo si no existe)
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificados' AND INDEX_NAME = 'idx_cert_curva_item');
SET @sql = IF(@idx_exists = 0, 
    'CREATE INDEX idx_cert_curva_item ON certificados(curva_item_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8g. Tablas CREATE TABLE IF NOT EXISTS de migración 05
CREATE TABLE IF NOT EXISTS obra_fuentes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    obra_id INT NOT NULL,
    fuente_id INT NOT NULL,
    porcentaje DECIMAL(8,3) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_of_obra (obra_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS curva_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version_id INT NOT NULL,
    periodo VARCHAR(10) NOT NULL,
    concepto VARCHAR(120) DEFAULT NULL,
    porcentaje_fisico DECIMAL(8,4) NOT NULL DEFAULT 0,
    porcentaje_real DECIMAL(8,4) DEFAULT 0,
    indice_inflacion DECIMAL(8,4) DEFAULT 0,
    fri DECIMAL(12,6) DEFAULT 1.000000,
    monto_base DECIMAL(18,2) DEFAULT 0,
    redeterminacion DECIMAL(18,2) DEFAULT 0,
    recupero DECIMAL(18,2) DEFAULT 0,
    neto DECIMAL(18,2) DEFAULT 0,
    INDEX idx_ci_version (version_id),
    INDEX idx_ci_periodo (periodo)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS curva_detalle_fuente (
    id INT AUTO_INCREMENT PRIMARY KEY,
    curva_version_id INT NOT NULL,
    periodo VARCHAR(10) NOT NULL,
    fuente_id INT NOT NULL,
    porcentaje_fuente DECIMAL(8,3) NOT NULL DEFAULT 0,
    monto_bruto_plan DECIMAL(18,2) DEFAULT 0,
    anticipo_pago_plan DECIMAL(18,2) DEFAULT 0,
    anticipo_recupero_plan DECIMAL(18,2) DEFAULT 0,
    monto_neto_plan DECIMAL(18,2) DEFAULT 0,
    UNIQUE KEY uk_cdf (curva_version_id, periodo, fuente_id),
    INDEX idx_cdf_version (curva_version_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS presupuesto_ejecucion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ejer VARCHAR(10),
    fecha_listado VARCHAR(20),
    tipo_imp VARCHAR(10),
    juri VARCHAR(10),
    sa VARCHAR(10),
    unor VARCHAR(10),
    fina VARCHAR(10),
    func VARCHAR(10),
    subf VARCHAR(10),
    inci VARCHAR(10),
    ppal VARCHAR(10),
    ppar VARCHAR(10),
    spar VARCHAR(10),
    fufi VARCHAR(20),
    ubge VARCHAR(10),
    monto_def DECIMAL(18,2) DEFAULT 0,
    monto_comp DECIMAL(18,2) DEFAULT 0,
    monto_ejec DECIMAL(18,2) DEFAULT 0,
    monto_disp DECIMAL(18,2) DEFAULT 0,
    monto_sald DECIMAL(18,2) DEFAULT 0,
    monto_reep DECIMAL(18,2) DEFAULT 0,
    tiju VARCHAR(10),
    tisa VARCHAR(10),
    tide VARCHAR(10),
    tiuo VARCHAR(10),
    cpn1 VARCHAR(10),
    cpn2 VARCHAR(10),
    cpn3 VARCHAR(10),
    atn1 VARCHAR(10),
    atn2 VARCHAR(10),
    atn3 VARCHAR(10),
    denominacion1 VARCHAR(200),
    denominacion2 VARCHAR(200),
    denominacion3 VARCHAR(200),
    imputacion VARCHAR(200),
    preventivos DECIMAL(18,2) DEFAULT 0,
    desc_imputacion TEXT,
    fecha_carga DATE NOT NULL,
    protegido TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_pe_fufi (fufi),
    INDEX idx_pe_fecha (fecha_carga),
    INDEX idx_pe_ejer (ejer)
) ENGINE=InnoDB;

-- Copiar created_at a fecha_creacion para registros existentes de curva_version (solo si created_at existe)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curva_version' AND COLUMN_NAME = 'created_at');
SET @sql = IF(@col_exists > 0, 
    'UPDATE curva_version SET fecha_creacion = created_at WHERE fecha_creacion IS NULL AND created_at IS NOT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- FIN DE MIGRACIÓN 06
-- Seguro para ejecutar múltiples veces en MySQL estándar
-- ============================================================
