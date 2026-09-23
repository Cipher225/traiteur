-- ============================================================================
--  MIGRATIONS — évolutions du schéma appliquées SANS TOUCHER AUX DONNÉES.
--  Ce fichier est exécuté à chaque déploiement. Toutes les instructions sont
--  IDEMPOTENTES (IF NOT EXISTS) : les relancer plusieurs fois est sans danger.
--  MariaDB 10.2+ / MySQL 8 requis pour « ADD COLUMN IF NOT EXISTS ».
-- ============================================================================

-- Le client d'import doit parler UTF-8 : sans cette ligne, un client
-- configuré en latin1 enregistre « événement » sous la forme « Ã©vÃ©nement »
-- et les accents restent abîmés dans toute l'application.
SET NAMES utf8mb4;

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

-- Relances des factures impayées
CREATE TABLE IF NOT EXISTS relances (
  id INT AUTO_INCREMENT PRIMARY KEY,
  facture_id INT DEFAULT NULL,
  client_id INT DEFAULT NULL,
  niveau TINYINT DEFAULT 1,
  montant DECIMAL(14,0) DEFAULT 0,
  destinataire VARCHAR(190) DEFAULT '',
  origine VARCHAR(20) DEFAULT 'manuelle',
  succes TINYINT(1) DEFAULT 1,
  envoye_le TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (facture_id), INDEX (envoye_le),
  FOREIGN KEY (facture_id) REFERENCES factures(id) ON DELETE CASCADE,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
);

INSERT IGNORE INTO settings (cle, valeur) VALUES ('relances_actives', '0');

-- Fiche personnelle d'un titulaire de compte : elle sert aux badges et cartes,
-- mais n'a pas à figurer dans la liste du personnel ni dans la paie.
ALTER TABLE employes ADD COLUMN IF NOT EXISTS fiche_perso TINYINT(1) DEFAULT 0;

-- Chaque entrée / sortie de caisse est reliée à son écriture comptable :
-- l'écriture suit la modification et disparaît avec le document.
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS recu_id INT DEFAULT NULL;

-- Reprise : les entrées / sorties déjà saisies avant cette mise à jour reçoivent
-- leur écriture comptable. Sans doublon possible grâce au NOT EXISTS.
INSERT INTO transactions (type, categorie, libelle, montant, mode_paiement, client_id, date_operation, notes, recu_id)
SELECT
  CASE WHEN r.type = 'entree' THEN 'entree' ELSE 'depense' END,
  CASE WHEN r.type = 'entree' THEN 'Ventes' ELSE 'Achats' END,
  CONCAT(CASE WHEN r.type = 'entree' THEN 'Entrée ' ELSE 'Sortie ' END, r.numero,
         CASE WHEN COALESCE(r.motif,'') <> '' THEN CONCAT(' — ', r.motif) ELSE '' END),
  r.montant, COALESCE(r.mode_paiement,'Espèces'), r.client_id, r.date_paiement,
  CASE WHEN COALESCE(r.activite,'') <> '' THEN CONCAT('Activité : ', r.activite) ELSE '' END,
  r.id
FROM recus r
WHERE NOT EXISTS (SELECT 1 FROM transactions t WHERE t.recu_id = r.id);

-- Durée d'une activité : certaines prestations se déroulent sur plusieurs jours.
ALTER TABLE factures ADD COLUMN IF NOT EXISTS nb_jours SMALLINT DEFAULT 1 AFTER date_evenement;
ALTER TABLE recus ADD COLUMN IF NOT EXISTS nb_jours SMALLINT DEFAULT 1 AFTER date_evenement;

-- =====================================================================
--  ANNUAIRE TÉLÉPHONIQUE
--  Plusieurs interlocuteurs peuvent exister chez un même client : le
--  gérant, la personne qui commande, celle qui règle les factures…
-- =====================================================================
CREATE TABLE IF NOT EXISTS contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT DEFAULT NULL,
  nom VARCHAR(120) NOT NULL,
  poste VARCHAR(100) DEFAULT '',
  telephone VARCHAR(40) DEFAULT '',
  telephone2 VARCHAR(40) DEFAULT '',
  whatsapp VARCHAR(40) DEFAULT '',
  email VARCHAR(190) DEFAULT '',
  adresse VARCHAR(255) DEFAULT '',
  principal TINYINT(1) DEFAULT 0,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (client_id), INDEX (nom),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);

