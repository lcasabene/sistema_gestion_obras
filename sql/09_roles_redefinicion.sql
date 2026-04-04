-- ============================================================
-- MIGRACIÓN 09: Redefinición de roles del sistema
-- Roles: Consulta (solo lectura) | Editor (lectura + escritura) | Admin (total)
-- ============================================================

-- 1. Insertar los tres roles estándar (si no existen)
INSERT IGNORE INTO roles (nombre, descripcion) VALUES
('Consulta', 'Solo lectura: puede ver datos pero no crear, editar ni eliminar'),
('Editor',   'Lectura y escritura: puede crear y editar registros en los módulos asignados'),
('Admin',    'Acceso total: gestión de usuarios, configuración técnica y eliminación de registros');

-- 2. Actualizar descripción del rol Admin si ya existía con otro texto
UPDATE roles SET descripcion = 'Acceso total: gestión de usuarios, configuración técnica y eliminación de registros'
WHERE nombre = 'Admin';

-- 3. Migrar usuarios con roles antiguos → roles nuevos
--    Tecnico → Editor
UPDATE usuario_roles ur
JOIN roles r_viejo ON r_viejo.id = ur.rol_id AND r_viejo.nombre = 'Tecnico'
JOIN roles r_nuevo ON r_nuevo.nombre = 'Editor'
SET ur.rol_id = r_nuevo.id;

--    Presupuesto → Editor
UPDATE usuario_roles ur
JOIN roles r_viejo ON r_viejo.id = ur.rol_id AND r_viejo.nombre = 'Presupuesto'
JOIN roles r_nuevo ON r_nuevo.nombre = 'Editor'
SET ur.rol_id = r_nuevo.id;

--    Auditor → Consulta
UPDATE usuario_roles ur
JOIN roles r_viejo ON r_viejo.id = ur.rol_id AND r_viejo.nombre = 'Auditor'
JOIN roles r_nuevo ON r_nuevo.nombre = 'Consulta'
SET ur.rol_id = r_nuevo.id;

-- 4. Eliminar roles obsoletos (solo si ya no tienen usuarios asignados)
DELETE FROM roles WHERE nombre IN ('Tecnico', 'Presupuesto', 'Auditor')
  AND id NOT IN (SELECT DISTINCT rol_id FROM usuario_roles);

-- 5. Agregar columna 'nivel' a roles para ordenar y controlar capacidades
SET @col_existe = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'nivel');
SET @sql = IF(@col_existe = 0,
    'ALTER TABLE roles ADD COLUMN nivel TINYINT NOT NULL DEFAULT 1 AFTER descripcion',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- nivel: 1=Consulta, 2=Editor, 3=Admin
UPDATE roles SET nivel = 1 WHERE nombre = 'Consulta';
UPDATE roles SET nivel = 2 WHERE nombre = 'Editor';
UPDATE roles SET nivel = 3 WHERE nombre = 'Admin';
