-- =====================================================================
-- MÓDULO DE LIQUIDACIONES – DETERMINACIÓN IMPOSITIVA
-- DDL MySQL - Compatible con gestion_obras_1
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

-- =========================
-- ALTER empresas: agregar campos fiscales
-- =========================
ALTER TABLE empresas
  ADD COLUMN IF NOT EXISTS condicion_iva ENUM('RI','MONOTRIBUTO','EXENTO','NO_CATEGORIZADO') NOT NULL DEFAULT 'RI' AFTER cuit,
  ADD COLUMN IF NOT EXISTS ganancias_condicion ENUM('INSCRIPTO','NO_INSCRIPTO') NOT NULL DEFAULT 'INSCRIPTO' AFTER condicion_iva,
  ADD COLUMN IF NOT EXISTS suss_condicion ENUM('EMPLEADOR','NO_EMPLEADOR','EXENTO') NOT NULL DEFAULT 'EMPLEADOR' AFTER ganancias_condicion,
  ADD COLUMN IF NOT EXISTS iibb_condicion VARCHAR(60) DEFAULT NULL AFTER suss_condicion,
  ADD COLUMN IF NOT EXISTS iibb_nro_inscripcion VARCHAR(30) DEFAULT NULL AFTER iibb_condicion,
  ADD COLUMN IF NOT EXISTS exclusion_ganancias TINYINT(1) NOT NULL DEFAULT 0 AFTER iibb_nro_inscripcion,
  ADD COLUMN IF NOT EXISTS exclusion_ganancias_desde DATE DEFAULT NULL AFTER exclusion_ganancias,
  ADD COLUMN IF NOT EXISTS exclusion_ganancias_hasta DATE DEFAULT NULL AFTER exclusion_ganancias_desde,
  ADD COLUMN IF NOT EXISTS exclusion_iva TINYINT(1) NOT NULL DEFAULT 0 AFTER exclusion_ganancias_hasta,
  ADD COLUMN IF NOT EXISTS exclusion_iva_desde DATE DEFAULT NULL AFTER exclusion_iva,
  ADD COLUMN IF NOT EXISTS exclusion_iva_hasta DATE DEFAULT NULL AFTER exclusion_iva_desde,
  ADD COLUMN IF NOT EXISTS exclusion_suss TINYINT(1) NOT NULL DEFAULT 0 AFTER exclusion_iva_hasta,
  ADD COLUMN IF NOT EXISTS exclusion_suss_desde DATE DEFAULT NULL AFTER exclusion_suss,
  ADD COLUMN IF NOT EXISTS exclusion_suss_hasta DATE DEFAULT NULL AFTER exclusion_suss_desde;

