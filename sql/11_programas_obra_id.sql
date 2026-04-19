-- ============================================================
-- MIGRACIÓN 11: Agregar obra_id a desembolsos, rendiciones y saldos
-- ============================================================

ALTER TABLE programa_desembolsos
    ADD COLUMN obra_id INT NULL AFTER programa_id,
    ADD CONSTRAINT fk_desemb_obra FOREIGN KEY (obra_id) REFERENCES obras(id) ON DELETE SET NULL,
    ADD INDEX idx_desemb_obra (obra_id);

ALTER TABLE programa_rendiciones
    ADD COLUMN obra_id INT NULL AFTER programa_id,
    ADD CONSTRAINT fk_rend_obra FOREIGN KEY (obra_id) REFERENCES obras(id) ON DELETE SET NULL,
    ADD INDEX idx_rend_obra (obra_id);

ALTER TABLE programa_saldos
    ADD COLUMN obra_id INT NULL AFTER programa_id,
    ADD CONSTRAINT fk_saldo_obra FOREIGN KEY (obra_id) REFERENCES obras(id) ON DELETE SET NULL,
    ADD INDEX idx_saldo_obra (obra_id);
