-- ============================================================
-- MIGRACIÓN 13: Campos extra en organismos_financiadores
-- Ejecutar cada ALTER por separado. Si da "Duplicate column name"
-- significa que ya existe → ignorar ese error y continuar.
-- ============================================================

ALTER TABLE organismos_financiadores ADD COLUMN sigla     VARCHAR(20)  NULL AFTER nombre_organismo;
ALTER TABLE organismos_financiadores ADD COLUMN pais      VARCHAR(80)  NULL AFTER sigla;
ALTER TABLE organismos_financiadores ADD COLUMN sitio_web VARCHAR(255) NULL AFTER pais;