-- Reprise : chaque client déjà enregistré devient son contact principal.
INSERT INTO contacts (client_id, nom, poste, telephone, email, adresse, principal)
SELECT c.id, c.nom,
       CASE WHEN COALESCE(c.entreprise,'') <> '' THEN 'Contact principal' ELSE '' END,
       COALESCE(c.telephone,''), COALESCE(c.email,''), COALESCE(c.adresse,''), 1
FROM clients c
WHERE NOT EXISTS (SELECT 1 FROM contacts k WHERE k.client_id = c.id AND k.principal = 1);

-- Numérotation propre au bon de livraison : il ne reprend plus le numéro de la
-- facture. Le numéro est attribué à la première édition du bon, puis conservé.
ALTER TABLE factures ADD COLUMN IF NOT EXISTS bl_numero VARCHAR(40) DEFAULT NULL;

-- Préfixes de numérotation manquants : tous doivent être modifiables
-- depuis Paramètres → Facturation.
INSERT IGNORE INTO settings (cle, valeur) VALUES
 ('prefixe_entree',    'BE'),
 ('prefixe_sortie',    'BS'),
 ('prefixe_livraison', 'BL'),
 ('prefixe_rapport',   'RAP');

-- =====================================================================
--  MESSAGERIE SORTANTE
--  Chaque message envoyé est consigné avec sa référence et son empreinte :
--  c'est ce qui permet au destinataire de vérifier son authenticité.
-- =====================================================================
CREATE TABLE IF NOT EXISTS emails_envoyes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(30) NOT NULL UNIQUE,
  empreinte VARCHAR(20) NOT NULL,
  destinataire VARCHAR(190) NOT NULL,
  destinataire_nom VARCHAR(150) DEFAULT '',
  client_id INT DEFAULT NULL,
  sujet VARCHAR(255) NOT NULL,
  corps LONGTEXT,
  piece_jointe VARCHAR(190) DEFAULT '',
  envoye_par INT DEFAULT NULL,
  envoye_par_nom VARCHAR(120) DEFAULT '',
  statut ENUM('envoye','echoue') DEFAULT 'envoye',
  erreur VARCHAR(255) DEFAULT '',
  envoye_le TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (reference), INDEX (destinataire), INDEX (envoye_le),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
);

-- Fournisseur de messagerie retenu (Gmail, Hostinger, Outlook…)
INSERT IGNORE INTO settings (cle, valeur) VALUES ('fournisseur_mail','');

-- Authentification des messages : activable au cas par cas.
ALTER TABLE emails_envoyes ADD COLUMN IF NOT EXISTS authentifie TINYINT(1) DEFAULT 1;
INSERT IGNORE INTO settings (cle, valeur) VALUES ('signature_auth_defaut','1');

-- Année de création de l'entreprise, affichée au bas des emails.
INSERT IGNORE INTO settings (cle, valeur) VALUES ('annee_fondation','2017');

-- Un message rédigé par un employé attend l'accord de l'administrateur.
ALTER TABLE emails_envoyes
  MODIFY COLUMN statut ENUM('envoye','echoue','en_attente','refuse') DEFAULT 'envoye';
ALTER TABLE emails_envoyes ADD COLUMN IF NOT EXISTS approuve_par INT DEFAULT NULL;
ALTER TABLE emails_envoyes ADD COLUMN IF NOT EXISTS approuve_le DATETIME DEFAULT NULL;
ALTER TABLE emails_envoyes ADD COLUMN IF NOT EXISTS pieces_ref TEXT;

-- Fichiers joints par un employé : conservés jusqu'à la décision de l'admin.
ALTER TABLE emails_envoyes ADD COLUMN IF NOT EXISTS fichiers TEXT;

-- =====================================================================
--  RENTABILITÉ PAR ACTIVITÉ
--  Une dépense n'a pas toujours de client (loyer, salaires, carburant).
--  En revanche, elle se rattache souvent à une prestation précise : on
--  peut alors comparer ce qu'une activité a rapporté et ce qu'elle a
--  coûté, et connaître son bénéfice réel.
-- =====================================================================
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS facture_id INT DEFAULT NULL;
ALTER TABLE recus       ADD COLUMN IF NOT EXISTS categorie VARCHAR(80) DEFAULT '';

