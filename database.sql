-- =====================================================
-- TRAITEUR PRO — Base de données MySQL (XAMPP)
-- Importer via phpMyAdmin : http://localhost/phpmyadmin
-- =====================================================
CREATE DATABASE IF NOT EXISTS traiteur_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE traiteur_db;

-- Administrateurs
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  nom VARCHAR(100) NOT NULL,
  role ENUM('admin','employe') DEFAULT 'admin',
  permissions TEXT DEFAULT NULL,
  employe_id INT DEFAULT NULL,
  actif TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
-- Identifiants par défaut : admin / admin123 (à changer après connexion)
INSERT IGNORE INTO users (username, password, nom, role) VALUES
('admin', '$2y$10$9YI7UAwmp9n2xdCZ6Wu5puxBttEoMMS4SmDkdgHNlbXsmUznyaSgO', 'Administrateur', 'admin');

-- Paramètres du site (tout est modifiable depuis l'admin)
CREATE TABLE IF NOT EXISTS settings (
  cle VARCHAR(60) PRIMARY KEY,
  valeur TEXT
);
INSERT IGNORE INTO settings (cle, valeur) VALUES
('nom_entreprise', 'Saveurs & Prestige'),
('slogan', 'L''art culinaire au service de vos événements'),
('hero_titre', 'Des saveurs qui marquent les esprits'),
('hero_texte', 'Mariages, événements d''entreprise, réceptions privées — nous créons des expériences culinaires inoubliables, livrées avec élégance.'),
('telephone', '+225 07 00 00 00 00'),
('email', 'contact@saveurs-prestige.ci'),
('adresse', 'Cocody, Abidjan — Côte d''Ivoire'),
('horaires', 'Lun – Sam : 8h00 – 19h00'),
('facebook', 'https://facebook.com'),
('instagram', 'https://instagram.com'),
('whatsapp', '+2250700000000'),
('apropos', 'Depuis plus de 10 ans, notre équipe de chefs passionnés sublime vos événements avec une cuisine raffinée mêlant traditions ivoiriennes et gastronomie internationale.');

-- Catégories du menu
CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(100) NOT NULL,
  icone VARCHAR(10) DEFAULT '🍽️',
  ordre INT DEFAULT 0,
  actif TINYINT(1) DEFAULT 1
);
INSERT IGNORE INTO categories (nom, icone, ordre) VALUES
('Entrées', '🥗', 1), ('Plats', '🍛', 2), ('Desserts', '🍰', 3), ('Boissons', '🍹', 4);

-- Plats
CREATE TABLE IF NOT EXISTS plats (
  id INT AUTO_INCREMENT PRIMARY KEY,
  categorie_id INT NOT NULL,
  nom VARCHAR(150) NOT NULL,
  description TEXT,
  prix DECIMAL(10,0) DEFAULT 0,
  image VARCHAR(255) DEFAULT NULL,
  populaire TINYINT(1) DEFAULT 0,
  actif TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (categorie_id) REFERENCES categories(id) ON DELETE CASCADE
);
INSERT IGNORE INTO plats (categorie_id, nom, description, prix, populaire) VALUES
(1, 'Salade exotique de crevettes', 'Crevettes grillées, mangue, avocat et vinaigrette passion', 6500, 1),
(1, 'Velouté de patate douce', 'Crème onctueuse, éclats de noix de cajou torréfiées', 4500, 0),
(2, 'Poulet braisé signature', 'Mariné 24h, accompagné d''attiéké et sauce maison', 9500, 1),
(2, 'Capitaine à la braise', 'Poisson entier, alloco doré et sauce tomate épicée', 12000, 1),
(2, 'Riz cantonais royal', 'Riz sauté, crevettes, poulet fumé et légumes croquants', 8000, 0),
(3, 'Fondant chocolat-coco', 'Cœur coulant, chantilly coco et zestes de citron vert', 5000, 1),
(3, 'Salade de fruits frais', 'Ananas, papaye, mangue et menthe fraîche', 3500, 0),
(4, 'Cocktail gingembre-ananas', 'Jus frais pressé du jour, sans alcool', 2500, 0),
(4, 'Bissap royal', 'Hibiscus infusé, touche de menthe et de vanille', 2000, 1);

-- Services / prestations
CREATE TABLE IF NOT EXISTS services (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(120) NOT NULL,
  description TEXT,
  icone VARCHAR(10) DEFAULT '✨',
  ordre INT DEFAULT 0,
  actif TINYINT(1) DEFAULT 1
);
INSERT IGNORE INTO services (nom, description, icone, ordre) VALUES
('Mariages & Cérémonies', 'Buffets d''exception, service à table et scénographie culinaire pour le plus beau jour de votre vie.', '💍', 1),
('Événements d''entreprise', 'Cocktails, séminaires, lancements de produits — une prestation professionnelle clé en main.', '💼', 2),
('Réceptions privées', 'Anniversaires, baptêmes, dîners intimes — une cuisine sur mesure chez vous.', '🎉', 3),
('Livraison de plateaux', 'Plateaux-repas gourmets livrés au bureau ou à domicile, commande dès 10 personnes.', '🛵', 4);