-- =========================
-- CONFIGURACIÓN IMPOSITIVA POR OBRA
-- =========================
CREATE TABLE IF NOT EXISTS config_impositiva_obra (
  id INT AUTO_INCREMENT PRIMARY KEY,
  obra_id INT NOT NULL,
  impuesto ENUM('GANANCIAS','IVA','SUSS','IIBB') NOT NULL,
  aplica_retencion TINYINT(1) NOT NULL DEFAULT 1,
  porcentaje_override DECIMAL(6,3) DEFAULT NULL,
  minimo_override DECIMAL(18,2) DEFAULT NULL,
  motivo VARCHAR(255) DEFAULT NULL,
  usuario_id INT DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_cio (obra_id, impuesto),
  FOREIGN KEY (obra_id) REFERENCES obras(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- =========================
-- CONFIGURACIÓN IMPOSITIVA POR PROVEEDOR
-- =========================
CREATE TABLE IF NOT EXISTS config_impositiva_proveedor (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  impuesto ENUM('GANANCIAS','IVA','SUSS','IIBB') NOT NULL,
  aplica_retencion TINYINT(1) NOT NULL DEFAULT 1,
  porcentaje_override DECIMAL(6,3) DEFAULT NULL,
  minimo_override DECIMAL(18,2) DEFAULT NULL,
  motivo VARCHAR(255) DEFAULT NULL,
  usuario_id INT DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_cip (empresa_id, impuesto),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- =========================
-- RG 830 – CONCEPTOS
-- =========================
CREATE TABLE IF NOT EXISTS rg830_conceptos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(10) NOT NULL,
  inciso VARCHAR(10) NOT NULL,
  descripcion VARCHAR(255) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_rg830c_codigo (codigo, inciso)
) ENGINE=InnoDB;

-- =========================
-- RG 830 – VIGENCIAS
-- =========================
CREATE TABLE IF NOT EXISTS rg830_vigencias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  concepto_id INT NOT NULL,
  vigencia_desde DATE NOT NULL,
  vigencia_hasta DATE DEFAULT NULL,
  porc_inscripto DECIMAL(6,3) NOT NULL DEFAULT 0,
  porc_no_inscripto DECIMAL(6,3) NOT NULL DEFAULT 0,
  minimo_no_sujeto DECIMAL(18,2) NOT NULL DEFAULT 0,
  modo_calculo ENUM('PORCENTAJE_DIRECTO','ESCALA_TRAMOS') NOT NULL DEFAULT 'PORCENTAJE_DIRECTO',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (concepto_id) REFERENCES rg830_conceptos(id),
  INDEX idx_rg830v_vigencia (concepto_id, vigencia_desde, vigencia_hasta)
) ENGINE=InnoDB;

-- =========================
-- RG 830 – ESCALAS / TRAMOS
-- =========================
CREATE TABLE IF NOT EXISTS rg830_escalas_tramos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vigencia_id INT NOT NULL,
  orden INT NOT NULL DEFAULT 1,
  desde DECIMAL(18,2) NOT NULL DEFAULT 0,
  hasta DECIMAL(18,2) DEFAULT NULL,
  importe_fijo DECIMAL(18,2) NOT NULL DEFAULT 0,
  porcentaje_sobre_excedente DECIMAL(6,3) NOT NULL DEFAULT 0,
  excedente_desde DECIMAL(18,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (vigencia_id) REFERENCES rg830_vigencias(id) ON DELETE CASCADE,
  INDEX idx_rg830et_orden (vigencia_id, orden)
) ENGINE=InnoDB;

-- =========================
-- LIQUIDACIONES (CABECERA)
-- =========================
CREATE TABLE IF NOT EXISTS liquidaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  
  -- Origen del comprobante
  comprobante_arca_id INT DEFAULT NULL,
  tipo_comprobante_origen ENUM('ARCA','OTROS_PAGOS') NOT NULL DEFAULT 'ARCA',
  
  -- Datos del comprobante (snapshot o manual)
  comprobante_tipo VARCHAR(40) DEFAULT NULL,
  comprobante_fecha DATE NOT NULL,
  comprobante_numero VARCHAR(16) DEFAULT NULL,
  comprobante_importe_total DECIMAL(18,2) NOT NULL DEFAULT 0,
  comprobante_iva DECIMAL(18,2) NOT NULL DEFAULT 0,
  comprobante_importe_neto DECIMAL(18,2) NOT NULL DEFAULT 0,
  
  -- Vinculaciones
  empresa_id INT NOT NULL,
  obra_id INT NOT NULL,
  
  -- Fecha de pago (fecha que manda para retenciones)
  fecha_pago DATE NOT NULL,
  
  -- Alícuota IVA contenido (manual, para discriminar el IVA de la factura)
  alicuota_iva_contenido DECIMAL(5,2) NOT NULL DEFAULT 21.00,
  
  -- Tipo de obra (determina alícuota SUSS: INGENIERIA=1.20%, ARQUITECTURA=2.50%)
  obra_tipo ENUM('INGENIERIA','ARQUITECTURA','OTRA') NOT NULL DEFAULT 'ARQUITECTURA',
  obra_exencion_ganancias TINYINT(1) NOT NULL DEFAULT 0,
  obra_exencion_iva TINYINT(1) NOT NULL DEFAULT 0,
  obra_exencion_iibb TINYINT(1) NOT NULL DEFAULT 0,
  
  -- IIBB (categoría según Res. 276/DPR/17 Neuquén)
  iibb_categoria_id INT DEFAULT NULL,
  iibb_jurisdiccion VARCHAR(60) DEFAULT NULL,
  iibb_alicuota DECIMAL(5,2) DEFAULT NULL,
  
  -- Cabecera documento SIGUE-UPEFE
  expediente VARCHAR(120) DEFAULT NULL,
  ref_doc VARCHAR(120) DEFAULT NULL,
  op_sicopro VARCHAR(60) DEFAULT NULL,
  
  -- Cesión de derechos
  cesion_cuit VARCHAR(20) DEFAULT NULL,
  cesion_proveedor VARCHAR(255) DEFAULT NULL,
  cesion_cbu VARCHAR(30) DEFAULT NULL,
  
  -- Pago parcial: importe que se paga en esta liquidación (puede ser < total factura)
  importe_pago DECIMAL(18,2) NOT NULL DEFAULT 0,
  
  -- Concepto Ganancias RG830 (referencia a rg830_conceptos)
  ganancias_concepto_id INT DEFAULT NULL,
  
  -- Base imponible (calculada sobre importe_pago)
  base_imponible DECIMAL(18,2) NOT NULL DEFAULT 0,

  -- Fondo de Reparo (default 5% del pago, editable)
  fondo_reparo_pct DECIMAL(5,2) NOT NULL DEFAULT 5.00,
  fondo_reparo_monto DECIMAL(18,2) NOT NULL DEFAULT 0,
  fondo_reparo_obs VARCHAR(255) DEFAULT NULL,
  
  -- Overrides manuales de retenciones calculadas (NULL = usar cálculo automático)
  override_suss DECIMAL(18,2) DEFAULT NULL,
  override_ganancias DECIMAL(18,2) DEFAULT NULL,
  override_iibb DECIMAL(18,2) DEFAULT NULL,
  override_iva DECIMAL(18,2) DEFAULT NULL,

  -- Retenciones manuales
  ret_otras_monto DECIMAL(18,2) NOT NULL DEFAULT 0,
  ret_otras_obs VARCHAR(255) DEFAULT NULL,
  multas_monto DECIMAL(18,2) NOT NULL DEFAULT 0,
  multas_obs VARCHAR(255) DEFAULT NULL,
  
  -- Observaciones por línea de retención
  obs_suss VARCHAR(255) DEFAULT NULL,
  obs_ganancias VARCHAR(255) DEFAULT NULL,
  obs_iibb VARCHAR(255) DEFAULT NULL,
  observaciones_finales TEXT DEFAULT NULL,
  
  -- Totales calculados
  total_retenciones DECIMAL(18,2) NOT NULL DEFAULT 0,
  neto_a_pagar DECIMAL(18,2) NOT NULL DEFAULT 0,
  
  -- Estado
  estado ENUM('BORRADOR','PRELIQUIDADO','CONFIRMADO','ANULADO') NOT NULL DEFAULT 'BORRADOR',
  
  -- Confirmación
  fecha_confirmacion DATETIME DEFAULT NULL,
  usuario_confirmacion_id INT DEFAULT NULL,
  
  -- Anulación
  fecha_anulacion DATETIME DEFAULT NULL,
  usuario_anulacion_id INT DEFAULT NULL,
  motivo_anulacion VARCHAR(500) DEFAULT NULL,
  
  -- Certificado de retención
  nro_certificado_retencion VARCHAR(20) DEFAULT NULL,
  
  -- Auditoría
  usuario_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (empresa_id) REFERENCES empresas(id),
  FOREIGN KEY (obra_id) REFERENCES obras(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (usuario_confirmacion_id) REFERENCES usuarios(id),
  FOREIGN KEY (usuario_anulacion_id) REFERENCES usuarios(id),
  INDEX idx_liq_estado (estado),
  INDEX idx_liq_empresa (empresa_id),
  INDEX idx_liq_obra (obra_id),
  INDEX idx_liq_fecha (fecha_pago),
  INDEX idx_liq_nro_cert (nro_certificado_retencion)
) ENGINE=InnoDB;

-- =========================
-- LIQUIDACION ITEMS (RETENCIONES INDIVIDUALES)
-- =========================
CREATE TABLE IF NOT EXISTS liquidacion_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  liquidacion_id INT NOT NULL,
  
  impuesto ENUM('GANANCIAS','IVA','SUSS','IIBB') NOT NULL,
  
  -- Parámetros aplicados (snapshot)
  rg830_concepto_id INT DEFAULT NULL,
  rg830_vigencia_id INT DEFAULT NULL,
  condicion_fiscal VARCHAR(30) DEFAULT NULL,
  
  -- Cálculo
  base_calculo DECIMAL(18,2) NOT NULL DEFAULT 0,
  minimo_no_sujeto DECIMAL(18,2) NOT NULL DEFAULT 0,
  base_sujeta DECIMAL(18,2) NOT NULL DEFAULT 0,
  alicuota_aplicada DECIMAL(6,3) NOT NULL DEFAULT 0,
  importe_retencion DECIMAL(18,2) NOT NULL DEFAULT 0,
  
  -- Override
  tiene_override TINYINT(1) NOT NULL DEFAULT 0,
  override_alicuota DECIMAL(6,3) DEFAULT NULL,
  override_importe DECIMAL(18,2) DEFAULT NULL,
  override_motivo VARCHAR(255) DEFAULT NULL,
  override_usuario_id INT DEFAULT NULL,
  override_fecha DATETIME DEFAULT NULL,
  
  -- Snapshot de parámetros al confirmar
  snapshot_parametros JSON DEFAULT NULL,
  
  -- SICORE / SIRE
  sicore_cod_impuesto VARCHAR(5) DEFAULT NULL,
  sicore_cod_regimen VARCHAR(5) DEFAULT NULL,
  sicore_cod_comprobante VARCHAR(3) DEFAULT NULL,
  
  activo TINYINT(1) NOT NULL DEFAULT 1,
  
  FOREIGN KEY (liquidacion_id) REFERENCES liquidaciones(id) ON DELETE CASCADE,
  FOREIGN KEY (rg830_concepto_id) REFERENCES rg830_conceptos(id),
  FOREIGN KEY (rg830_vigencia_id) REFERENCES rg830_vigencias(id),
  FOREIGN KEY (override_usuario_id) REFERENCES usuarios(id),
  INDEX idx_li_liq (liquidacion_id),
  INDEX idx_li_impuesto (impuesto)
) ENGINE=InnoDB;

