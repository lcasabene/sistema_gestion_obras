-- ============================================================
-- MIGRACIÓN 14: Número de documento en desembolsos y rendiciones
-- ============================================================

ALTER TABLE programa_desembolsos
    ADD COLUMN numero_documento VARCHAR(80) NULL AFTER moneda;

ALTER TABLE programa_rendiciones
    ADD COLUMN numero_documento VARCHAR(80) NULL AFTER total_contraparte;

ALTER TABLE programa_saldos
    ADD COLUMN numero_extracto VARCHAR(80) NULL AFTER saldo_moneda_nacional;