-- Galerie
CREATE TABLE IF NOT EXISTS galerie (
  id INT AUTO_INCREMENT PRIMARY KEY,
  titre VARCHAR(150) DEFAULT '',
  image VARCHAR(255) NOT NULL,
  ordre INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Témoignages
CREATE TABLE IF NOT EXISTS temoignages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(100) NOT NULL,
  texte TEXT NOT NULL,
  note TINYINT DEFAULT 5,
  actif TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
INSERT IGNORE INTO temoignages (nom, texte, note) VALUES
('Aïcha K.', 'Une prestation exceptionnelle pour notre mariage. Les invités en parlent encore ! Merci à toute l''équipe.', 5),
('Jean-Marc D.', 'Cocktail d''entreprise impeccable, service ponctuel et présentation magnifique. Je recommande vivement.', 5),
('Fatou B.', 'Le poulet braisé signature est une merveille. Livraison rapide et plats encore chauds.', 4);

-- Demandes de devis / commandes
CREATE TABLE IF NOT EXISTS commandes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(100) NOT NULL,
  telephone VARCHAR(30) NOT NULL,
  email VARCHAR(120) DEFAULT '',
  type_evenement VARCHAR(80) NOT NULL,
  date_evenement DATE DEFAULT NULL,
  nb_invites INT DEFAULT 0,
  budget VARCHAR(60) DEFAULT '',
  message TEXT,
  statut ENUM('nouveau','en_cours','confirme','termine','annule') DEFAULT 'nouveau',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =====================================================
-- MODULE GESTION — Groupe Helisce
-- (Clients, Comptabilité, Documents)
-- =====================================================

-- Paramètres complémentaires pour les documents
INSERT IGNORE INTO settings (cle, valeur) VALUES
('devise', 'FCFA'),
('tva_taux', '18'),
('rccm', 'CI-ABJ-2020-B-00000'),
('ncc', '0000000 A'),
('compte_bancaire', ''),
('prefixe_facture', 'FAC'),
('prefixe_fiche', 'PAIE'),
('mentions_facture', 'Paiement à réception. Merci de votre confiance.')
ON DUPLICATE KEY UPDATE valeur = valeur;

-- Renommer l'entreprise
UPDATE settings SET valeur = 'Groupe Helisce' WHERE cle = 'nom_entreprise';

-- Clients
CREATE TABLE IF NOT EXISTS clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(120) NOT NULL,
  entreprise VARCHAR(150) DEFAULT '',
  telephone VARCHAR(30) DEFAULT '',
  email VARCHAR(120) DEFAULT '',
  adresse VARCHAR(255) DEFAULT '',
  ncc VARCHAR(60) DEFAULT '',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Comptabilité : entrées & dépenses
CREATE TABLE IF NOT EXISTS transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('entree','depense') NOT NULL,
  categorie VARCHAR(80) DEFAULT 'Autre',
  libelle VARCHAR(200) NOT NULL,
  montant DECIMAL(14,0) NOT NULL DEFAULT 0,
  mode_paiement VARCHAR(40) DEFAULT 'Espèces',
  client_id INT DEFAULT NULL,
  date_operation DATE NOT NULL,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
);

-- Employés (pour les fiches de paie)
CREATE TABLE IF NOT EXISTS employes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(120) NOT NULL,
  poste VARCHAR(100) DEFAULT '',
  telephone VARCHAR(30) DEFAULT '',
  email VARCHAR(120) DEFAULT '',
  numero_cnps VARCHAR(60) DEFAULT '',
  salaire_base DECIMAL(14,0) DEFAULT 0,
  date_embauche DATE DEFAULT NULL,
  actif TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Factures
CREATE TABLE IF NOT EXISTS factures (
  id INT AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(40) NOT NULL UNIQUE,
  type ENUM('facture','proforma') DEFAULT 'facture',
  client_id INT DEFAULT NULL,
  date_emission DATE NOT NULL,
  date_echeance DATE DEFAULT NULL,
  tva_taux DECIMAL(5,2) DEFAULT 18,
  remise DECIMAL(14,0) DEFAULT 0,
  statut ENUM('brouillon','envoyee','payee','annulee') DEFAULT 'brouillon',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS facture_lignes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  facture_id INT NOT NULL,
  designation VARCHAR(255) NOT NULL,
  quantite DECIMAL(10,2) DEFAULT 1,
  prix_unitaire DECIMAL(14,0) DEFAULT 0,
  FOREIGN KEY (facture_id) REFERENCES factures(id) ON DELETE CASCADE
);

-- Fiches de paie
CREATE TABLE IF NOT EXISTS fiches_paie (
  id INT AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(40) NOT NULL UNIQUE,
  employe_id INT DEFAULT NULL,
  periode VARCHAR(7) NOT NULL,           -- format AAAA-MM
  salaire_base DECIMAL(14,0) DEFAULT 0,
  primes DECIMAL(14,0) DEFAULT 0,
  heures_sup DECIMAL(14,0) DEFAULT 0,
  cnps DECIMAL(14,0) DEFAULT 0,
  impots DECIMAL(14,0) DEFAULT 0,
  autres_deductions DECIMAL(14,0) DEFAULT 0,
  net_a_payer DECIMAL(14,0) DEFAULT 0,
  date_paiement DATE DEFAULT NULL,
  statut ENUM('brouillon','payee') DEFAULT 'brouillon',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employe_id) REFERENCES employes(id) ON DELETE SET NULL
);