-- =========================
-- LOG DE LIQUIDACIONES (AUDITORÍA)
-- =========================
CREATE TABLE IF NOT EXISTS liquidacion_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  liquidacion_id INT NOT NULL,
  usuario_id INT NOT NULL,
  accion VARCHAR(50) NOT NULL,
  campo_modificado VARCHAR(100) DEFAULT NULL,
  valor_anterior TEXT DEFAULT NULL,
  valor_nuevo TEXT DEFAULT NULL,
  motivo VARCHAR(500) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (liquidacion_id) REFERENCES liquidaciones(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  INDEX idx_ll_liq (liquidacion_id),
  INDEX idx_ll_fecha (created_at)
) ENGINE=InnoDB;

-- =========================
-- EXPORTACIONES (SICORE / SIRE)
-- =========================
CREATE TABLE IF NOT EXISTS exportaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo ENUM('SICORE','SIRE_F2004') NOT NULL,
  periodo_desde DATE NOT NULL,
  periodo_hasta DATE NOT NULL,
  cantidad_registros INT NOT NULL DEFAULT 0,
  importe_total DECIMAL(18,2) NOT NULL DEFAULT 0,
  nombre_archivo VARCHAR(255) DEFAULT NULL,
  contenido_archivo LONGTEXT DEFAULT NULL,
  usuario_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  INDEX idx_exp_tipo (tipo),
  INDEX idx_exp_periodo (periodo_desde, periodo_hasta)
) ENGINE=InnoDB;

