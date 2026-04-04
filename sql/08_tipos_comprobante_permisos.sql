-- ============================================================
-- MIGRACIÓN 08: Tipos de comprobante AFIP + Permisos por módulo
-- ============================================================

-- 1. Tabla de tipos de comprobante AFIP
CREATE TABLE IF NOT EXISTS tipos_comprobante_arca (
    codigo VARCHAR(10) NOT NULL PRIMARY KEY,
    descripcion VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

-- Seed con códigos AFIP estándar (INSERT IGNORE = seguro para re-ejecutar)
INSERT IGNORE INTO tipos_comprobante_arca (codigo, descripcion) VALUES
('001', 'FACTURA A'),
('002', 'NOTA DE DEBITO A'),
('003', 'NOTA DE CREDITO A'),
('004', 'RECIBO A'),
('005', 'NOTAS DE VENTA AL CONTADO A'),
('006', 'FACTURA B'),
('007', 'NOTA DE DEBITO B'),
('008', 'NOTA DE CREDITO B'),
('009', 'RECIBO B'),
('010', 'NOTAS DE VENTA AL CONTADO B'),
('011', 'FACTURA C'),
('012', 'NOTA DE DEBITO C'),
('013', 'NOTA DE CREDITO C'),
('015', 'RECIBO C'),
('016', 'NOTAS DE VENTA AL CONTADO C'),
('019', 'FACTURAS DE EXPORTACION'),
('020', 'NOTA DE DEBITO POR OPERACIONES CON EL EXTERIOR'),
('021', 'NOTA DE CREDITO POR OPERACIONES CON EL EXTERIOR'),
('051', 'FACTURA M'),
('052', 'NOTA DE DEBITO M'),
('053', 'NOTA DE CREDITO M'),
('080', 'REMITO ELECTRONICO R'),
('081', 'REMITO ELECTRONICO X'),
('082', 'REMITO ELECTRONICO R DEL EXTERIOR'),
('110', 'TRAMITES Y/O SERVICIOS DE ADUANA'),
('111', 'CUENTA DE GASTOS'),
('112', 'LIQUIDACIONES'),
('113', 'LIQUIDACION PRIMARIA DE GRANOS'),
('114', 'LIQUIDACION SECUNDARIA DE GRANOS'),
('180', 'REGISTRO DE FACTURA DE CREDITO'),
('182', 'NOTA DE DEBITO CREDITO ELECTRONICA'),
('183', 'NOTA DE CREDITO CREDITO ELECTRONICA'),
('201', 'FACTURA DE CREDITO ELECTRONICA MiPyMEs A'),
('202', 'NOTA DE DEBITO ELECTRONICA MiPyMEs A'),
('203', 'NOTA DE CREDITO ELECTRONICA MiPyMEs A'),
('206', 'FACTURA DE CREDITO ELECTRONICA MiPyMEs B'),
('207', 'NOTA DE DEBITO ELECTRONICA MiPyMEs B'),
('208', 'NOTA DE CREDITO ELECTRONICA MiPyMEs B'),
('211', 'FACTURA DE CREDITO ELECTRONICA MiPyMEs C'),
('212', 'NOTA DE DEBITO ELECTRONICA MiPyMEs C'),
('213', 'NOTA DE CREDITO ELECTRONICA MiPyMEs C');

-- ============================================================
-- 2. Catálogo de módulos del sistema
-- ============================================================
CREATE TABLE IF NOT EXISTS modulos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clave VARCHAR(50) NOT NULL UNIQUE,
    nombre VARCHAR(100) NOT NULL,
    descripcion VARCHAR(200) NULL,
    icono VARCHAR(50) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

INSERT IGNORE INTO modulos (clave, nombre, descripcion, icono) VALUES
('obras',         'Gestión de Obras',       'ABM de obras, fichas y mapas',           'bi-building'),
('curva',         'Curva de Inversión',      'Generación y visualización de curvas',   'bi-graph-up'),
('certificados',  'Certificados',            'Emisión y seguimiento de certificados',  'bi-file-earmark-check'),
('liquidaciones', 'Liquidaciones',           'RG 830 - Retenciones impositivas',       'bi-receipt'),
('arca',          'Comprobantes ARCA',       'Importación y consulta de facturas AFIP','bi-cloud-download'),
('empresas',      'Empresas / Proveedores',  'ABM de empresas y UTEs',                 'bi-building-gear'),
('fuentes',       'Fuentes de Financiamiento','Gestión de fuentes y presupuesto',      'bi-bank'),
('obras_config',  'Config. Obras',           'Tipos, estados, organismos, regiones',   'bi-gear'),
('presupuesto',   'Presupuesto Ejecución',   'Importación CSV de ejecución presupuestaria','bi-table'),
('sicopro',       'SICOPRO',                 'Sistema de control de proyectos',        'bi-kanban'),
('admin',         'Administración',          'Usuarios, roles y permisos del sistema', 'bi-shield-lock');

-- ============================================================
-- 3. Tabla de permisos: qué roles acceden a qué módulos
-- ============================================================
CREATE TABLE IF NOT EXISTS rol_modulos (
    rol_id INT NOT NULL,
    modulo_clave VARCHAR(50) NOT NULL,
    PRIMARY KEY (rol_id, modulo_clave),
    FOREIGN KEY (rol_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- El rol ADMIN tiene acceso a todo (se gestiona en código, no en tabla)