-- Données de démonstration
INSERT IGNORE INTO clients (nom, entreprise, telephone, email, adresse) VALUES
('Konan Yao', 'Yao Événements', '+225 07 11 22 33 44', 'konan@exemple.ci', 'Cocody, Abidjan'),
('Aminata Traoré', 'Cabinet ATraoré', '+225 05 55 66 77 88', 'a.traore@exemple.ci', 'Plateau, Abidjan');

INSERT IGNORE INTO employes (nom, poste, telephone, salaire_base, date_embauche) VALUES
('Ibrahim Sané', 'Chef cuisinier', '+225 07 00 11 22 33', 350000, '2023-01-15'),
('Grace Kouassi', 'Responsable service', '+225 05 44 55 66 77', 250000, '2023-06-01');

INSERT IGNORE INTO transactions (type, categorie, libelle, montant, mode_paiement, date_operation) VALUES
('entree', 'Prestation', 'Acompte mariage Konan', 500000, 'Virement', CURDATE()),
('depense', 'Approvisionnement', 'Achat denrées marché', 120000, 'Espèces', CURDATE()),
('depense', 'Salaires', 'Avance sur salaire', 80000, 'Espèces', CURDATE());

-- =====================================================
-- MODULE VIDÉOS + CONTENU ÉDITABLE + INFOS LÉGALES
-- =====================================================

-- Vidéos des prestations (YouTube/Vimeo ou fichier local)
CREATE TABLE IF NOT EXISTS videos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  titre VARCHAR(150) NOT NULL,
  description VARCHAR(400) DEFAULT '',
  type ENUM('youtube','fichier') DEFAULT 'youtube',
  url VARCHAR(255) DEFAULT '',
  fichier VARCHAR(255) DEFAULT '',
  miniature VARCHAR(255) DEFAULT '',
  ordre INT DEFAULT 0,
  actif TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO videos (titre, description, type, url, ordre) VALUES
('Mariage de prestige à Cocody', 'Un buffet raffiné pour 300 invités.', 'youtube', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 1),
('Cocktail d''entreprise', 'Service traiteur pour un lancement de produit.', 'youtube', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 2);

-- Contenu éditable du site + informations légales
INSERT IGNORE INTO settings (cle, valeur) VALUES
('hero_eyebrow', 'Traiteur d''exception'),
('hero_card_titre', 'Cuisine signature'),
('hero_card_texte', 'Des créations uniques, préparées le jour même avec des produits frais et locaux.'),
('hero_chip1', '+500 événements réussis'),
('hero_chip2', 'Livraison express'),
('cta_devis', 'Demander un devis'),
('cta_menu', 'Découvrir le menu'),
('sec_services_eyebrow', 'Nos prestations'),
('sec_services_titre', 'Un service pour chaque occasion'),
('sec_menu_eyebrow', 'La carte'),
('sec_menu_titre', 'Notre menu du moment'),
('sec_menu_texte', 'Sélectionnez une catégorie et laissez-vous tenter.'),
('sec_galerie_eyebrow', 'En images'),
('sec_galerie_titre', 'Nos plus belles réalisations'),
('sec_videos_eyebrow', 'En vidéo'),
('sec_videos_titre', 'Nos prestations en action'),
('sec_videos_texte', 'Découvrez l''ambiance de nos événements.'),
('sec_avis_eyebrow', 'Ils nous font confiance'),
('sec_avis_titre', 'Avis de nos clients'),
('sec_devis_eyebrow', 'Parlons de votre projet'),
('sec_devis_titre', 'Demandez votre devis gratuit'),
('sec_devis_texte', 'Réponse sous 24h. Décrivez votre événement, nous nous occupons du reste.'),
('footer_description', 'Groupe Helisce sublime vos événements avec une cuisine d''exception et un service irréprochable, à Abidjan et partout en Côte d''Ivoire.'),
('forme_juridique', 'SARL'),
('capital', '1 000 000 FCFA'),
('siege_social', 'Cocody, Abidjan — Côte d''Ivoire'),
('mentions_legales', 'Groupe Helisce — SARL au capital de 1 000 000 FCFA. Siège social : Cocody, Abidjan. Toute reproduction interdite.')
ON DUPLICATE KEY UPDATE valeur = valeur;

-- =====================================================
-- REÇUS DE PAIEMENT + TÂCHES + RAPPORTS + MODE CLAIR
-- =====================================================

-- Préfixes des nouveaux documents
INSERT IGNORE INTO settings (cle, valeur) VALUES
('prefixe_proforma', 'PRO'),
('prefixe_recu', 'REC')
ON DUPLICATE KEY UPDATE valeur = valeur;