-- Detalle: qué liquidaciones se incluyeron en cada exportación
CREATE TABLE IF NOT EXISTS exportacion_liquidaciones (
  exportacion_id INT NOT NULL,
  liquidacion_id INT NOT NULL,
  liquidacion_item_id INT NOT NULL,
  PRIMARY KEY (exportacion_id, liquidacion_item_id),
  FOREIGN KEY (exportacion_id) REFERENCES exportaciones(id),
  FOREIGN KEY (liquidacion_id) REFERENCES liquidaciones(id),
  FOREIGN KEY (liquidacion_item_id) REFERENCES liquidacion_items(id)
) ENGINE=InnoDB;

-- =========================
-- NUMERACIÓN CERTIFICADOS DE RETENCIÓN
-- =========================
CREATE TABLE IF NOT EXISTS retencion_numeracion (
  id INT AUTO_INCREMENT PRIMARY KEY,
  anio INT NOT NULL,
  ultimo_numero INT NOT NULL DEFAULT 0,
  prefijo VARCHAR(10) DEFAULT NULL,
  UNIQUE KEY uk_rn_anio (anio)
) ENGINE=InnoDB;

-- =========================
-- IIBB – CATEGORÍAS DE RETENCIÓN (Res. 276/DPR/17 Neuquén, configurable)
-- =========================
CREATE TABLE IF NOT EXISTS iibb_categorias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(5) NOT NULL,
  descripcion VARCHAR(255) NOT NULL,
  alicuota DECIMAL(5,2) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  orden INT NOT NULL DEFAULT 0,
  UNIQUE KEY uk_iibb_cat_codigo (codigo)
) ENGINE=InnoDB;

-- =========================
-- IIBB – MÍNIMOS NO SUJETOS A RETENCIÓN (Art. 7)
-- =========================
CREATE TABLE IF NOT EXISTS iibb_minimos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo_agente VARCHAR(60) NOT NULL,
  descripcion VARCHAR(255) NOT NULL,
  minimo_no_sujeto DECIMAL(18,2) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_iibb_min_tipo (tipo_agente)
) ENGINE=InnoDB;

