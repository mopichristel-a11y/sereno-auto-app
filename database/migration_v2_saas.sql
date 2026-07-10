-- ============================================================
--  SERENO SMS — Migration v1.1 → v2.0 (SaaS multi-garages)
--  À exécuter sur une base v1.1 existante.
--  Les nouvelles installations utilisent directement schema.sql.
-- ============================================================

-- ------------------------------------------------------------
-- TABLE : garages_sms — les garages abonnés à la plateforme
-- Plans : starter (25 000 F, 100 véhicules) ·
--         pro (50 000 F, 500 véhicules) ·
--         enterprise (100 000 F, illimité)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS garages_sms (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom             VARCHAR(150) NOT NULL,
    code            VARCHAR(20) NOT NULL UNIQUE,      -- ex: SAUT-YDE
    ville           VARCHAR(100),
    pays            VARCHAR(60) DEFAULT 'Cameroun',
    telephone       VARCHAR(20),
    email           VARCHAR(150),
    logo            VARCHAR(255),
    plan            ENUM('starter','pro','enterprise') NOT NULL DEFAULT 'starter',
    abonnement_fin  DATE,                              -- expiration de l'abonnement SaaS
    actif           TINYINT(1) NOT NULL DEFAULT 1,
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Garage fondateur (tenant n°1)
INSERT IGNORE INTO garages_sms (id, nom, code, ville, plan, abonnement_fin)
VALUES (1, 'SERENO AUTO Yaoundé', 'SAUT-YDE', 'Yaoundé', 'enterprise', '2099-12-31');

-- ------------------------------------------------------------
-- Colonne garage_id (tenant) :
--  - utilisateurs : NULL = super-admin plateforme
--  - clients : chaque client appartient à un garage
--    (véhicules, contrats, devis... héritent via le client)
--  - notifications : scoping direct pour les listes
-- ------------------------------------------------------------
ALTER TABLE utilisateurs
    ADD COLUMN garage_id INT UNSIGNED NULL AFTER role,
    ADD CONSTRAINT fk_user_garage FOREIGN KEY (garage_id) REFERENCES garages_sms(id) ON DELETE SET NULL;

ALTER TABLE clients
    ADD COLUMN garage_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
    ADD CONSTRAINT fk_client_garage FOREIGN KEY (garage_id) REFERENCES garages_sms(id),
    ADD INDEX idx_client_garage (garage_id);

ALTER TABLE notifications
    ADD COLUMN garage_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
    ADD CONSTRAINT fk_notif_garage FOREIGN KEY (garage_id) REFERENCES garages_sms(id),
    ADD INDEX idx_notif_garage (garage_id);

-- Rattacher les données existantes au garage fondateur
UPDATE utilisateurs SET garage_id = 1 WHERE garage_id IS NULL AND role != 'admin';
-- Le premier admin créé devient super-admin plateforme (garage_id NULL) :
-- s'il doit rester admin du garage 1, exécuter :
--   UPDATE utilisateurs SET garage_id = 1 WHERE role = 'admin';