-- Reçus de paiement
CREATE TABLE IF NOT EXISTS recus (
  id INT AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(40) NOT NULL UNIQUE,
  client_id INT DEFAULT NULL,
  facture_id INT DEFAULT NULL,
  montant DECIMAL(14,0) NOT NULL DEFAULT 0,
  mode_paiement VARCHAR(40) DEFAULT 'Espèces',
  motif VARCHAR(255) DEFAULT '',
  date_paiement DATE NOT NULL,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
  FOREIGN KEY (facture_id) REFERENCES factures(id) ON DELETE SET NULL
);

-- Tâches assignées aux employés
CREATE TABLE IF NOT EXISTS taches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  titre VARCHAR(200) NOT NULL,
  description TEXT,
  assigne_a INT DEFAULT NULL,          -- users.id (employé)
  priorite ENUM('basse','normale','haute') DEFAULT 'normale',
  statut ENUM('a_faire','en_cours','termine') DEFAULT 'a_faire',
  date_limite DATE DEFAULT NULL,
  note_employe TEXT,                   -- retour de l'employé
  vue TINYINT(1) DEFAULT 0,            -- lue par l'employé (notification)
  cree_par INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (assigne_a) REFERENCES users(id) ON DELETE SET NULL
);

-- Rapports journaliers des employés
CREATE TABLE IF NOT EXISTS rapports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(40) NOT NULL UNIQUE,
  employe_user_id INT DEFAULT NULL,    -- users.id (auteur)
  titre VARCHAR(200) NOT NULL,
  contenu MEDIUMTEXT,                  -- HTML riche
  date_rapport DATE NOT NULL,
  statut ENUM('brouillon','envoye') DEFAULT 'brouillon',
  envoye_at DATETIME DEFAULT NULL,
  lu_par_admin TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (employe_user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- =====================================================================
-- PHASE 5 : espace client, comptes unifiés, bulletin de paie légal,
--           témoignages modérés, messagerie & forum
-- =====================================================================

-- Comptes : ajout du rôle client + liaison à une fiche client
ALTER TABLE users MODIFY role ENUM('admin','employe','client') DEFAULT 'admin';
ALTER TABLE users ADD COLUMN IF NOT EXISTS client_id INT DEFAULT NULL AFTER employe_id;

-- Employés : informations légales pour le bulletin de paie
ALTER TABLE employes ADD COLUMN IF NOT EXISTS matricule       VARCHAR(40)  DEFAULT '' AFTER poste;
ALTER TABLE employes ADD COLUMN IF NOT EXISTS categorie       VARCHAR(60)  DEFAULT '' AFTER matricule;
ALTER TABLE employes ADD COLUMN IF NOT EXISTS banque          VARCHAR(120) DEFAULT '' AFTER numero_cnps;
ALTER TABLE employes ADD COLUMN IF NOT EXISTS numero_compte   VARCHAR(60)  DEFAULT '' AFTER banque;

-- Bulletin de paie : lignes détaillées + mode de paiement bancaire
ALTER TABLE fiches_paie ADD COLUMN IF NOT EXISTS sursalaire          DECIMAL(14,0) DEFAULT 0 AFTER salaire_base;
ALTER TABLE fiches_paie ADD COLUMN IF NOT EXISTS prime_transport     DECIMAL(14,0) DEFAULT 0 AFTER primes;
ALTER TABLE fiches_paie ADD COLUMN IF NOT EXISTS prime_anciennete    DECIMAL(14,0) DEFAULT 0 AFTER prime_transport;
ALTER TABLE fiches_paie ADD COLUMN IF NOT EXISTS indemnites          DECIMAL(14,0) DEFAULT 0 AFTER prime_anciennete;
ALTER TABLE fiches_paie ADD COLUMN IF NOT EXISTS cnps_employeur      DECIMAL(14,0) DEFAULT 0 AFTER cnps;
ALTER TABLE fiches_paie ADD COLUMN IF NOT EXISTS avances             DECIMAL(14,0) DEFAULT 0 AFTER autres_deductions;
ALTER TABLE fiches_paie ADD COLUMN IF NOT EXISTS jours_travailles    DECIMAL(5,1)  DEFAULT 30 AFTER periode;
ALTER TABLE fiches_paie ADD COLUMN IF NOT EXISTS mode_paiement       VARCHAR(40)  DEFAULT 'Virement bancaire' AFTER statut;
ALTER TABLE fiches_paie ADD COLUMN IF NOT EXISTS banque              VARCHAR(120) DEFAULT '' AFTER mode_paiement;
ALTER TABLE fiches_paie ADD COLUMN IF NOT EXISTS numero_compte       VARCHAR(60)  DEFAULT '' AFTER banque;

-- Témoignages : modération (soumis par les clients, validés par l'admin)
ALTER TABLE temoignages ADD COLUMN IF NOT EXISTS email  VARCHAR(120) DEFAULT '' AFTER nom;
ALTER TABLE temoignages ADD COLUMN IF NOT EXISTS statut ENUM('en_attente','valide','rejete') DEFAULT 'en_attente' AFTER note;
-- Les témoignages de démonstration existants restent visibles
UPDATE temoignages SET statut='valide' WHERE actif=1;

-- Messagerie directe (admin <-> employé)
CREATE TABLE IF NOT EXISTS messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  expediteur_id  INT NOT NULL,
  destinataire_id INT NOT NULL,
  contenu TEXT,
  fichier VARCHAR(255) DEFAULT NULL,
  fichier_nom VARCHAR(255) DEFAULT NULL,
  lu TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (expediteur_id), INDEX (destinataire_id)
);

-- Forum d'équipe (tous les employés + admin)
CREATE TABLE IF NOT EXISTS forum_posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  auteur_id INT NOT NULL,
  contenu TEXT,
  fichier VARCHAR(255) DEFAULT NULL,
  fichier_nom VARCHAR(255) DEFAULT NULL,
  epingle TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (auteur_id)
);