-- Reprise : les écritures déjà rattachées à un reçu héritent de son activité.
UPDATE transactions t
  JOIN recus r ON r.id = t.recu_id
   SET t.facture_id = r.facture_id
 WHERE t.facture_id IS NULL AND r.facture_id IS NOT NULL;

-- =====================================================================
--  CHARGES RÉCURRENTES
--  Le loyer, les salaires ou l'abonnement Internet reviennent chaque
--  mois pour le même montant. Les saisir douze fois par an est une
--  perte de temps et une source d'oubli : on les décrit une fois, et
--  l'application propose de les enregistrer d'un clic.
-- =====================================================================
CREATE TABLE IF NOT EXISTS charges_recurrentes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  libelle VARCHAR(160) NOT NULL,
  categorie VARCHAR(80) NOT NULL DEFAULT 'Divers',
  montant DECIMAL(14,2) NOT NULL DEFAULT 0,
  mode_paiement VARCHAR(40) DEFAULT 'Espèces',
  jour_du_mois TINYINT DEFAULT 1,
  actif TINYINT(1) DEFAULT 1,
  notes VARCHAR(255) DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =====================================================================
--  GALERIE ENRICHIE
--  Une photo appartient à un album (mariage, cocktail, séminaire…) et
--  peut être rattachée à une prestation réelle. Le visiteur filtre
--  alors les réalisations qui l'intéressent.
-- =====================================================================
CREATE TABLE IF NOT EXISTS galerie_albums (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(120) NOT NULL,
  icone VARCHAR(12) DEFAULT '📸',
  description VARCHAR(300) DEFAULT '',
  couverture VARCHAR(255) DEFAULT '',
  ordre INT DEFAULT 0,
  actif TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE galerie ADD COLUMN IF NOT EXISTS album_id INT DEFAULT NULL;
ALTER TABLE galerie ADD COLUMN IF NOT EXISTS facture_id INT DEFAULT NULL;
ALTER TABLE galerie ADD COLUMN IF NOT EXISTS description VARCHAR(300) DEFAULT '';
ALTER TABLE galerie ADD COLUMN IF NOT EXISTS actif TINYINT(1) DEFAULT 1;
ALTER TABLE galerie ADD COLUMN IF NOT EXISTS largeur INT DEFAULT 0;
ALTER TABLE galerie ADD COLUMN IF NOT EXISTS hauteur INT DEFAULT 0;

INSERT IGNORE INTO galerie_albums (id, nom, icone, description, ordre) VALUES
 (1, 'Mariages',    '💍', 'Réceptions et cérémonies', 1),
 (2, 'Séminaires',  '💼', 'Entreprises et institutions', 2),
 (3, 'Cocktails',   '🥂', 'Réceptions debout et lancements', 3),
 (4, 'Baptêmes',    '🕊️', 'Cérémonies familiales', 4),
 (5, 'Nos plats',   '🍛', 'Le savoir-faire en cuisine', 5);

-- =====================================================================
--  LIGNES FACTURÉES PAR JOUR
--  Sur un événement de plusieurs jours, certaines prestations se
--  répètent chaque jour : les petits-déjeuners, les pauses café, les
--  repas. D'autres non : la décoration de la salle, la location de
--  sono, le transport aller-retour.
--
--  Cette colonne dit, ligne par ligne, si la quantité doit être
--  multipliée par le nombre de jours de l'événement.
--
--  Valeur 0 par défaut : les documents DÉJÀ ÉMIS gardent exactement
--  leur montant. Une facture envoyée à un client ne doit jamais
--  changer de total après coup.
-- =====================================================================
ALTER TABLE facture_lignes ADD COLUMN IF NOT EXISTS par_jour TINYINT(1) DEFAULT 0;

-- =====================================================================
--  SERVICES ENRICHIS
--  Un service n'est pas qu'un nom : le visiteur veut savoir ce qu'il
--  comprend et à partir de quel prix. Ces colonnes restent facultatives.
-- =====================================================================
ALTER TABLE services ADD COLUMN IF NOT EXISTS prix_indicatif VARCHAR(80) DEFAULT '';
ALTER TABLE services ADD COLUMN IF NOT EXISTS details TEXT;
ALTER TABLE services ADD COLUMN IF NOT EXISTS image VARCHAR(255) DEFAULT '';

-- =====================================================================
--  LOCALISATION DES CONNEXIONS
--
--  La position est déduite de l'adresse IP. Elle situe la ville, pas la
--  personne : la précision va de quelques kilomètres en ville à plusieurs
--  dizaines en zone rurale. Ce n'est PAS une position GPS.
--
--  Le cache évite d'interroger le service de géolocalisation à chaque
--  connexion — il est limité à 45 appels par minute, et une attente de
--  trois secondes à chaque ouverture de session serait pénible.
-- =====================================================================
CREATE TABLE IF NOT EXISTS geo_cache (
  ip VARCHAR(45) NOT NULL PRIMARY KEY,
  ville VARCHAR(120) DEFAULT '',
  region VARCHAR(120) DEFAULT '',
  pays VARCHAR(80) DEFAULT '',
  code_pays VARCHAR(4) DEFAULT '',
  latitude DECIMAL(10,6) NULL,
  longitude DECIMAL(10,6) NULL,
  operateur VARCHAR(160) DEFAULT '',
  fuseau VARCHAR(64) DEFAULT '',
  mobile TINYINT(1) DEFAULT 0,
  proxy TINYINT(1) DEFAULT 0,
  maj DATETIME DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE users ADD COLUMN IF NOT EXISTS last_region VARCHAR(120) DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_pays VARCHAR(80) DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_lat DECIMAL(10,6) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_lon DECIMAL(10,6) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_operateur VARCHAR(160) DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_mobile TINYINT(1) DEFAULT 0;

-- =====================================================================
--  LIEN AVEC GOOGLE DRIVE
--  On retient l'identifiant du fichier distant et son lien de partage,
--  pour pouvoir l'ouvrir ou le supprimer depuis l'application.
-- =====================================================================
ALTER TABLE coffre_documents ADD COLUMN IF NOT EXISTS drive_id VARCHAR(80) DEFAULT '';
ALTER TABLE coffre_documents ADD COLUMN IF NOT EXISTS drive_lien VARCHAR(255) DEFAULT '';
ALTER TABLE coffre_documents ADD COLUMN IF NOT EXISTS drive_le DATETIME NULL;

-- =====================================================================
--  ÉCHÉANCES RÉCURRENTES
--
--  Les obligations qui reviennent : déclarations, impôts, cotisations,
--  renouvellements. Le système calcule la prochaine date et prévient
--  à l'avance, pour qu'aucune ne passe à la trappe.
-- =====================================================================
CREATE TABLE IF NOT EXISTS echeances (
  id INT AUTO_INCREMENT PRIMARY KEY,
  libelle VARCHAR(160) NOT NULL,
  categorie VARCHAR(40) DEFAULT 'fiscal',      -- fiscal, social, juridique, assurance, autre
  description TEXT,
  organisme VARCHAR(120) DEFAULT '',           -- DGI, CNPS, mairie…
  recurrence VARCHAR(20) DEFAULT 'mensuelle',  -- mensuelle, trimestrielle, semestrielle, annuelle, unique
  jour_du_mois TINYINT DEFAULT 15,             -- 1 à 31
  mois TINYINT DEFAULT NULL,                   -- pour annuelle / semestrielle : mois de référence
  date_unique DATE DEFAULT NULL,               -- pour une échéance ponctuelle
  preavis_jours SMALLINT DEFAULT 10,           -- combien de jours avant on prévient
  montant_estime DECIMAL(14,0) DEFAULT 0,
  responsable_id INT DEFAULT NULL,
  actif TINYINT(1) DEFAULT 1,
  ordre SMALLINT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (actif), INDEX (recurrence)
);

-- Ce qui a été accompli : une ligne par échéance et par période.
CREATE TABLE IF NOT EXISTS echeances_faites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  echeance_id INT NOT NULL,
  periode VARCHAR(10) NOT NULL,                -- 2026-03, ou 2026 pour une annuelle
  echue_le DATE NOT NULL,                      -- la date qui était attendue
  fait_le DATETIME DEFAULT CURRENT_TIMESTAMP,
  fait_par INT DEFAULT NULL,
  montant_reel DECIMAL(14,0) DEFAULT 0,
  note VARCHAR(255) DEFAULT '',
  UNIQUE KEY (echeance_id, periode),
  FOREIGN KEY (echeance_id) REFERENCES echeances(id) ON DELETE CASCADE
);

-- =====================================================================
--  ASSISTANT DU SITE
--
--  La base de connaissances est découpée en SECTIONS plutôt qu'en un seul
--  bloc de texte : on peut en désactiver une sans tout réécrire.
-- =====================================================================
CREATE TABLE IF NOT EXISTS ia_connaissances (
  id INT AUTO_INCREMENT PRIMARY KEY,
  titre VARCHAR(160) NOT NULL,
  contenu TEXT NOT NULL,
  mots_cles VARCHAR(255) DEFAULT '',
  ordre SMALLINT DEFAULT 0,
  actif TINYINT(1) DEFAULT 1,
  maj TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Documents que l'assistant peut proposer au téléchargement.
CREATE TABLE IF NOT EXISTS ia_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  titre VARCHAR(160) NOT NULL,
  description VARCHAR(400) DEFAULT '',
  fichier VARCHAR(255) NOT NULL,
  fichier_nom VARCHAR(255) NOT NULL,
  taille INT DEFAULT 0,
  mots_cles VARCHAR(255) DEFAULT '',
  telechargements INT DEFAULT 0,
  actif TINYINT(1) DEFAULT 1,
  ordre SMALLINT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Conversations : ce que les visiteurs demandent vraiment.
CREATE TABLE IF NOT EXISTS ia_conversations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  jeton VARCHAR(64) NOT NULL,
  ip VARCHAR(45) DEFAULT '',
  visiteur_nom VARCHAR(120) DEFAULT '',
  visiteur_tel VARCHAR(40) DEFAULT '',
  nb_messages SMALLINT DEFAULT 0,
  transmise TINYINT(1) DEFAULT 0,
  commande_id INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  maj DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (jeton), INDEX (created_at)
);

CREATE TABLE IF NOT EXISTS ia_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  role VARCHAR(12) NOT NULL,
  contenu TEXT NOT NULL,
  sans_reponse TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (conversation_id),
  FOREIGN KEY (conversation_id) REFERENCES ia_conversations(id) ON DELETE CASCADE
);

