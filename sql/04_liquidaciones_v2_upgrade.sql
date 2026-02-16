-- =====================================================================
-- UPGRADE v2/v3 – Módulo Liquidaciones
-- Ejecutar SOLO si ya se ejecutó 03_liquidaciones_schema.sql original
-- Agrega: obra_tipo, cabecera doc, cesión, ret_otras, multas, obs, IIBB
-- =====================================================================

-- Nuevos campos en liquidaciones (v2 base)
ALTER TABLE liquidaciones
  ADD COLUMN IF NOT EXISTS alicuota_iva_contenido DECIMAL(5,2) NOT NULL DEFAULT 21.00 AFTER fecha_pago,
  ADD COLUMN IF NOT EXISTS obra_tipo ENUM('INGENIERIA','ARQUITECTURA','OTRA') NOT NULL DEFAULT 'ARQUITECTURA' AFTER alicuota_iva_contenido,
  ADD COLUMN IF NOT EXISTS obra_exencion_ganancias TINYINT(1) NOT NULL DEFAULT 0 AFTER obra_tipo,
  ADD COLUMN IF NOT EXISTS obra_exencion_iva TINYINT(1) NOT NULL DEFAULT 0 AFTER obra_exencion_ganancias,
  ADD COLUMN IF NOT EXISTS obra_exencion_iibb TINYINT(1) NOT NULL DEFAULT 0 AFTER obra_exencion_iva,
  ADD COLUMN IF NOT EXISTS iibb_categoria_id INT DEFAULT NULL AFTER obra_exencion_iibb,
  ADD COLUMN IF NOT EXISTS iibb_jurisdiccion VARCHAR(60) DEFAULT NULL AFTER iibb_categoria_id,
  ADD COLUMN IF NOT EXISTS iibb_alicuota DECIMAL(5,2) DEFAULT NULL AFTER iibb_jurisdiccion;

-- Cabecera documento SIGUE-UPEFE (v3)
ALTER TABLE liquidaciones
  ADD COLUMN IF NOT EXISTS expediente VARCHAR(120) DEFAULT NULL AFTER iibb_alicuota,
  ADD COLUMN IF NOT EXISTS ref_doc VARCHAR(120) DEFAULT NULL AFTER expediente,
  ADD COLUMN IF NOT EXISTS op_sicopro VARCHAR(60) DEFAULT NULL AFTER ref_doc;

-- Cesión de derechos (v3)
ALTER TABLE liquidaciones
  ADD COLUMN IF NOT EXISTS cesion_cuit VARCHAR(20) DEFAULT NULL AFTER op_sicopro,
  ADD COLUMN IF NOT EXISTS cesion_proveedor VARCHAR(255) DEFAULT NULL AFTER cesion_cuit,
  ADD COLUMN IF NOT EXISTS cesion_cbu VARCHAR(30) DEFAULT NULL AFTER cesion_proveedor;

-- Observaciones y retenciones manuales (v3)
ALTER TABLE liquidaciones
  ADD COLUMN IF NOT EXISTS fondo_reparo_obs VARCHAR(255) DEFAULT NULL AFTER fondo_reparo_monto,
  ADD COLUMN IF NOT EXISTS ret_otras_monto DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER fondo_reparo_obs,
  ADD COLUMN IF NOT EXISTS ret_otras_obs VARCHAR(255) DEFAULT NULL AFTER ret_otras_monto,
  ADD COLUMN IF NOT EXISTS multas_monto DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER ret_otras_obs,
  ADD COLUMN IF NOT EXISTS multas_obs VARCHAR(255) DEFAULT NULL AFTER multas_monto,
  ADD COLUMN IF NOT EXISTS obs_suss VARCHAR(255) DEFAULT NULL AFTER multas_obs,
  ADD COLUMN IF NOT EXISTS obs_ganancias VARCHAR(255) DEFAULT NULL AFTER obs_suss,
  ADD COLUMN IF NOT EXISTS obs_iibb VARCHAR(255) DEFAULT NULL AFTER obs_ganancias,
  ADD COLUMN IF NOT EXISTS observaciones_finales TEXT DEFAULT NULL AFTER obs_iibb;

-- Pago parcial y concepto ganancias (v3.1)
ALTER TABLE liquidaciones
  ADD COLUMN IF NOT EXISTS importe_pago DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER cesion_cbu,
  ADD COLUMN IF NOT EXISTS ganancias_concepto_id INT DEFAULT NULL AFTER importe_pago;

-- Migrar datos existentes: importe_pago = comprobante_importe_total donde importe_pago=0
UPDATE liquidaciones SET importe_pago = comprobante_importe_total WHERE importe_pago = 0;