-- =====================================================================
-- ARCHIVE DES ANCIENS MEMBRES (renvoyés / comptes supprimés)
-- Conserve l'identité liée à un ancien user_id pour que les données
-- historiques de l'entreprise (messages, forum, rapports, tâches…)
-- gardent le nom de leur auteur même après suppression du compte.
-- =====================================================================
CREATE TABLE IF NOT EXISTS anciens_membres (
  user_id     INT PRIMARY KEY,
  nom         VARCHAR(150) NOT NULL,
  role        VARCHAR(30) DEFAULT NULL,
  archive_le  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paramètres légaux supplémentaires pour le bulletin de paie
INSERT IGNORE INTO settings (cle, valeur) VALUES
 ('cnps_employeur', ''),
 ('convention_collective', 'Convention Collective Interprofessionnelle'),
 ('banque_entreprise', '')
ON DUPLICATE KEY UPDATE cle=cle;

-- =====================================================================
-- PHASE 6 : horaires d'accès employés + commandes client (panier/devis)
-- =====================================================================

-- Horaires d'accès des employés (Lun-Sam 8h-17h par défaut)
INSERT IGNORE INTO settings (cle, valeur) VALUES
 ('work_hours_actif', '1'),
 ('work_jours', '1,2,3,4,5,6'),      -- 1=Lundi ... 7=Dimanche (ISO-8601)
 ('work_debut', '08:00'),
 ('work_fin',   '17:00')
ON DUPLICATE KEY UPDATE cle=cle;

-- Commandes passées depuis l'espace client (panier sans prix -> devis)
CREATE TABLE IF NOT EXISTS commandes_client (
  id INT AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(40) NOT NULL UNIQUE,
  client_id INT NOT NULL,
  date_evenement DATE DEFAULT NULL,
  nb_invites INT DEFAULT 0,
  lieu VARCHAR(200) DEFAULT '',
  notes TEXT,
  statut ENUM('nouvelle','en_traitement','devis_envoye','confirmee','terminee','annulee') DEFAULT 'nouvelle',
  proforma_id INT DEFAULT NULL,      -- facture (type proforma) générée
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (client_id), INDEX (statut)
);

CREATE TABLE IF NOT EXISTS commandes_client_lignes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  commande_id INT NOT NULL,
  plat_id INT DEFAULT NULL,
  designation VARCHAR(200) NOT NULL,
  quantite INT DEFAULT 1,
  INDEX (commande_id)
);

INSERT IGNORE INTO settings (cle, valeur) VALUES ('prefixe_commande', 'CMD') ON DUPLICATE KEY UPDATE cle=cle;

-- =====================================================================
-- PHASE 7 : coffre-fort documentaire de l'entreprise
-- =====================================================================
CREATE TABLE IF NOT EXISTS coffre_dossiers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(120) NOT NULL,
  icone VARCHAR(10) DEFAULT '📁',
  ordre INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS coffre_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dossier_id INT DEFAULT NULL,
  titre VARCHAR(200) NOT NULL,
  description TEXT,
  fichier VARCHAR(255) NOT NULL,
  fichier_nom VARCHAR(255) NOT NULL,
  taille INT DEFAULT 0,
  uploaded_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (dossier_id)
);
-- Dossiers par défaut (rangement adéquat)
INSERT IGNORE INTO coffre_dossiers (nom, icone, ordre) VALUES
 ('Juridique & légal',        '⚖️', 1),
 ('Ressources humaines',      '🧑‍💼', 2),
 ('Comptabilité & finances',  '💰', 3),
 ('Contrats & partenariats',  '🤝', 4),
 ('Fournisseurs & achats',    '📦', 5),
 ('Marketing & communication','📣', 6),
 ('Modèles & documents types','📄', 7),
 ('Divers',                   '🗂️', 8);

