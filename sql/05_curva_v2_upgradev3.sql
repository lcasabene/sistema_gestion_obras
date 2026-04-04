-- ============================================================
-- 05_curva_v2_upgrade.sql
-- Migración: Alinear BD con el código actual del sistema
-- Ejecutar en la nube DESPUÉS de 01_schema.sql y 02_seed.sql
-- Seguro para ejecutar múltiples veces (usa IF NOT EXISTS)
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;


-- ==========================================================
-- 5. TABLA: obra_fuentes (usada por curva_generate.php)
-- ==========================================================
CREATE TABLE IF NOT EXISTS obra_fuentes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  obra_id INT NOT NULL,
  fuente_id INT NOT NULL,
  porcentaje DECIMAL(8,3) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (obra_id) REFERENCES obras(id),
  FOREIGN KEY (fuente_id) REFERENCES fuentes_financiamiento(id),
  INDEX idx_of_obra (obra_id)
) ENGINE=InnoDB;

-- ==========================================================
-- 6. TABLA: curva_version - agregar columnas faltantes
-- ==========================================================
ALTER TABLE curva_version
  ADD COLUMN IF NOT EXISTS monto_presupuesto DECIMAL(18,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS fecha_creacion DATETIME DEFAULT NULL;

-- Copiar created_at a fecha_creacion para registros existentes
UPDATE curva_version SET fecha_creacion = created_at WHERE fecha_creacion IS NULL;


-- ==========================================================
-- 8. TABLA: curva_items (YA EXISTE con datos - solo verificar)
--    Columnas existentes: id, version_id, periodo, concepto,
--    porcentaje_fisico, porcentaje_real, indice_inflacion, fri,
--    monto_base, redeterminacion, recupero, neto
--    NO RECREAR - tiene datos cargados.
-- ==========================================================
-- Si por algún motivo no existiera, crearla:
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
-- Si ya existe, agregar columnas que pudieran faltar:
ALTER TABLE curva_items
  ADD COLUMN IF NOT EXISTS porcentaje_real DECIMAL(8,4) DEFAULT 0 AFTER porcentaje_fisico;

-- ==========================================================
-- 9. TABLA: curva_detalle_fuente (prorrateo por FUFI)
-- ==========================================================
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
  FOREIGN KEY (curva_version_id) REFERENCES curva_version(id),
  FOREIGN KEY (fuente_id) REFERENCES fuentes_financiamiento(id),
  UNIQUE KEY uk_cdf (curva_version_id, periodo, fuente_id),
  INDEX idx_cdf_version (curva_version_id)
) ENGINE=InnoDB;

-- ==========================================================
-- 10. TABLA: certificados - reestructurar
--     El código usa columnas muy distintas al schema original.
--     Agregamos las columnas faltantes sin borrar las existentes.
-- ==========================================================
ALTER TABLE certificados
  ADD COLUMN IF NOT EXISTS empresa_id INT DEFAULT NULL AFTER obra_id,
  ADD COLUMN IF NOT EXISTS curva_item_id INT DEFAULT NULL AFTER empresa_id,
  ADD COLUMN IF NOT EXISTS nro_certificado INT DEFAULT NULL AFTER curva_item_id,
  ADD COLUMN IF NOT EXISTS tipo ENUM('ORDINARIO','ANTICIPO','REDETERMINACION') DEFAULT 'ORDINARIO' AFTER nro_certificado,
  ADD COLUMN IF NOT EXISTS fecha_medicion DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS monto_basico DECIMAL(18,2) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS monto_redeterminado DECIMAL(18,2) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS monto_bruto DECIMAL(18,2) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS fri DECIMAL(12,6) DEFAULT 1.000000,
  ADD COLUMN IF NOT EXISTS fondo_reparo_pct DECIMAL(6,3) DEFAULT 5.000,
  ADD COLUMN IF NOT EXISTS fondo_reparo_sustituido TINYINT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS fondo_reparo_monto DECIMAL(18,2) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS anticipo_pct_aplicado DECIMAL(6,3) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS anticipo_descuento DECIMAL(18,2) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS multas_monto DECIMAL(18,2) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS monto_neto_pagar DECIMAL(18,2) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS avance_fisico_mensual DECIMAL(8,4) DEFAULT 0;

-- Índice para búsqueda por curva_item_id (clave para curva_ver.php)
-- Solo crear si no existe
CREATE INDEX idx_cert_curva_item ON certificados(curva_item_id);

-- ==========================================================
-- 11. TABLA: presupuesto_ejecucion (importación CSV)
-- ==========================================================
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

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- FIN DE MIGRACIÓN
-- Después de ejecutar, verificar con:
--   SHOW TABLES;
--   DESCRIBE curva_items;
--   DESCRIBE certificados;
-- ============================================================
