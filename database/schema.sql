-- ============================================================
--  SERENO AUTO MANAGEMENT SYSTEM — Base de données complète
--  Version 1.0 | MySQL 8.0+
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- TABLE : garages_sms — garages abonnés à la plateforme (SaaS)
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
-- TABLE : utilisateurs (auth + rôles)
-- garage_id NULL = super-admin plateforme (multi-garages)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS utilisateurs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom           VARCHAR(100) NOT NULL,
    prenom        VARCHAR(100) NOT NULL,
    email         VARCHAR(150) NOT NULL UNIQUE,
    telephone     VARCHAR(20),
    mot_de_passe  VARCHAR(255) NOT NULL,             -- bcrypt hash
    role          ENUM('admin','commercial','technicien','client') NOT NULL DEFAULT 'client',
    garage_id     INT UNSIGNED,                      -- NULL = super-admin plateforme
    actif         TINYINT(1) NOT NULL DEFAULT 1,
    photo         VARCHAR(255),
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (garage_id) REFERENCES garages_sms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TABLE : refresh_tokens (sécurité JWT)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    utilisateur_id INT UNSIGNED NOT NULL,
    token         VARCHAR(512) NOT NULL UNIQUE,
    expire_le     DATETIME NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TABLE : clients
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clients (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    garage_id       INT UNSIGNED NOT NULL DEFAULT 1, -- tenant : garage propriétaire
    utilisateur_id  INT UNSIGNED,                    -- si le client a un compte app
    nom             VARCHAR(150) NOT NULL,
    telephone       VARCHAR(20) NOT NULL,
    email           VARCHAR(150),
    adresse         TEXT,
    piece_identite  VARCHAR(50),                     -- CNI, passeport...
    num_piece       VARCHAR(50),
    profession      VARCHAR(100),
    type_client     ENUM('particulier','entreprise') DEFAULT 'particulier',
    created_by      INT UNSIGNED,                    -- commercial qui a créé
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (garage_id) REFERENCES garages_sms(id),
    FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    INDEX idx_client_garage (garage_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TABLE : vehicules
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vehicules (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED NOT NULL,
    marque          VARCHAR(80) NOT NULL,
    modele          VARCHAR(80) NOT NULL,
    annee           YEAR,
    immatriculation VARCHAR(30),
    vin             VARCHAR(50),
    kilometrage     INT UNSIGNED DEFAULT 0,
    couleur         VARCHAR(40),
    carburant       ENUM('essence','diesel','électrique','hybride','gaz') DEFAULT 'essence',
    boite_vitesses  ENUM('manuelle','automatique','CVT') DEFAULT 'manuelle',
    date_achat      DATE,
    photo_1         VARCHAR(255),
    photo_2         VARCHAR(255),
    photo_3         VARCHAR(255),
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TABLE : formules_csa (Essentiel / Confort / Premium)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS formules_csa (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom                 ENUM('essentiel','confort','premium') NOT NULL UNIQUE,
    mensualite          DECIMAL(10,2) NOT NULL,
    couverture_pct      TINYINT UNSIGNED NOT NULL,   -- 40 / 60 / 80
    description         TEXT,
    garanties           JSON,                        -- liste des garanties incluses
    actif               TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Données initiales
INSERT IGNORE INTO formules_csa (nom, mensualite, couverture_pct, description, garanties) VALUES
('essentiel', 15000, 40, 'Couverture de base pour les entretiens essentiels',
 '["Vidange","Filtres","Contrôle général"]'),
('confort', 28000, 60, 'Couverture étendue incluant les pièces courantes',
 '["Vidange","Filtres","Plaquettes","Pneus","Batterie","Contrôle général"]'),
('premium', 45000, 80, 'Couverture maximale toutes interventions',
 '["Vidange","Filtres","Plaquettes","Disques","Pneus","Batterie","Amortisseurs","Courroie","Climatisation","Diagnostic","Réparations majeures"]');

-- ------------------------------------------------------------
-- TABLE : contrats_csa
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contrats_csa (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference       VARCHAR(30) NOT NULL UNIQUE,     -- ex: CSA-2026-0041
    client_id       INT UNSIGNED NOT NULL,
    vehicule_id     INT UNSIGNED NOT NULL,
    formule_id      INT UNSIGNED NOT NULL,
    date_debut      DATE NOT NULL,
    date_expiration DATE NOT NULL,
    mensualite      DECIMAL(10,2) NOT NULL,
    couverture_pct  TINYINT UNSIGNED NOT NULL,
    statut          ENUM('actif','expiré','résilié','suspendu') DEFAULT 'actif',
    commercial_id   INT UNSIGNED,                    -- commercial signataire
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id)    REFERENCES clients(id),
    FOREIGN KEY (vehicule_id)  REFERENCES vehicules(id),
    FOREIGN KEY (formule_id)   REFERENCES formules_csa(id),
    FOREIGN KEY (commercial_id) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TABLE : paiements
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS paiements (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contrat_id      INT UNSIGNED NOT NULL,
    montant         DECIMAL(10,2) NOT NULL,
    date_paiement   DATE NOT NULL,
    moyen           ENUM('orange_money','mtn_momo','carte','espèces','virement') NOT NULL,
    reference_paiement VARCHAR(100),
    statut          ENUM('payé','en_attente','échoué') DEFAULT 'en_attente',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contrat_id) REFERENCES contrats_csa(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TABLE : diagnostics
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS diagnostics (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference       VARCHAR(30) NOT NULL UNIQUE,     -- ex: DIAG-2026-003
    vehicule_id     INT UNSIGNED NOT NULL,
    technicien_id   INT UNSIGNED,
    date_diagnostic DATETIME NOT NULL,
    outil           VARCHAR(80),                     -- Car Scanner, Launch X431...
    kilometrage     INT UNSIGNED,
    codes_dtc       JSON,                            -- [{code, description, statut}]
    commentaires    TEXT,
    photos          JSON,                            -- URLs photos
    statut          ENUM('en_cours','terminé','devis_envoyé') DEFAULT 'en_cours',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicule_id)   REFERENCES vehicules(id) ON DELETE CASCADE,
    FOREIGN KEY (technicien_id) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TABLE : devis
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS devis (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference       VARCHAR(30) NOT NULL UNIQUE,     -- ex: DEVIS-SAUT-20260703-001
    diagnostic_id   INT UNSIGNED,
    client_id       INT UNSIGNED NOT NULL,
    vehicule_id     INT UNSIGNED NOT NULL,
    lignes          JSON NOT NULL,                   -- [{designation, montant}]
    sous_total      DECIMAL(10,2) NOT NULL,
    main_oeuvre_pct TINYINT UNSIGNED DEFAULT 10,
    main_oeuvre     DECIMAL(10,2) NOT NULL,
    total           DECIMAL(10,2) NOT NULL,
    statut          ENUM('brouillon','envoyé','accepté','refusé','expiré') DEFAULT 'brouillon',
    validite_jours  TINYINT UNSIGNED DEFAULT 30,
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (diagnostic_id) REFERENCES diagnostics(id) ON DELETE SET NULL,
    FOREIGN KEY (client_id)     REFERENCES clients(id),
    FOREIGN KEY (vehicule_id)   REFERENCES vehicules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TABLE : interventions (réparations)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS interventions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference       VARCHAR(30) NOT NULL UNIQUE,
    devis_id        INT UNSIGNED,
    vehicule_id     INT UNSIGNED NOT NULL,
    technicien_id   INT UNSIGNED,
    date_debut      DATETIME,
    date_fin        DATETIME,
    statut          ENUM('planifié','en_cours','terminé','annulé') DEFAULT 'planifié',
    rapport         TEXT,
    photos          JSON,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (devis_id)      REFERENCES devis(id) ON DELETE SET NULL,
    FOREIGN KEY (vehicule_id)   REFERENCES vehicules(id),
    FOREIGN KEY (technicien_id) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TABLE : carnet_entretien
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS carnet_entretien (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicule_id     INT UNSIGNED NOT NULL,
    intervention_id INT UNSIGNED,
    type_entretien  ENUM(
        'vidange','filtre_air','filtre_habitacle','filtre_carburant','filtre_boite',
        'plaquettes','disques','pneus','batterie','amortisseurs',
        'courroie','climatisation','diagnostic','reparation_autre'
    ) NOT NULL,
    date_intervention DATE NOT NULL,
    kilometrage     INT UNSIGNED,
    prochain_km     INT UNSIGNED,
    prochaine_date  DATE,
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicule_id)   REFERENCES vehicules(id) ON DELETE CASCADE,
    FOREIGN KEY (intervention_id) REFERENCES interventions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TABLE : rendez_vous
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rendez_vous (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED NOT NULL,
    vehicule_id     INT UNSIGNED NOT NULL,
    date_rdv        DATETIME NOT NULL,
    motif           VARCHAR(255),
    statut          ENUM('confirmé','en_attente','annulé','terminé') DEFAULT 'en_attente',
    notes           TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id)   REFERENCES clients(id),
    FOREIGN KEY (vehicule_id) REFERENCES vehicules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TABLE : notifications
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    garage_id       INT UNSIGNED NOT NULL DEFAULT 1, -- tenant
    destinataire_id INT UNSIGNED,                    -- utilisateur interne (nullable)
    client_id       INT UNSIGNED,                    -- ou client final (nullable)
    type            ENUM('vidange','filtre','pneus','batterie','assurance',
                         'visite_technique','csa_expiration','paiement','autre') NOT NULL,
    titre           VARCHAR(200) NOT NULL,
    message         TEXT NOT NULL,
    canal           SET('whatsapp','sms','email','app') DEFAULT 'app',
    lu              TINYINT(1) DEFAULT 0,
    envoye          TINYINT(1) DEFAULT 0,
    date_envoi      DATETIME,
    erreur_envoi    VARCHAR(255),
    -- clé de déduplication des rappels automatiques (ex: csa_expiration-12-J30)
    cle_unique      VARCHAR(120) UNIQUE,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (garage_id)       REFERENCES garages_sms(id),
    FOREIGN KEY (destinataire_id) REFERENCES utilisateurs(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id)       REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_notif_envoye (envoye),
    INDEX idx_notif_lu (lu),
    INDEX idx_notif_garage (garage_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TABLE : garages_partenaires
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS garages_partenaires (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom             VARCHAR(150) NOT NULL,
    adresse         TEXT,
    telephone       VARCHAR(20),
    email           VARCHAR(150),
    specialites     JSON,
    latitude        DECIMAL(10,8),
    longitude       DECIMAL(11,8),
    note            DECIMAL(3,2) DEFAULT 0.00,
    actif           TINYINT(1) DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