-- =====================================================================
-- PHASE 8 : demandes RH des employés (permission, congé, explication, maladie)
-- =====================================================================
ALTER TABLE rapports ADD COLUMN IF NOT EXISTS type ENUM('rapport','permission','conge','explication','conge_maladie') DEFAULT 'rapport' AFTER employe_user_id;
ALTER TABLE rapports ADD COLUMN IF NOT EXISTS date_debut DATE DEFAULT NULL AFTER date_rapport;
ALTER TABLE rapports ADD COLUMN IF NOT EXISTS date_fin   DATE DEFAULT NULL AFTER date_debut;
ALTER TABLE rapports ADD COLUMN IF NOT EXISTS motif      VARCHAR(255) DEFAULT '' AFTER date_fin;
ALTER TABLE rapports ADD COLUMN IF NOT EXISTS hopital    VARCHAR(200) DEFAULT '' AFTER motif;
ALTER TABLE rapports ADD COLUMN IF NOT EXISTS lieu       VARCHAR(200) DEFAULT '' AFTER hopital;
-- Préfixes de numérotation par type
INSERT IGNORE INTO settings (cle, valeur) VALUES
 ('prefixe_permission', 'PERM'),
 ('prefixe_conge', 'CONGE'),
 ('prefixe_explication', 'EXPL'),
 ('prefixe_maladie', 'MAL')
ON DUPLICATE KEY UPDATE cle=cle;

-- =====================================================================
-- PHASE 9 : authentification des documents (QR) + signature & tampon
-- =====================================================================
CREATE TABLE IF NOT EXISTS documents_auth (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(30) NOT NULL,          -- facture, proforma, recu, fiche, rapport, permission, conge, explication, conge_maladie...
  doc_id INT NOT NULL,
  numero VARCHAR(60) DEFAULT '',
  token VARCHAR(64) NOT NULL UNIQUE,
  checksum VARCHAR(16) DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_doc (type, doc_id)
);
INSERT IGNORE INTO settings (cle, valeur) VALUES
 ('site_url', 'http://localhost/traiteur'),
 ('signature_img', ''),
 ('tampon_img', ''),
 ('signataire_nom', ''),
 ('signataire_fonction', 'La Direction'),
 ('mention_livraison', 'Marchandises livrées et reçues en bon état, conformément au bon de commande.')
ON DUPLICATE KEY UPDATE cle=cle;

-- =====================================================================
-- PHASE 10 : accès exceptionnel employé + inscription publique
-- =====================================================================
ALTER TABLE users ADD COLUMN IF NOT EXISTS acces_exception_until DATETIME DEFAULT NULL AFTER actif;
-- Type de client (individuel / entreprise) pour l'inscription
ALTER TABLE clients ADD COLUMN IF NOT EXISTS type_client ENUM('individuel','entreprise') DEFAULT 'individuel' AFTER nom;

-- =====================================================================
-- PHASE 11 : décision admin sur les demandes (accepter / refuser)
-- =====================================================================
ALTER TABLE rapports ADD COLUMN IF NOT EXISTS decision ENUM('en_attente','accepte','refuse') DEFAULT 'en_attente' AFTER statut;
ALTER TABLE rapports ADD COLUMN IF NOT EXISTS decision_motif VARCHAR(255) DEFAULT '' AFTER decision;
ALTER TABLE rapports ADD COLUMN IF NOT EXISTS decision_at DATETIME DEFAULT NULL AFTER decision_motif;

-- Catégorie par ligne de facture/proforma (affichage groupé sur le document)
ALTER TABLE facture_lignes ADD COLUMN IF NOT EXISTS categorie VARCHAR(120) DEFAULT NULL AFTER designation;

-- Menu unifié : ordre d'affichage des articles dans leur catégorie
ALTER TABLE plats ADD COLUMN IF NOT EXISTS ordre INT DEFAULT 0 AFTER actif;

-- Menu unifié : description facultative de la catégorie (ex. « Servi de 8h à 10h30 »)
ALTER TABLE categories ADD COLUMN IF NOT EXISTS description VARCHAR(255) DEFAULT NULL AFTER icone;

-- Exemple de catégorie de service (montre la souplesse du menu)
INSERT IGNORE INTO categories (nom, icone, description, ordre, actif) VALUES
('Pause café du matin', '☕', 'Servi de 8h à 10h30', 5, 1);
SET @pc = LAST_INSERT_ID();
INSERT IGNORE INTO plats (categorie_id, nom, description, prix, populaire, actif, ordre) VALUES
(@pc, 'Croissant au beurre', 'Pur beurre, cuit sur place chaque matin', 1000, 1, 1, 1),
(@pc, 'Pain fourré', 'Poulet, thon ou œuf mayonnaise', 1500, 0, 1, 2),
(@pc, 'Mini-viennoiseries assorties', 'Plateau de 12 pièces', 6000, 0, 1, 3),
(@pc, 'Café / thé à volonté', 'Service en thermos, sucre et lait inclus', 0, 0, 1, 4),
(@pc, 'Jus d''orange pressé', 'Pressé le matin même', 1200, 0, 1, 5);

-- Facturation par prestation : éléments inclus dans une ligne (sans prix individuels)
ALTER TABLE facture_lignes ADD COLUMN IF NOT EXISTS details TEXT DEFAULT NULL AFTER categorie;
-- TVA applicable ou non sur le document
ALTER TABLE factures ADD COLUMN IF NOT EXISTS tva_applicable TINYINT(1) DEFAULT 1 AFTER tva_taux;