-- Consommation quotidienne, pour tenir le plafond.
CREATE TABLE IF NOT EXISTS ia_usage (
  jour DATE NOT NULL PRIMARY KEY,
  appels INT DEFAULT 0,
  jetons_entree INT DEFAULT 0,
  jetons_sortie INT DEFAULT 0
);

-- =====================================================================
--  TRANSFERT AU SECRÉTARIAT
--
--  Une demande transmise par l'assistant n'est pas « envoyée puis
--  oubliée » : elle a un état, un responsable, un canal de réponse et
--  une échéance. Sans cela, personne n'est comptable de rien.
-- =====================================================================
ALTER TABLE ia_conversations ADD COLUMN IF NOT EXISTS visiteur_email VARCHAR(160) DEFAULT '';
ALTER TABLE ia_conversations ADD COLUMN IF NOT EXISTS statut VARCHAR(20) DEFAULT 'ouverte';
   -- ouverte | transmise | prise | traitee | classee
ALTER TABLE ia_conversations ADD COLUMN IF NOT EXISTS transmise_le DATETIME NULL;
ALTER TABLE ia_conversations ADD COLUMN IF NOT EXISTS echeance_reponse DATETIME NULL;
ALTER TABLE ia_conversations ADD COLUMN IF NOT EXISTS pris_par INT DEFAULT NULL;
ALTER TABLE ia_conversations ADD COLUMN IF NOT EXISTS pris_le DATETIME NULL;
ALTER TABLE ia_conversations ADD COLUMN IF NOT EXISTS traite_le DATETIME NULL;
ALTER TABLE ia_conversations ADD COLUMN IF NOT EXISTS canal_reponse VARCHAR(16) DEFAULT '';
ALTER TABLE ia_conversations ADD COLUMN IF NOT EXISTS note_interne TEXT;
ALTER TABLE ia_conversations ADD COLUMN IF NOT EXISTS relance_envoyee TINYINT(1) DEFAULT 0;

