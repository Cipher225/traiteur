-- ============================================================================
--  MIGRATIONS — évolutions du schéma appliquées SANS TOUCHER AUX DONNÉES.
--  Ce fichier est exécuté à chaque déploiement. Toutes les instructions sont
--  IDEMPOTENTES (IF NOT EXISTS) : les relancer plusieurs fois est sans danger.
--  MariaDB 10.2+ / MySQL 8 requis pour « ADD COLUMN IF NOT EXISTS ».
-- ============================================================================

-- Type de client (particulier / entreprise) + raison sociale
ALTER TABLE clients ADD COLUMN IF NOT EXISTS type_client ENUM('individuel','entreprise') DEFAULT 'individuel';
ALTER TABLE clients ADD COLUMN IF NOT EXISTS entreprise VARCHAR(150) DEFAULT '';
ALTER TABLE clients ADD COLUMN IF NOT EXISTS ncc VARCHAR(60) DEFAULT '';
ALTER TABLE clients ADD COLUMN IF NOT EXISTS notes TEXT;

-- Intervalle de prix indicatif par catégorie (affichage vitrine)
ALTER TABLE categories ADD COLUMN IF NOT EXISTS prix_min INT DEFAULT 0;
ALTER TABLE categories ADD COLUMN IF NOT EXISTS prix_max INT DEFAULT 0;

-- Géolocalisation / dernière connexion des utilisateurs
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_ip VARCHAR(64) DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_ville VARCHAR(120) DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_activity DATETIME NULL;

-- Harmonisation : tout client ayant une raison sociale devient « entreprise »
UPDATE clients SET type_client='entreprise'
  WHERE entreprise IS NOT NULL AND entreprise <> '' AND type_client <> 'entreprise';

-- Réglages email (envoi automatique)
INSERT IGNORE INTO settings (cle, valeur) VALUES
  ('smtp_hote',''), ('smtp_port','587'), ('smtp_user',''), ('smtp_pass',''),
  ('smtp_secure','tls'), ('emails_actifs','1');

-- =====================================================================
-- GESTION DE STOCK / INVENTAIRE (ingrédients et fournitures)
-- =====================================================================
CREATE TABLE IF NOT EXISTS stock_articles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(150) NOT NULL,
  categorie VARCHAR(80) DEFAULT 'Ingrédient',
  unite VARCHAR(30) DEFAULT 'unité',
  quantite DECIMAL(12,2) DEFAULT 0,
  seuil_alerte DECIMAL(12,2) DEFAULT 0,
  prix_unitaire DECIMAL(12,0) DEFAULT 0,
  fournisseur VARCHAR(150) DEFAULT '',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (categorie)
);
CREATE TABLE IF NOT EXISTS stock_mouvements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  article_id INT NOT NULL,
  type ENUM('entree','sortie') NOT NULL,
  quantite DECIMAL(12,2) NOT NULL,
  motif VARCHAR(200) DEFAULT '',
  date_mouvement DATE DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (article_id)
);

-- Identifiants Google (connexion OAuth)
INSERT IGNORE INTO settings (cle, valeur) VALUES ('google_client_id',''), ('google_client_secret','');