-- =====================================================================
-- Notifications : suivi de ce que chaque destinataire a déjà consulté
-- =====================================================================
ALTER TABLE commandes_client ADD COLUMN IF NOT EXISTS vu_client TINYINT(1) DEFAULT 1 AFTER statut;
ALTER TABLE factures ADD COLUMN IF NOT EXISTS vu_client TINYINT(1) DEFAULT 1 AFTER statut;
ALTER TABLE recus ADD COLUMN IF NOT EXISTS vu_client TINYINT(1) DEFAULT 1 AFTER mode_paiement;

-- Champs communs aux documents : mode de paiement, activité (titre/description), date & lieu de l'événement
ALTER TABLE factures ADD COLUMN IF NOT EXISTS mode_paiement VARCHAR(60) DEFAULT '' AFTER statut;
ALTER TABLE factures ADD COLUMN IF NOT EXISTS activite VARCHAR(255) DEFAULT '' AFTER mode_paiement;
ALTER TABLE factures ADD COLUMN IF NOT EXISTS date_evenement DATE NULL AFTER activite;
ALTER TABLE factures ADD COLUMN IF NOT EXISTS lieu VARCHAR(255) DEFAULT '' AFTER date_evenement;
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
 ('tiktok',''), ('youtube',''), ('linkedin',''), ('x',''),
 ('wave_actif', '0'), ('wave_api_key', ''), ('wave_mode', 'test'),
 ('wave_webhook_secret', ''), ('wave_frais_client', '0');

-- Circuit de validation des documents rediges
ALTER TABLE documents_texte ADD COLUMN IF NOT EXISTS statut ENUM('brouillon','termine','valide') DEFAULT 'brouillon' AFTER categorie;
ALTER TABLE documents_texte ADD COLUMN IF NOT EXISTS termine_le DATETIME NULL AFTER statut;
ALTER TABLE documents_texte ADD COLUMN IF NOT EXISTS termine_par INT DEFAULT NULL AFTER termine_le;
ALTER TABLE documents_texte ADD COLUMN IF NOT EXISTS valide_le DATETIME NULL AFTER termine_par;
ALTER TABLE documents_texte ADD COLUMN IF NOT EXISTS valide_par INT DEFAULT NULL AFTER valide_le;
ALTER TABLE documents_texte ADD COLUMN IF NOT EXISTS coffre_doc_id INT DEFAULT NULL AFTER valide_par;
ALTER TABLE documents_texte ADD COLUMN IF NOT EXISTS motif_refus VARCHAR(255) DEFAULT '' AFTER coffre_doc_id;

ALTER TABLE recus ADD COLUMN IF NOT EXISTS activite VARCHAR(255) DEFAULT '' AFTER motif;
ALTER TABLE recus ADD COLUMN IF NOT EXISTS date_evenement DATE NULL AFTER activite;
ALTER TABLE recus ADD COLUMN IF NOT EXISTS lieu VARCHAR(255) DEFAULT '' AFTER date_evenement;
ALTER TABLE users ADD COLUMN IF NOT EXISTS forum_vu_at DATETIME NULL AFTER actif;
ALTER TABLE rapports ADD COLUMN IF NOT EXISTS vu_par_employe TINYINT(1) DEFAULT 1 AFTER decision;

-- Bons de sortie et bons d'entrée (remplacent les reçus)
ALTER TABLE recus ADD COLUMN IF NOT EXISTS type ENUM('sortie','entree') DEFAULT 'sortie' AFTER numero;

-- =====================================================================
-- Connexion Google + réinitialisation de mot de passe + profil complet
-- =====================================================================
ALTER TABLE users ADD COLUMN IF NOT EXISTS google_id VARCHAR(64) NULL AFTER password;
ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(190) NULL AFTER nom;
ALTER TABLE users ADD COLUMN IF NOT EXISTS telephone VARCHAR(40) NULL AFTER email;
ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar VARCHAR(255) NULL AFTER telephone;
ALTER TABLE users ADD COLUMN IF NOT EXISTS profil_complet TINYINT(1) DEFAULT 1 AFTER avatar;
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_code VARCHAR(12) NULL AFTER profil_complet;
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_expire DATETIME NULL AFTER reset_code;

-- Trace de la première authentification (pour le coffre)
ALTER TABLE documents_auth ADD COLUMN IF NOT EXISTS authentifie_par INT NULL AFTER numero;