-- Ce qui a été fait sur une demande : qui, quand, par quel canal.
CREATE TABLE IF NOT EXISTS ia_suivi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  acteur_id INT DEFAULT NULL,
  acteur_nom VARCHAR(120) DEFAULT '',
  action VARCHAR(30) NOT NULL,        -- transmise | prise | relance | reponse | traitee | note
  canal VARCHAR(16) DEFAULT '',       -- email | whatsapp | telephone
  detail TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (conversation_id),
  FOREIGN KEY (conversation_id) REFERENCES ia_conversations(id) ON DELETE CASCADE
);

-- ============================================================================
--  PROTOCOLE DE RETRAIT D'UN ADMINISTRATEUR
--  Aucun administrateur ne peut en écarter un autre seul : il dépose une
--  demande motivée, qu'un SECOND administrateur doit approuver. Sans
--  approbation, elle expire au bout de trois jours.
-- ============================================================================
CREATE TABLE IF NOT EXISTS admin_retraits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cible_id INT NOT NULL,                  -- l'administrateur visé
  cible_nom VARCHAR(120) DEFAULT '',      -- conservé : le compte peut disparaître
  action VARCHAR(20) NOT NULL,            -- supprimer | desactiver | retrograder
  motif TEXT NOT NULL,                    -- ce que le second administrateur lira
  demandeur_id INT NOT NULL,
  demandeur_nom VARCHAR(120) DEFAULT '',
  statut VARCHAR(20) DEFAULT 'attente',   -- attente | approuvee | refusee | annulee | expiree
  approbateur_id INT DEFAULT NULL,
  approbateur_nom VARCHAR(120) DEFAULT '',
  reponse_motif TEXT,
  expire_le DATETIME DEFAULT NULL,
  traitee_le DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (cible_id),
  INDEX (statut)
);

