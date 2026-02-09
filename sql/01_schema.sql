SET FOREIGN_KEY_CHECKS = 0;

-- =========================
-- SEGURIDAD / LOGIN
-- =========================
CREATE TABLE roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(50) NOT NULL UNIQUE,
  descripcion VARCHAR(200),
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario VARCHAR(50) NOT NULL UNIQUE,
  nombre VARCHAR(120) NOT NULL,
  email VARCHAR(150),
  password_hash VARCHAR(255) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_login DATETIME,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE usuario_roles (
  usuario_id INT NOT NULL,
  rol_id INT NOT NULL,
  PRIMARY KEY (usuario_id, rol_id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (rol_id) REFERENCES roles(id)
) ENGINE=InnoDB;

-- =========================
-- CATALOGOS
-- =========================
CREATE TABLE tipos_obra (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(60) NOT NULL UNIQUE,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE estados_obra (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(60) NOT NULL UNIQUE,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE fuentes_financiamiento (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL UNIQUE,
  descripcion VARCHAR(200),
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- =========================
-- OBRAS
-- =========================
CREATE TABLE obras (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo_interno VARCHAR(50),
  expediente VARCHAR(80),
  denominacion VARCHAR(255) NOT NULL,
  tipo_obra_id INT NOT NULL,
  estado_obra_id INT NOT NULL,
  ubicacion VARCHAR(200),

  fecha_inicio DATE,
  fecha_fin_prevista DATE,
  plazo_dias_original INT,

  moneda ENUM('ARS','USD') NOT NULL DEFAULT 'ARS',
  monto_original DECIMAL(18,2) NOT NULL DEFAULT 0,
  monto_actualizado DECIMAL(18,2) NOT NULL DEFAULT 0,

  observaciones TEXT,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (tipo_obra_id) REFERENCES tipos_obra(id),
  FOREIGN KEY (estado_obra_id) REFERENCES estados_obra(id),
  INDEX idx_obras_estado (estado_obra_id),
  INDEX idx_obras_tipo (tipo_obra_id)
) ENGINE=InnoDB;

-- Permisos por obra (opcional, pero útil)
CREATE TABLE obra_usuarios (
  obra_id INT NOT NULL,
  usuario_id INT NOT NULL,
  tipo_relacion VARCHAR(30) NOT NULL DEFAULT 'Asignado',
  PRIMARY KEY (obra_id, usuario_id),
  INDEX idx_ou_usuario (usuario_id),
  CONSTRAINT fk_ou_obra FOREIGN KEY (obra_id) REFERENCES obras(id),
  CONSTRAINT fk_ou_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- Identificación presupuestaria (según Excel)
CREATE TABLE obra_partida (
  id INT AUTO_INCREMENT PRIMARY KEY,
  obra_id INT NOT NULL,

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
  ubge VARCHAR(20),
  defc VARCHAR(20),

  denominacion1 VARCHAR(255),
  denominacion2 VARCHAR(255),
  denominacion3 VARCHAR(255),

  imputacion_codigo VARCHAR(120),

  vigente_desde DATE,
  vigente_hasta DATE,
  activo TINYINT(1) NOT NULL DEFAULT 1,

  FOREIGN KEY (obra_id) REFERENCES obras(id),
  INDEX idx_op_obra (obra_id),
  INDEX idx_op_denom3 (denominacion3),
  INDEX idx_op_fufi (fufi)
) ENGINE=InnoDB;

-- Eventos de obra (adicionales, redeterminaciones, ampliaciones, etc.)
CREATE TABLE obra_eventos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  obra_id INT NOT NULL,
  tipo_evento ENUM(
    'ADICIONAL',
    'DEDUCTIVO',
    'REDETERMINACION',
    'AMPLIACION_PLAZO',
    'REPROGRAMACION',
    'OTRO'
  ) NOT NULL,
  fecha DATE NOT NULL,
  acto_admin VARCHAR(120),
  monto DECIMAL(18,2) NOT NULL DEFAULT 0,
  dias_plazo INT NOT NULL DEFAULT 0,
  observacion TEXT,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (obra_id) REFERENCES obras(id),
  INDEX idx_oe_obra (obra_id),
  INDEX idx_oe_tipo (tipo_evento),
  INDEX idx_oe_fecha (fecha)
) ENGINE=InnoDB;

-- Vedas climáticas (inicio/fin obligatorios)
CREATE TABLE vedas_climaticas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  obra_id INT NOT NULL,

  fecha_desde DATE NOT NULL,
  fecha_hasta DATE NOT NULL,

  tipo VARCHAR(50) NOT NULL,
  afecta_plazo TINYINT(1) NOT NULL DEFAULT 0,
  dias_computables INT,
  acto_admin VARCHAR(120),
  observacion TEXT,

  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (obra_id) REFERENCES obras(id),
  INDEX idx_vc_obra (obra_id),
  INDEX idx_vc_rango (fecha_desde, fecha_hasta)
) ENGINE=InnoDB;

-- Curvas (versionadas)
CREATE TABLE curva_version (
  id INT AUTO_INCREMENT PRIMARY KEY,
  obra_id INT NOT NULL,
  nro_version INT NOT NULL DEFAULT 1,
  motivo VARCHAR(120),
  modo ENUM('S','LINEAL','MANUAL') NOT NULL DEFAULT 'S',
  fecha_desde DATE,
  fecha_hasta DATE,
  es_vigente TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (obra_id) REFERENCES obras(id),
  UNIQUE KEY uk_cv_obra_version (obra_id, nro_version),
  INDEX idx_cv_obra (obra_id),
  INDEX idx_cv_vigente (obra_id, es_vigente)
) ENGINE=InnoDB;

CREATE TABLE curva_detalle (
  id INT AUTO_INCREMENT PRIMARY KEY,
  curva_version_id INT NOT NULL,
  periodo CHAR(7) NOT NULL, -- YYYY-MM
  porcentaje_plan DECIMAL(6,3) NOT NULL DEFAULT 0,
  monto_plan DECIMAL(18,2),
  FOREIGN KEY (curva_version_id) REFERENCES curva_version(id),
  UNIQUE KEY uk_cd_periodo (curva_version_id, periodo),
  INDEX idx_cd_periodo (periodo)
) ENGINE=InnoDB;

-- Facturas
CREATE TABLE facturas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  proveedor VARCHAR(160),
  tipo VARCHAR(20),
  numero VARCHAR(40),
  fecha DATE,
  cae VARCHAR(40),
  monto DECIMAL(18,2) NOT NULL DEFAULT 0,
  archivo_pdf VARCHAR(255),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Certificados
CREATE TABLE certificados (
  id INT AUTO_INCREMENT PRIMARY KEY,
  obra_id INT NOT NULL,
  nro INT NOT NULL,
  periodo CHAR(7) NOT NULL,
  fecha_cert DATE NOT NULL,

  avance_fisico_periodo DECIMAL(6,3) NOT NULL DEFAULT 0,
  avance_fisico_acum DECIMAL(6,3) NOT NULL DEFAULT 0,

  monto_certificado DECIMAL(18,2) NOT NULL DEFAULT 0,
  anticipo_desc DECIMAL(18,2) NOT NULL DEFAULT 0,
  fondo_reparo DECIMAL(18,2) NOT NULL DEFAULT 0,
  multas DECIMAL(18,2) NOT NULL DEFAULT 0,
  otros_desc DECIMAL(18,2) NOT NULL DEFAULT 0,
  importe_a_pagar DECIMAL(18,2) NOT NULL DEFAULT 0,

  factura_id INT,
  estado ENUM('CARGADO','REVISADO','APROBADO','PAGADO','ANULADO') NOT NULL DEFAULT 'CARGADO',
  observaciones TEXT,
  activo TINYINT(1) NOT NULL DEFAULT 1,

  FOREIGN KEY (obra_id) REFERENCES obras(id),
  FOREIGN KEY (factura_id) REFERENCES facturas(id),
  UNIQUE KEY uk_cert_obra_nro (obra_id, nro),
  INDEX idx_cert_obra_periodo (obra_id, periodo),
  INDEX idx_cert_estado (estado)
) ENGINE=InnoDB;

-- Prorrateo por fuente (por certificado)
CREATE TABLE certificado_fuente (
  id INT AUTO_INCREMENT PRIMARY KEY,
  certificado_id INT NOT NULL,
  fuente_id INT NOT NULL,
  porcentaje DECIMAL(6,3),
  monto_asignado DECIMAL(18,2),
  FOREIGN KEY (certificado_id) REFERENCES certificados(id),
  FOREIGN KEY (fuente_id) REFERENCES fuentes_financiamiento(id),
  UNIQUE KEY uk_cf (certificado_id, fuente_id)
) ENGINE=InnoDB;

-- Pagos
CREATE TABLE pagos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  certificado_id INT NOT NULL,
  nro_op VARCHAR(50),
  fecha_pago DATE,
  medio VARCHAR(50),
  importe_pagado DECIMAL(18,2) NOT NULL DEFAULT 0,
  estado ENUM('EMITIDO','PAGADO','ANULADO') NOT NULL DEFAULT 'EMITIDO',
  observaciones TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (certificado_id) REFERENCES certificados(id),
  INDEX idx_pago_cert (certificado_id),
  INDEX idx_pago_fecha (fecha_pago)
) ENGINE=InnoDB;

-- Auditoría
CREATE TABLE auditoria_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT,
  entidad VARCHAR(50) NOT NULL,
  entidad_id BIGINT NOT NULL,
  accion VARCHAR(30) NOT NULL,
  detalle TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_al_entidad (entidad, entidad_id),
  INDEX idx_al_usuario (usuario_id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