-- Fondo de reparo porcentaje y overrides manuales (v3.2)
ALTER TABLE liquidaciones
  ADD COLUMN IF NOT EXISTS fondo_reparo_pct DECIMAL(5,2) NOT NULL DEFAULT 5.00 AFTER base_imponible,
  ADD COLUMN IF NOT EXISTS override_suss DECIMAL(18,2) DEFAULT NULL AFTER fondo_reparo_obs,
  ADD COLUMN IF NOT EXISTS override_ganancias DECIMAL(18,2) DEFAULT NULL AFTER override_suss,
  ADD COLUMN IF NOT EXISTS override_iibb DECIMAL(18,2) DEFAULT NULL AFTER override_ganancias,
  ADD COLUMN IF NOT EXISTS override_iva DECIMAL(18,2) DEFAULT NULL AFTER override_iibb;

-- Si existía obra_es_ingenieria del v2 anterior, migrar y eliminar
-- (ejecutar solo si existe la columna)
-- UPDATE liquidaciones SET obra_tipo = 'INGENIERIA' WHERE obra_es_ingenieria = 1;
-- ALTER TABLE liquidaciones DROP COLUMN IF EXISTS obra_es_ingenieria;
-- ALTER TABLE liquidaciones DROP COLUMN IF EXISTS excluir_iva;
-- ALTER TABLE liquidaciones DROP COLUMN IF EXISTS excluir_fondo_reparo;
-- ALTER TABLE liquidaciones DROP COLUMN IF EXISTS otras_deducciones;
-- ALTER TABLE liquidaciones DROP COLUMN IF EXISTS otras_deducciones_detalle;

-- =====================================================================
-- IIBB – CATEGORÍAS DE RETENCIÓN (configurable)
-- Basado en Resolución 276/DPR/17 Neuquén, pero genérico
-- =====================================================================
CREATE TABLE IF NOT EXISTS iibb_categorias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(5) NOT NULL,
  descripcion VARCHAR(255) NOT NULL,
  alicuota DECIMAL(5,2) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  orden INT NOT NULL DEFAULT 0,
  UNIQUE KEY uk_iibb_cat_codigo (codigo)
) ENGINE=InnoDB;

-- =====================================================================
-- IIBB – MÍNIMOS NO SUJETOS A RETENCIÓN (por tipo de agente)
-- =====================================================================
CREATE TABLE IF NOT EXISTS iibb_minimos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo_agente VARCHAR(60) NOT NULL,
  descripcion VARCHAR(255) NOT NULL,
  minimo_no_sujeto DECIMAL(18,2) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_iibb_min_tipo (tipo_agente)
) ENGINE=InnoDB;

-- =====================================================================
-- SEED: Categorías IIBB – Resolución 276/DPR/17 Neuquén (Art. 11)
-- =====================================================================
INSERT INTO iibb_categorias (codigo, descripcion, alicuota, activo, orden) VALUES
('a', 'No acredita situación fiscal (Art. 11 inc. g → ahora default)', 4.00, 0, 99),
('b', 'Contribuyente directo Provincia del Neuquén', 2.00, 1, 1),
('c', 'Convenio Multilateral con sede en Neuquén', 1.50, 1, 2),
('d', 'Convenio Multilateral con sede en otra Provincia', 1.00, 1, 3),
('e', 'Pagos Dirección de Lotería (juegos de azar)', 3.00, 1, 4),
('f', 'Pagos mediante tarjetas de crédito/compra/pago', 1.00, 1, 5),
('g', 'Honorarios profesionales judiciales (Entidades Financieras)', 1.50, 1, 6),
('h', 'No acredita situación fiscal', 4.00, 1, 7)
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion), alicuota=VALUES(alicuota);

-- =====================================================================
-- SEED: Mínimos no sujetos a retención (Art. 7)
-- =====================================================================
INSERT INTO iibb_minimos (tipo_agente, descripcion, minimo_no_sujeto) VALUES
('ESTADO', 'Reparticiones Nac/Prov/Mun, organismos autárquicos, empresas del Estado', 10000.00),
('PRIVADO', 'Agentes de retención no incluidos en inciso anterior', 5000.00)
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion), minimo_no_sujeto=VALUES(minimo_no_sujeto);

-- Concepto IIBB en rg830_conceptos
INSERT INTO rg830_conceptos (codigo, inciso, descripcion, activo) VALUES
('IIBB', 'PAGO', 'Retención Ingresos Brutos - Res. 276/DPR/17 Neuquén', 1)
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion);

-- Vigencia IIBB default (placeholder, la alícuota real viene de iibb_categorias)
INSERT INTO rg830_vigencias (concepto_id, vigencia_desde, vigencia_hasta, porc_inscripto, porc_no_inscripto, minimo_no_sujeto, modo_calculo) 
SELECT id, '2024-01-01', NULL, 2.00, 4.00, 10000.00, 'PORCENTAJE_DIRECTO'
FROM rg830_conceptos WHERE codigo='IIBB' AND inciso='PAGO'
ON DUPLICATE KEY UPDATE porc_inscripto=VALUES(porc_inscripto);
