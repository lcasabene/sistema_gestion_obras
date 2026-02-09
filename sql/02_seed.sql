-- Roles
INSERT INTO roles (nombre, descripcion) VALUES
('Admin','Administrador del sistema'),
('Tecnico','Carga de obras/curvas/certificados'),
('Presupuesto','Gestión presupuestaria/fuentes/reportes'),
('Auditor','Solo lectura/consulta');

-- Tipos de obra (base)
INSERT INTO tipos_obra (nombre) VALUES
('Rutas y pavimento'),
('Puentes / Obras de arte'),
('Escuelas / Educación'),
('Hospitales / Salud'),
('Agua potable'),
('Saneamiento / Cloacas'),
('Energía / Alumbrado'),
('Vivienda / Urbanización'),
('Edificios públicos'),
('Hidráulicas / Defensa civil'),
('Otros');

-- Estados de obra (base)
INSERT INTO estados_obra (nombre) VALUES
('Idea / Cartera'),
('En formulación'),
('En proceso de licitación'),
('Adjudicada / A iniciar'),
('En ejecución'),
('Paralizada'),
('Rescindida'),
('Finalizada (provisoria)'),
('Recepción definitiva / Cerrada');

-- Fuentes (ejemplos)
INSERT INTO fuentes_financiamiento (nombre, descripcion) VALUES
('Tesoro','Recursos del tesoro'),
('BID','Financiamiento BID'),
('CAF','Financiamiento CAF'),
('BIRF','Banco Mundial / BIRF'),
('Municipio','Aporte municipal'),
('Otros','Otras fuentes');

-- Usuario admin inicial (cambiar clave luego)
INSERT INTO usuarios (usuario, nombre, email, password_hash, activo)
VALUES ('admin', 'Administrador', NULL, '$2y$10$Vaxd0R0frFN5yVrBRPPFD.Yg.MjKEVbAGYUTcImDQtRnzqhUevgB6', 1);

-- Asignar rol Admin al usuario admin
INSERT INTO usuario_roles (usuario_id, rol_id)
SELECT u.id, r.id
FROM usuarios u
JOIN roles r ON r.nombre='Admin'
WHERE u.usuario='admin';