-- Un administrateur a tous les droits : sa colonne « permissions » doit le
-- dire, sinon une rétrogradation le laisserait sans rien et la fiche afficherait
-- des cases vides pour quelqu'un qui accède pourtant à tout. La liste est
-- réécrite à chaque déploiement, sans condition : c'est ainsi qu'un module
-- ajouté depuis se retrouve dans les droits des administrateurs existants.
UPDATE users SET permissions = '["commandes_client","calendrier","echeances","recherche","messages","annuaire","clients","paiements","audit","finances","relances","comptabilite","stock","factures","proformas","bons_sortie","bons_entree","paie","employes","comptes","badges","externes","documents","coffre","taches","journal","rapports","messagerie","forum","menu","services","galerie","videos","temoignages","assistant","parametres","systeme"]'
WHERE role = 'admin';

-- ============================================================================
--  ÉCHÉANCES — DATE DE DÉBUT DE SUIVI
--  Sans elle, une obligation mensuelle saisie en septembre apparaissait en
--  retard pour janvier à août : huit périodes que personne n'a manquées,
--  puisque l'obligation n'était pas encore enregistrée. On ne compte donc
--  les occurrences qu'à partir du jour où l'entreprise a commencé à suivre.
-- ============================================================================
ALTER TABLE echeances ADD COLUMN IF NOT EXISTS suivi_depuis DATE DEFAULT NULL;
UPDATE echeances SET suivi_depuis = DATE(created_at)
WHERE suivi_depuis IS NULL AND created_at IS NOT NULL;

-- ============================================================================
--  SORTIES — LE BÉNÉFICIAIRE
--  Une sortie n'a pas de client : elle a quelqu'un qu'on paie — un
--  fournisseur, un bailleur, un prestataire. Faute de ce champ, toutes les
--  sorties se rangeaient sous « Client de passage », ce qui ne désignait
--  personne.
-- ============================================================================
ALTER TABLE recus ADD COLUMN IF NOT EXISTS beneficiaire VARCHAR(160) DEFAULT '';
