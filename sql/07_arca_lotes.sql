-- ============================================================
-- MIGRACIÓN 07: Lotes de importación ARCA
-- Permite rastrear y eliminar importaciones por lote
-- ============================================================

-- 1. Tabla de lotes
CREATE TABLE IF NOT EXISTS lotes_importacion_arca (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_archivo VARCHAR(255) NULL,
    fecha_importacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    usuario VARCHAR(100) NULL,
    total_importados INT NOT NULL DEFAULT 0,
    total_duplicados INT NOT NULL DEFAULT 0,
    total_errores INT NOT NULL DEFAULT 0,
    eliminado TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- 2. Columna lote_id en comprobantes_arca (NULL = importado antes de esta migración)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comprobantes_arca' AND COLUMN_NAME = 'lote_id');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE comprobantes_arca ADD COLUMN lote_id INT NULL AFTER id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