-- =========================
-- SEED: Conceptos RG 830 iniciales (Construcción)
-- =========================
INSERT INTO rg830_conceptos (codigo, inciso, descripcion, activo) VALUES
('GANANCIAS', 'j', 'Locaciones de obra y/o servicio no ejecutadas en relación de dependencia (Construcción)', 1),
('GANANCIAS', 'a', 'Intereses por operaciones financieras', 1),
('GANANCIAS', 'b', 'Alquileres o arrendamientos de bienes muebles', 1),
('GANANCIAS', 'c', 'Alquileres o arrendamientos de bienes inmuebles', 1),
('GANANCIAS', 'f', 'Enajenación de bienes muebles', 1),
('IVA', 'PAGO', 'Retención IVA a proveedores', 1),
('SUSS', 'PAGO', 'Retención SUSS - Contribuciones Seguridad Social', 1),
('IIBB', 'PAGO', 'Retención Ingresos Brutos - Obras de construcción', 1)
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion);

-- Vigencia default (valores orientativos, ajustar con RG vigente)
INSERT INTO rg830_vigencias (concepto_id, vigencia_desde, vigencia_hasta, porc_inscripto, porc_no_inscripto, minimo_no_sujeto, modo_calculo) 
SELECT id, '2024-01-01', NULL, 2.00, 28.00, 450000.00, 'PORCENTAJE_DIRECTO'
FROM rg830_conceptos WHERE codigo='GANANCIAS' AND inciso='j'
ON DUPLICATE KEY UPDATE porc_inscripto=VALUES(porc_inscripto);

INSERT INTO rg830_vigencias (concepto_id, vigencia_desde, vigencia_hasta, porc_inscripto, porc_no_inscripto, minimo_no_sujeto, modo_calculo) 
SELECT id, '2024-01-01', NULL, 10.50, 21.00, 0.00, 'PORCENTAJE_DIRECTO'
FROM rg830_conceptos WHERE codigo='IVA' AND inciso='PAGO'
ON DUPLICATE KEY UPDATE porc_inscripto=VALUES(porc_inscripto);

INSERT INTO rg830_vigencias (concepto_id, vigencia_desde, vigencia_hasta, porc_inscripto, porc_no_inscripto, minimo_no_sujeto, modo_calculo) 
SELECT id, '2024-01-01', NULL, 2.00, 2.00, 0.00, 'PORCENTAJE_DIRECTO'
FROM rg830_conceptos WHERE codigo='SUSS' AND inciso='PAGO'
ON DUPLICATE KEY UPDATE porc_inscripto=VALUES(porc_inscripto);

INSERT INTO rg830_vigencias (concepto_id, vigencia_desde, vigencia_hasta, porc_inscripto, porc_no_inscripto, minimo_no_sujeto, modo_calculo) 
SELECT id, '2024-01-01', NULL, 2.00, 4.00, 10000.00, 'PORCENTAJE_DIRECTO'
FROM rg830_conceptos WHERE codigo='IIBB' AND inciso='PAGO'
ON DUPLICATE KEY UPDATE porc_inscripto=VALUES(porc_inscripto);

-- SEED: Categorías IIBB – Resolución 276/DPR/17 Neuquén (Art. 11)
INSERT INTO iibb_categorias (codigo, descripcion, alicuota, activo, orden) VALUES
('b', 'Contribuyente directo Provincia del Neuquén', 2.00, 1, 1),
('c', 'Convenio Multilateral con sede en Neuquén', 1.50, 1, 2),
('d', 'Convenio Multilateral con sede en otra Provincia', 1.00, 1, 3),
('e', 'Pagos Dirección de Lotería (juegos de azar)', 3.00, 1, 4),
('f', 'Pagos mediante tarjetas de crédito/compra/pago', 1.00, 1, 5),
('g', 'Honorarios profesionales judiciales (Entidades Financieras)', 1.50, 1, 6),
('h', 'No acredita situación fiscal (Art. 11 inc. g)', 4.00, 1, 7)
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion), alicuota=VALUES(alicuota);

-- SEED: Mínimos no sujetos a retención IIBB (Art. 7)
INSERT INTO iibb_minimos (tipo_agente, descripcion, minimo_no_sujeto) VALUES
('ESTADO', 'Reparticiones Nac/Prov/Mun, organismos autárquicos, empresas del Estado', 10000.00),
('PRIVADO', 'Agentes de retención no incluidos en inciso anterior', 5000.00)
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion), minimo_no_sujeto=VALUES(minimo_no_sujeto);

SET FOREIGN_KEY_CHECKS = 1;