-- =====================================================================
-- BADGES & CARTES PROFESSIONNELLES
-- =====================================================================
CREATE TABLE IF NOT EXISTS badges (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    matricule     VARCHAR(60) NOT NULL UNIQUE,
    type_porteur  ENUM('employe','externe') NOT NULL DEFAULT 'employe',
    employe_id    INT NULL,                         -- si lié à un employé
    externe_id    INT NULL,                         -- si lié à une fiche du registre externes
    nom           VARCHAR(150) NOT NULL,
    poste         VARCHAR(150) NULL,                -- fonction / rôle
    organisation  VARCHAR(150) NULL,               -- société pour un externe
    telephone     VARCHAR(60) NULL,
    email         VARCHAR(190) NULL,
    photo         VARCHAR(255) NULL,
    departement   VARCHAR(120) NULL,
    date_emission DATE NOT NULL,
    date_expiration DATE NULL,
    statut        ENUM('actif','suspendu','expire') NOT NULL DEFAULT 'actif',
    token         VARCHAR(64) NULL,                 -- pour la vérification en ligne
    cree_par      INT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY (type_porteur), KEY (statut), KEY (employe_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- REGISTRE DES MEMBRES EXTERNES (prestataires, extras, vacataires…)
-- Numéro de référence de traçabilité auto-généré (REF-2026-001).
-- =====================================================================
CREATE TABLE IF NOT EXISTS externes (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    reference     VARCHAR(40) NOT NULL UNIQUE,        -- REF-2026-001 (traçabilité)
    nom           VARCHAR(150) NOT NULL,
    organisation  VARCHAR(150) NULL,                  -- société / structure
    fonction      VARCHAR(150) NULL,                  -- rôle lors des interventions
    telephone     VARCHAR(60) NULL,
    email         VARCHAR(190) NULL,
    adresse       VARCHAR(255) NULL,
    piece_identite VARCHAR(120) NULL,                 -- type + n° de pièce (CNI, passeport…)
    notes         TEXT NULL,
    nb_interventions INT NOT NULL DEFAULT 0,          -- compteur d'interventions
    derniere_intervention DATE NULL,
    statut        ENUM('actif','inactif') NOT NULL DEFAULT 'actif',
    badge_id      INT NULL,                           -- badge lié éventuel
    cree_par      INT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY (statut), KEY (badge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historique des interventions d'un externe (traces datées)
CREATE TABLE IF NOT EXISTS externes_interventions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    externe_id    INT NOT NULL,
    date_intervention DATE NOT NULL,
    objet         VARCHAR(255) NULL,                  -- événement / mission
    lieu          VARCHAR(200) NULL,
    notes         TEXT NULL,
    cree_par      INT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY (externe_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Format du numéro de référence des externes
INSERT IGNORE INTO settings (cle, valeur) VALUES ('externe_prefixe', 'REF') ON DUPLICATE KEY UPDATE valeur=valeur;

INSERT IGNORE INTO settings (cle, valeur) VALUES ('badge_prefixe', 'GH') ON DUPLICATE KEY UPDATE valeur=valeur;
INSERT IGNORE INTO settings (cle, valeur) VALUES ('badge_format', '{PREFIXE}-{ANNEE}-{SEQ4}') ON DUPLICATE KEY UPDATE valeur=valeur;
INSERT IGNORE INTO settings (cle, valeur) VALUES ('badge_prefixe_externe', 'EXT') ON DUPLICATE KEY UPDATE valeur=valeur;

-- Session unique (empêche la double connexion) + suivi d'activité
ALTER TABLE users ADD COLUMN IF NOT EXISTS session_id VARCHAR(64) NULL AFTER reset_expire;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_activity DATETIME NULL AFTER session_id;

-- Champs supplémentaires pour la carte professionnelle (modèle premium)
ALTER TABLE badges ADD COLUMN IF NOT EXISTS groupe_sanguin VARCHAR(8) NULL AFTER departement;
ALTER TABLE badges ADD COLUMN IF NOT EXISTS date_naissance DATE NULL AFTER groupe_sanguin;
ALTER TABLE badges ADD COLUMN IF NOT EXISTS date_embauche DATE NULL AFTER date_naissance;

-- Champs employé utiles pour badge/carte (matricule devient l'identifiant unique)
ALTER TABLE employes ADD COLUMN IF NOT EXISTS departement VARCHAR(120) NULL AFTER categorie;
ALTER TABLE employes ADD COLUMN IF NOT EXISTS groupe_sanguin VARCHAR(8) NULL AFTER departement;
ALTER TABLE employes ADD COLUMN IF NOT EXISTS date_naissance DATE NULL AFTER groupe_sanguin;
ALTER TABLE employes ADD COLUMN IF NOT EXISTS photo VARCHAR(255) NULL AFTER date_naissance;

-- Suivi de connexion (tableau de bord : comptes en ligne)
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_ip VARCHAR(45) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_ville VARCHAR(120) NULL;

-- Intervalle de prix des catégories/packages (affichage vitrine uniquement)
ALTER TABLE categories ADD COLUMN IF NOT EXISTS prix_min INT DEFAULT 0;
ALTER TABLE categories ADD COLUMN IF NOT EXISTS prix_max INT DEFAULT 0;

-- Correction : tout client ayant une raison sociale est de type 'entreprise'
UPDATE clients SET type_client='entreprise' WHERE entreprise IS NOT NULL AND entreprise != '' AND type_client != 'entreprise';

-- Réglages email (SMTP) — envoi automatique de confirmations et factures
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

-- Identifiants Google (connexion OAuth) — configurables dans Paramètres
INSERT IGNORE INTO settings (cle, valeur) VALUES ('google_client_id',''), ('google_client_secret','');

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
