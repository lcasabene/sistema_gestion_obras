-- ============================================================
-- MIGRACIÓN 12: Agregar datos_extra a programa_pagos_importados
-- Si aparece error "Duplicate column name" significa que la columna
-- ya existe (el schema original ya la incluía) → ignorar el error.
-- ============================================================

ALTER TABLE programa_pagos_importados
    ADD COLUMN datos_extra JSON NULL AFTER col_referencia;