-- =====================================================================
-- REGISTRE DES MEMBRES EXTERNES (ajout idempotent)
-- =====================================================================
CREATE TABLE IF NOT EXISTS externes (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    reference     VARCHAR(40) NOT NULL UNIQUE,
    nom           VARCHAR(150) NOT NULL,
    organisation  VARCHAR(150) NULL,
    fonction      VARCHAR(150) NULL,
    telephone     VARCHAR(60) NULL,
    email         VARCHAR(190) NULL,
    adresse       VARCHAR(255) NULL,
    piece_identite VARCHAR(120) NULL,
    notes         TEXT NULL,
    nb_interventions INT NOT NULL DEFAULT 0,
    derniere_intervention DATE NULL,
    statut        ENUM('actif','inactif') NOT NULL DEFAULT 'actif',
    badge_id      INT NULL,
    cree_par      INT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY (statut), KEY (badge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS externes_interventions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    externe_id    INT NOT NULL,
    date_intervention DATE NOT NULL,
    objet         VARCHAR(255) NULL,
    lieu          VARCHAR(200) NULL,
    notes         TEXT NULL,
    cree_par      INT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY (externe_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (cle, valeur) VALUES ('externe_prefixe', 'REF');

-- Lien badge → fiche du registre des externes (traçabilité)
ALTER TABLE badges ADD COLUMN IF NOT EXISTS externe_id INT NULL AFTER employe_id;

-- =====================================================================
-- PRÉSERVATION DES DONNÉES À LA SUPPRESSION D'UN COMPTE
-- =====================================================================

-- Archive des anciens membres (garde le nom lié à un ancien user_id)
CREATE TABLE IF NOT EXISTS anciens_membres (
  user_id     INT PRIMARY KEY,
  nom         VARCHAR(150) NOT NULL,
  role        VARCHAR(30) DEFAULT NULL,
  archive_le  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Remplacer les CASCADE dangereux par SET NULL (tâches + rapports préservés).
-- On retire l'ancienne contrainte puis on la recrée en SET NULL, de façon sûre.
SET @db := DATABASE();

-- Tâches : assigne_a
SET @fk := (SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='taches' AND COLUMN_NAME='assigne_a' AND REFERENCED_TABLE_NAME='users' LIMIT 1);
SET @sql := IF(@fk IS NOT NULL, CONCAT('ALTER TABLE taches DROP FOREIGN KEY ', @fk), 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
ALTER TABLE taches ADD CONSTRAINT fk_taches_assigne FOREIGN KEY (assigne_a) REFERENCES users(id) ON DELETE SET NULL;

-- Rapports : employe_user_id
SET @fk2 := (SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='rapports' AND COLUMN_NAME='employe_user_id' AND REFERENCED_TABLE_NAME='users' LIMIT 1);
SET @sql2 := IF(@fk2 IS NOT NULL, CONCAT('ALTER TABLE rapports DROP FOREIGN KEY ', @fk2), 'SELECT 1');
PREPARE s2 FROM @sql2; EXECUTE s2; DEALLOCATE PREPARE s2;
ALTER TABLE rapports ADD CONSTRAINT fk_rapports_auteur FOREIGN KEY (employe_user_id) REFERENCES users(id) ON DELETE SET NULL;

-- =====================================================================
--  MISES À JOUR RÉCENTES — ajoutées automatiquement à chaque déploiement
--  Toutes ces instructions sont sans effet si elles ont déjà été appliquées.
-- =====================================================================

-- Champs communs aux documents : mode de paiement, activité, date et lieu
ALTER TABLE factures ADD COLUMN IF NOT EXISTS mode_paiement VARCHAR(60) DEFAULT '' AFTER statut;
ALTER TABLE factures ADD COLUMN IF NOT EXISTS activite VARCHAR(255) DEFAULT '' AFTER mode_paiement;
ALTER TABLE factures ADD COLUMN IF NOT EXISTS date_evenement DATE NULL AFTER activite;
ALTER TABLE factures ADD COLUMN IF NOT EXISTS lieu VARCHAR(255) DEFAULT '' AFTER date_evenement;
ALTER TABLE recus ADD COLUMN IF NOT EXISTS activite VARCHAR(255) DEFAULT '' AFTER motif;
ALTER TABLE recus ADD COLUMN IF NOT EXISTS date_evenement DATE NULL AFTER activite;
ALTER TABLE recus ADD COLUMN IF NOT EXISTS lieu VARCHAR(255) DEFAULT '' AFTER date_evenement;

-- Mention imprimée sur le bon de livraison
INSERT IGNORE INTO settings (cle, valeur) VALUES
 ('mention_livraison', 'Marchandises livrées et reçues en bon état, conformément au bon de commande.');

-- Traitement de texte : documents rédigés dans l'application
CREATE TABLE IF NOT EXISTS documents_texte (
  id INT AUTO_INCREMENT PRIMARY KEY,
  titre VARCHAR(200) NOT NULL,
  categorie VARCHAR(60) DEFAULT 'Document',
  contenu LONGTEXT,
  avec_entete TINYINT(1) DEFAULT 1,
  avec_signature TINYINT(1) DEFAULT 0,
  auteur_id INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (auteur_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Circuit de validation des documents rédigés
ALTER TABLE documents_texte ADD COLUMN IF NOT EXISTS statut ENUM('brouillon','termine','valide') DEFAULT 'brouillon' AFTER categorie;
ALTER TABLE documents_texte ADD COLUMN IF NOT EXISTS termine_le DATETIME NULL AFTER statut;
ALTER TABLE documents_texte ADD COLUMN IF NOT EXISTS termine_par INT DEFAULT NULL AFTER termine_le;
ALTER TABLE documents_texte ADD COLUMN IF NOT EXISTS valide_le DATETIME NULL AFTER termine_par;
ALTER TABLE documents_texte ADD COLUMN IF NOT EXISTS valide_par INT DEFAULT NULL AFTER valide_le;
ALTER TABLE documents_texte ADD COLUMN IF NOT EXISTS coffre_doc_id INT DEFAULT NULL AFTER valide_par;
ALTER TABLE documents_texte ADD COLUMN IF NOT EXISTS motif_refus VARCHAR(255) DEFAULT '' AFTER coffre_doc_id;

-- Paiements en ligne (Wave)
CREATE TABLE IF NOT EXISTS paiements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(40) NOT NULL UNIQUE,
  facture_id INT DEFAULT NULL,
  client_id INT DEFAULT NULL,
  montant DECIMAL(14,0) NOT NULL DEFAULT 0,
  devise VARCHAR(8) DEFAULT 'XOF',
  fournisseur VARCHAR(20) DEFAULT 'wave',
  session_id VARCHAR(120) DEFAULT '',
  transaction_id VARCHAR(120) DEFAULT '',
  statut ENUM('en_attente','paye','echoue','annule') DEFAULT 'en_attente',
  url_paiement TEXT,
  recu_id INT DEFAULT NULL,
  ip VARCHAR(64) DEFAULT '',
  detail TEXT,
  paye_le DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (session_id), INDEX (statut),
  FOREIGN KEY (facture_id) REFERENCES factures(id) ON DELETE SET NULL,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
);

INSERT IGNORE INTO settings (cle, valeur) VALUES
 ('wave_actif', '0'), ('wave_api_key', ''), ('wave_mode', 'test'),
 ('wave_webhook_secret', ''), ('wave_frais_client', '0');

-- Nouveaux reseaux sociaux
INSERT IGNORE INTO settings (cle, valeur) VALUES
 ('tiktok',''), ('youtube',''), ('linkedin',''), ('x','');

-- =====================================================================
--  SÉCURITÉ : protection anti-intrusion et journal des actions
-- =====================================================================

-- Tentatives de connexion (protection contre les essais en série)
CREATE TABLE IF NOT EXISTS tentatives_connexion (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(64) NOT NULL,
  identifiant VARCHAR(120) DEFAULT '',
  reussi TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (ip, created_at),
  INDEX (created_at)
);

-- Journal des actions sensibles (qui a fait quoi, et quand)
CREATE TABLE IF NOT EXISTS journal (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,
  acteur VARCHAR(120) DEFAULT '',
  role VARCHAR(20) DEFAULT '',
  action VARCHAR(60) NOT NULL,
  cible VARCHAR(60) DEFAULT '',
  cible_id INT DEFAULT NULL,
  detail VARCHAR(255) DEFAULT '',
  ip VARCHAR(64) DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (created_at), INDEX (action), INDEX (user_id)
);
