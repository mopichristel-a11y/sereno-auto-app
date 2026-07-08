-- ============================================================
--  SERENO SMS — Migration v1.0 → v1.1
--  À exécuter UNIQUEMENT sur une base créée avec le schema v1.0.
--  Les nouvelles installations utilisent directement schema.sql.
-- ============================================================

ALTER TABLE notifications
    MODIFY destinataire_id INT UNSIGNED NULL,
    ADD COLUMN client_id INT UNSIGNED NULL AFTER destinataire_id,
    ADD COLUMN erreur_envoi VARCHAR(255) NULL AFTER date_envoi,
    ADD COLUMN cle_unique VARCHAR(120) NULL UNIQUE AFTER erreur_envoi,
    ADD CONSTRAINT fk_notif_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    ADD INDEX idx_notif_envoye (envoye),
    ADD INDEX idx_notif_lu (lu);
