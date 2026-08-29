# 🍽️ Groupe Helisce — Application de gestion (XAMPP)

Application web complète : site vitrine + espace admin de gestion (clients, comptabilité, facturation, paie).
Design **iOS 26 Liquid Glass**, allure application mobile.

## ✅ Prérequis
- XAMPP installé (Apache + MySQL démarrés)
- PHP 8.0 ou plus (inclus dans XAMPP récent)
- Les extensions PHP **`dom`**, **`xml`** et **`mbstring`** doivent être actives (elles le sont **par défaut dans XAMPP** — nécessaires pour la génération PDF des rapports).

## 🚀 Installation en 3 étapes

### 1. Copier le projet
Copiez le dossier `traiteur` dans :
```
C:\xampp\htdocs\traiteur
```

### 2. Créer la base de données
1. Ouvrez **http://localhost/phpmyadmin**
2. Onglet **Importer** → choisissez le fichier `database.sql` → **Exécuter**
3. La base `traiteur_db` est créée avec des données de démonstration.

### 3. Ouvrir l'application
- **Site public** : http://localhost/traiteur/
- **Espace admin** : http://localhost/traiteur/login.php (ou le bouton "Connexion" en haut à droite)

## 🔑 Identifiants par défaut
| Champ | Valeur |
|---|---|
| Utilisateur | `admin` |
| Mot de passe | `admin123` |

⚠️ **Changez ce mot de passe dès la première connexion** (Admin → Paramètres → Changer mon mot de passe).

## 🗂️ Structure du projet
```
traiteur/
├── index.php            → Site vitrine public
├── login.php / logout.php
├── database.sql         → Base de données à importer
├── config/db.php        → Connexion MySQL (root / sans mot de passe par défaut XAMPP)
├── lib/fpdf.php         → Librairie PDF (aucune installation requise)
├── api/devis.php        → Réception des demandes de devis
├── admin/               → Espace administrateur
│   ├── index.php        → Tableau de bord (activité + aperçu financier)
│   ├── commandes.php    → Demandes de devis (statuts, WhatsApp direct)
│   ├── clients.php      → Fichier clients
│   ├── comptabilite.php → Entrées, dépenses, solde, trésorerie
│   ├── factures.php     → Factures (lignes, TVA, remise, statuts)
│   ├── paie.php         → Fiches de paie (gains, retenues, net)
│   ├── employes.php     → Équipe / salariés
│   ├── pdf.php          → Génération PDF (factures + fiches)
│   ├── print.php        → Vue impression (A4)
│   ├── plats.php / categories.php / services.php
│   ├── galerie.php / temoignages.php
│   └── parametres.php   → Infos entreprise, RCCM/NCC, TVA + mot de passe
├── assets/css & js      → Design liquid glass
└── uploads/             → Photos envoyées depuis l'admin
```

## ✨ Fonctionnalités

**Site public** : hero animé, services, menu par catégories, galerie, **vidéos des prestations** (YouTube/Vimeo ou fichiers), témoignages, formulaire de devis, tab bar flottante sur mobile, bouton connexion discret en haut à droite, et un **footer complet avec informations légales** (forme juridique, capital, RCCM, NCC, siège social).

**Tout le contenu du site est éditable** depuis Paramètres (organisé en sections repliables) : titres et textes de chaque section, hero, boutons, description et mentions du bas de page.

**Compte client au devis** : lorsqu'un visiteur envoie une demande de devis depuis le site, il est **automatiquement ajouté à la liste des clients** dans l'admin et **invité à créer son espace client** (identifiant + mot de passe) pour suivre sa demande et recevoir son devis. S'il a déjà un compte, il est invité à se connecter.

**Espace client** : depuis « Clients », l'administrateur peut créer un accès (identifiant + mot de passe) pour chaque client. Le client se connecte via la même page de connexion et arrive sur **son espace personnel** avec tous ses menus : accueil (ses factures, devis et reçus consultables/imprimables), **Commander**, **Mes commandes**, et un formulaire pour laisser un avis. Un client ne voit que ses propres documents.

**Commande en ligne (panier sans prix → devis → suivi)** : depuis « Commander », le client parcourt les **menus présentés par catégorie sous forme de listes déroulantes** (compactes, toujours synchronisées avec la carte gérée dans l'admin), ajoute des plats au panier avec les quantités (**aucun prix affiché**) et précise son événement (date, invités, lieu, notes). Il envoie sa commande. Côté admin, « Commandes clients » reçoit la demande (avec notification), permet de la traiter et de **générer un devis (proforma)** pré-rempli à partir des plats commandés — l'admin saisit les prix puis passe le statut à « Devis envoyé ». Le client **suit l'évolution de sa commande** sur une frise (Reçue → En préparation → Devis disponible → Confirmée → Terminée) et consulte/télécharge son devis dès qu'il est prêt.

**Horaires d'accès des employés** : depuis « Employés & accès », l'administrateur définit les jours et heures de travail (par défaut du lundi au samedi, 8h–17h). En dehors de ces horaires, les employés ne peuvent pas accéder à leur espace ; l'administrateur et les clients ne sont pas concernés.

**Avis clients (modérés)** : ce sont les clients qui soumettent leurs avis — depuis le site public (formulaire en bas de la section « Avis ») ou depuis leur espace. Les avis arrivent **en attente** dans l'admin (Témoignages) ; l'administrateur les **publie ou les rejette**. Seuls les avis validés apparaissent sur le site.

**Gestion (admin)** :
- **Clients** : fichier complet avec recherche, lien WhatsApp, facturation en un clic.
- **Comptabilité** : saisie des entrées et dépenses par catégorie, mode de paiement, filtres par mois, calcul automatique du solde mensuel et de la trésorerie globale.
- **Factures** : lignes dynamiques (désignation / quantité / prix), calcul HT → remise → TVA → TTC, 4 statuts (brouillon, envoyée, payée, annulée), numérotation automatique (FAC-2026-0001).
- **Factures proforma (devis)** : même éditeur que les factures, libellé « FACTURE PROFORMA », numérotation dédiée (PRO-2026-0001), et **conversion en un clic d'une proforma en facture**.
- **Reçus de paiement** : montant, mode de paiement, motif, date, facture liée (facultatif), **montant écrit en toutes lettres**, numérotation auto (REC-2026-0001), stat « encaissé du mois ».
- **Fiches de paie** : gains (salaire, primes, heures sup.) et retenues (CNPS, ITS, autres), net à payer calculé automatiquement, numérotation auto.
- **Employés** : équipe avec salaire de base, CNPS, statut.
- Sur chaque document : **📄 générer le PDF**, **🖨️ imprimer**, **✉️ envoyer** (WhatsApp / e-mail), **✏️ modifier**, **✕ supprimer**.
- **Tableau de bord** : activité + aperçu financier du mois (entrées, dépenses, trésorerie, factures à encaisser) — masqué automatiquement aux employés non autorisés.
- **Vidéos** : ajout de vidéos de prestations par lien YouTube/Vimeo ou par fichier (mp4/webm), affichées sur le site.
- **Employés & accès (unifié)** : une seule fiche par employé regroupe ses informations RH (poste, matricule, catégorie, CNPS, banque/RIB, salaire), son **compte de connexion** (identifiant + mot de passe) et ses **permissions** section par section. Plus besoin de page séparée.
- **Bulletins de paie (légaux, mode banque)** : bulletin conforme avec en-tête employeur (RCCM, NCC, N° CNPS employeur, capital), informations du salarié (matricule, catégorie, CNPS, date d'embauche), détail des gains (salaire de base, sursalaire, primes de transport/ancienneté, indemnités, heures sup.) et des retenues (CNPS, ITS, avances), **net à payer en toutes lettres**, mode de paiement **virement bancaire** avec banque et n° de compte, charges patronales et mentions légales. Directement exploitable par l'employé pour faire valoir ses droits.
- **Espace employé & permissions** : depuis « Employés & accès », l'administrateur donne à un employé un accès à son espace et coche les sections autorisées. L'employé se connecte via la même page ; son menu et ses droits sont limités à ce qui lui est autorisé.
- **Messagerie & Forum** : messagerie directe entre l'administration et chaque employé (messages + pièces jointes), et un **forum d'équipe sous forme de conversation unique** (comme une discussion de groupe) où tous les messages s'enchaînent dans le même fil, avec possibilité d'épingler et de joindre des fichiers. Notifications de nouveaux messages.

**Coffre à documents** : un espace d'archivage pour tous les documents de l'entreprise (juridique, RH, comptabilité, contrats, fournisseurs, modèles…). Les documents se rangent dans des **dossiers** que l'administrateur peut créer, renommer ou supprimer. On y **téléverse** tout type de fichier (PDF, Word, Excel, PowerPoint, images, archives, audio/vidéo… jusqu'à 30 Mo), avec titre et description, puis on les retrouve par dossier ou par **recherche**, et on les télécharge en un clic. Le coffre **détecte aussi automatiquement tous les documents générés par l'application** (factures, proformas, reçus, bulletins de paie, rapports & demandes) et les range dans des dossiers dédiés « Documents de l'application », consultables et téléchargeables directement.

**Authentification des documents (QR anti-falsification) & signature/tampon** :
- Dans **Paramètres → « Signature, tampon & authentification »**, l'administrateur renseigne l'adresse du site, le nom et la fonction du signataire, et téléverse la **signature** et le **tampon** de l'entreprise (idéalement en PNG à fond transparent).
- Sur chaque document (facture, proforma, reçu, bulletin de paie **et** documents des employés), un bouton **🔐 « PDF authentifié »** (réservé à l'admin) génère une version portant un **QR code sécurisé**, une **empreinte** unique, la **signature** et le **tampon**. Le QR renvoie vers une page publique **`verifier.php`** où n'importe qui peut confirmer que le document est authentique et voir ses informations officielles (numéro, date, montant, client…). Comme ces informations proviennent de la base, tout exemplaire papier falsifié (montant ou nom modifié) est immédiatement démasqué. Chaque document reçoit un jeton unique de 128 bits, impossible à deviner.

**Tâches & rapports (admin ↔ employé)** :
- **Tâches** : l'administrateur assigne des tâches à un employé (titre, description, priorité, échéance). L'employé les **reçoit dans son espace avec une notification** (pastille sur la cloche 🔔 et compteur sur le menu). Il met à jour leur statut (à faire → en cours → terminé) et ajoute une note.
- **Rapports & demandes RH** : l'employé rédige, avec le **même éditeur de texte enrichi type Word** (titres, gras/italique/souligné/barré, police, taille, couleurs, listes, alignements, liens…), plusieurs types de documents : **rapport journalier**, **demande de permission**, **demande de congé**, **réponse à une demande d'explication** et **demande de congé maladie** (avec nom de l'hôpital/clinique, lieu, période d'arrêt, motif). Chaque type affiche les champs adaptés. Le document est **envoyé à l'administrateur** en **PDF A4** (une copie reste dans l'espace de l'employé), et l'administrateur le reçoit dans sa boîte de réception, filtrable par type.

**Horaires d'accès des employés** : depuis « Employés & accès », l'administrateur définit les jours et heures de travail (par défaut du lundi au samedi, 8h–17h). En dehors de ces horaires, les employés ne peuvent pas accéder à leur espace. L'administrateur peut toutefois accorder un **accès exceptionnel** à un employé (bouton 🕑 : pour 2 heures, pour la journée, ou jusqu'à une date/heure précise), et le révoquer à tout moment.

**Inscription en ligne (particulier ou entreprise)** : depuis la page de connexion, un bouton **« Créer un compte client »** mène à un formulaire unique où le visiteur choisit son profil (👤 Particulier ou 🏢 Entreprise) et saisit toutes ses informations. Le compte et la fiche client sont créés automatiquement (sans doublon si une fiche existe déjà), et le visiteur est connecté à son espace. Les demandes de devis renvoient aussi vers ce formulaire unique.

**🌗 Mode clair / sombre** : un bouton ☀️/🌙 (site public, page de connexion et espace admin) bascule toute l'application entre thème sombre et thème clair. Le choix est **mémorisé** sur l'appareil.

**Le contenu du site vitrine reste entièrement pilotable** depuis l'admin (contenu des sections, plats, catégories, services, galerie, vidéos, témoignages, informations légales).

## 🧾 À propos des PDF et de l'envoi
- Les **factures, proformas, reçus et fiches de paie** sont générés avec **FPDF** (inclus dans `lib/`, aucune installation ni connexion requise).
- Les **rapports** (contenu riche mis en forme) sont générés avec **Dompdf** (inclus dans `lib/dompdf/`, aucune installation requise) pour préserver la mise en forme de l'éditeur. Nécessite les extensions PHP `dom` et `mbstring`, actives par défaut dans XAMPP.
- **Imprimer** ouvre une vue A4 propre et lance la boîte d'impression du navigateur (permet aussi « Enregistrer en PDF »).
- **Envoyer** : en local, l'e-mail automatique n'est pas disponible sous XAMPP sans configuration SMTP. Le bouton propose donc de télécharger le PDF puis de l'envoyer via **WhatsApp** ou votre **logiciel e-mail** — la méthode la plus fiable en local.

## 🛠️ Dépannage
- **"Erreur de connexion à la base"** → vérifiez que MySQL est démarré dans XAMPP et que `traiteur_db` existe.
- **Accents bizarres dans le PDF** → normal si la police n'est pas standard ; FPDF gère l'essentiel via translittération automatique.
- **Mot de passe MySQL personnalisé ?** → modifiez `DB_PASS` dans `config/db.php`.
- **Le PDF d'un rapport ne se génère pas** → vérifiez que l'extension PHP `dom` est active (dans XAMPP : fichier `php.ini`, la ligne `extension=dom` ou `extension=xml` ne doit pas être commentée). Elle est active par défaut.
- **Accents "Ã©" à l'écran** → l'import de `database.sql` doit se faire en UTF-8 (phpMyAdmin le fait par défaut). Si besoin, réimportez la base en choisissant le jeu de caractères `utf-8`.

## 🎨 Identité visuelle

L'application utilise deux palettes complémentaires, marine & or.

**Site public — Marine profond & Or (premium).** Le site vitrine s'ouvre dans une ambiance marine élégante : le fond alterne en profondeur entre le marine très sombre (#060f22), le marine principal (#0a1f44) et le marine clair (#143264) pour rythmer les sections. L'or (#d4a526, éclairci en #f0c14b au survol) sert d'accent discret sur les boutons, icônes et liens actifs, avec un halo doré subtil sur les cartes survolées. Les textes utilisent un blanc cassé (#e7ecf5), jamais du blanc pur, avec deux niveaux adoucis (#aab6cc, #6b7a96).

**Espace connecté — Marine & Or, version claire.** L'admin, l'espace employé et l'espace client s'ouvrent sur un fond très clair (#f4f6f9) avec des cartes blanches. La barre latérale reste en marine profond avec l'élément actif souligné d'un liseré doré. Les statuts utilisent des couleurs sémantiques lisibles (succès vert, erreur rouge, info bleue, attention orangée) et les icônes de statistiques portent des dégradés variés (violet, bleu, sarcelle, or, corail, rose) pour distinguer les rubriques d'un coup d'œil.

Chaque espace mémorise son propre thème : le bouton ☀️/🌙 en haut permet de basculer, sans que le choix fait sur le site n'affecte l'espace de travail.

### Finitions de prestige

Plusieurs détails travaillent la matière et la lumière plutôt que d'ajouter de la couleur.

Les titres du site vitrine sont composés en **Playfair Display**, une serif de haute couture, soulignés d'un fin filet doré dégradé. Un **halo doré** éclaire discrètement le héros, comme un projecteur sur une table dressée, et de fins **séparateurs dorés de 1 pixel** ponctuent le passage d'une section à l'autre. Un **grain très léger** est appliqué sur toute l'interface : invisible à l'œil nu, il enlève l'aspect plat des grands aplats de marine et donne une texture imprimée.

Les boutons dorés reçoivent un reflet supérieur et un **éclat qui balaie** la surface au survol. Les cartes s'élèvent légèrement, leur arête supérieure s'illumine d'un liseré doré, et les champs de saisie s'entourent d'un anneau doré au focus. La barre de défilement elle-même est assortie.

Dans l'espace de travail, chaque titre de panneau porte une **barrette dorée**, les lignes de tableau révèlent un repère doré au survol, la barre latérale est nimbée d'une lueur dorée en tête, et les cartes de statistiques portent un lavis coloré discret assorti à leur icône.

Sur les documents, le nom de l'entreprise est composé en serif, le bandeau marine se termine par un **double filet doré**, et les libellés sont espacés en petites capitales.

Le site vitrine conserve toujours son ambiance marine premium (une identité unique, non basculable). L'espace de travail, lui, garde le choix clair/sombre.

## 🍽️ Le module Menu

Les plats et les catégories ne sont plus gérés séparément : tout se passe dans un seul module, **Menu**.

Vous y créez une **catégorie** — le libellé est totalement libre, ce n'est pas limité aux types de plats : *Pause café du matin*, *Cocktail dînatoire*, *Buffet mariage*, *Plats principaux*… Chaque catégorie peut recevoir une icône et une précision utile (par exemple « Servi de 8h à 10h30 »).

Une fois la catégorie créée, vous **ajoutez directement ses articles au même endroit**, sans changer de page : nom, description, prix, photo. Un prix laissé à 0 s'affiche « sur devis ». Chaque article peut être marqué **populaire** (mis en avant sur le site) ou **masqué** temporairement sans être supprimé — pratique pour un plat de saison.

Vous pouvez réordonner les catégories entre elles et les articles à l'intérieur de chaque catégorie avec les flèches, afin que le menu se présente exactement dans l'ordre voulu. Tout ce qui est publié ici apparaît immédiatement sur le site vitrine et dans l'espace client, où les clients composent leur commande.

Les anciennes adresses (`plats.php`, `categories.php`) redirigent automatiquement vers le Menu, et les employés qui avaient accès aux plats ou aux catégories conservent leur accès.

### Finitions métalliques

L'or et le marine ne sont pas des aplats : ce sont des **surfaces métalliques polies**. Chaque dégradé comporte des bandes sombres et claires successives qui simulent la réflexion de la lumière sur du métal — l'or passe du bronze profond à un reflet presque blanc avant de retomber, le marine a un éclat froid en son milieu. Des reflets internes (lumière sur l'arête haute, ombre sur l'arête basse) donnent l'épaisseur.

Ce traitement s'applique aux boutons dorés, à la barre latérale, aux en-têtes de conversation, aux médaillons, aux filets de séparation, et jusque dans les PDF — où le dégradé est reconstitué par bandes fines, puisque le format ne gère pas les dégradés nativement.

### Mise en cache des styles

Les feuilles de style et scripts sont appelés avec un numéro de version calculé sur la date du fichier. Après chaque mise à jour, le navigateur recharge donc automatiquement la nouvelle version : plus besoin de vider le cache ou de faire Ctrl+F5.

### Facturer une prestation complète

Sur une facture ou une proforma, une ligne représente désormais **une prestation entière**, pas un plat isolé.

Dans le formulaire, choisissez une catégorie du menu : ses articles s'affichent avec une case à cocher devant chacun. Cochez ceux qui sont inclus, puis cliquez sur « Ajouter au document ». La catégorie devient la ligne — par exemple *Pause café du matin* — et les éléments retenus se listent juste en dessous, **sans prix individuels**. Vous saisissez ensuite la quantité et **un seul prix, celui de la prestation** (30 000 par exemple, quel que soit le nombre d'éléments inclus).

Chaque élément inclus peut être retiré d'un clic sur sa croix, et vous pouvez en ajouter d'autres à la main dans le champ prévu. Les documents imprimés et les PDF reprennent exactement cette présentation : la prestation, son prix, et le détail de ce qu'elle comprend en retrait.

### TVA applicable ou non

Le formulaire propose deux options claires : **Applicable** — le taux s'affiche et se modifie — ou **Non applicable**. Dans ce second cas, aucune TVA n'est calculée et le document porte la mention « TVA non applicable » à la place du montant. En repassant sur « Applicable », le taux habituel de l'entreprise est automatiquement rétabli.

## 📄 Le papier à en-tête officiel

Tous les documents produits par l'application — facture, proforma, reçu, bulletin de paie, et l'ensemble des documents des employés (rapport, permission, congé, demande d'explication, arrêt maladie) — sont désormais composés sur **un seul et même papier à en-tête**, en PDF comme à l'écran.

**En-tête.** Le logo de l'entreprise apparaît en haut à gauche, surmontant la raison sociale et le slogan. À droite s'inscrit le type de document en grandes capitales espacées, souligné d'un double filet doré, suivi du bloc d'identification (numéro, date, échéance ou période selon le document). Un filet doré pleine largeur ferme l'en-tête.

**Coordonnées.** Deux colonnes se font face : à gauche le destinataire (client ou salarié), à droite les informations de gestion (référence, mode de paiement, vendeur, devise). Chaque valeur repose sur une conduite pointillée, comme sur un imprimé officiel.

**Corps.** Le tableau porte un bandeau d'en-tête marine et des colonnes numérotées. Les éléments inclus dans une prestation se listent en retrait sous leur ligne, sans prix. Les totaux s'alignent à droite, le total final sur un bandeau d'or métallisé. À gauche figure la mention « Arrêtée la présente facture à la somme de : » avec le montant en toutes lettres.

**Authentification et signature.** En bas, le QR code d'authentification accompagné de l'empreinte et du code de vérification ; en vis-à-vis, la fonction du signataire, le tampon, la signature et le nom du signataire.

**Pied de page.** Un filet doré puis quatre colonnes séparées : raison sociale et adresse, téléphones, email et site, enfin RC et numéro contribuable — chacune précédée d'une pastille dorée.

### Remplacer le logo

Un logo par défaut est fourni. Pour installer le vôtre, rendez-vous dans **Paramètres → Signature, tampon & authentification** : le premier champ permet de téléverser votre logo (PNG à fond transparent recommandé). Il remplace aussitôt le logo par défaut sur tous les documents. Une case permet de revenir au logo d'origine.

## 🔔 Les notifications

Chaque espace dispose d'une **cloche** en haut à droite. Elle affiche le nombre total d'éléments qui vous attendent, et son panneau détaille chaque catégorie avec un lien direct.

**Administrateur** : rapports et demandes reçus, nouvelles commandes de l'espace client, demandes de devis venues du site, messages non lus, avis clients en attente de validation, messages du forum.

**Employé** : nouvelles tâches assignées, réponses de la direction à ses demandes (permission, congé…), messages non lus, messages du forum.

**Client** : devis disponibles, commandes dont le statut a évolué, nouvelles factures et nouveaux reçus.

Les compteurs se remettent à zéro automatiquement lorsque vous ouvrez la page concernée : consulter le forum efface son badge, ouvrir « Mes commandes » efface celui des commandes, etc.

## 📦 Demandes de devis et commandes réunies

Le menu ne contient plus qu'une seule entrée, **Commandes clients**, organisée en deux onglets : les *commandes passées depuis l'espace client* et les *demandes de devis reçues via le formulaire du site*. Chaque onglet affiche son propre compteur de nouveautés. Les anciennes adresses redirigent automatiquement.

## 📱 Utilisation sur téléphone

L'application s'adapte aux écrans de téléphone : la barre latérale se replie derrière le bouton ☰, les formulaires passent sur une colonne, les tableaux défilent horizontalement du doigt sans déformer la page, et le panneau de notifications s'ajuste à la largeur de l'écran. Les documents (factures, reçus, bulletins) se consultent également sur mobile, la feuille s'adaptant à l'écran tout en conservant sa mise en page à l'impression.

## 📤 Bons de sortie et bons d'entrée

Les « reçus » sont remplacés par deux registres distincts, accessibles depuis le menu.

**Bons de sortie** (numérotés BS-AAAA-0001) : ils constatent une somme décaissée. C'est le document remis au client ou au bénéficiaire.

**Bons d'entrée** (numérotés BE-AAAA-0001) : ils constatent une somme encaissée — acompte, règlement, apport. La colonne de gauche du document s'intitule alors « Fournisseur / Émetteur » plutôt que « Client ».

Les deux se créent depuis la même interface, chacun avec sa propre liste, son propre total mensuel et sa propre numérotation. Ils reprennent le papier à en-tête officiel, avec QR d'authentification, tampon et signature. Dans le coffre à documents, ils apparaissent dans deux dossiers séparés.

## 🌐 Mettre l'application en ligne (hébergement)

L'application est prête pour un hébergement mutualisé (cPanel, Plesk…) ou un serveur. Trois étapes :

**1. Envoyer les fichiers.** Copiez tout le dossier `traiteur` dans le répertoire web de votre hébergeur (souvent `public_html/` ou `www/`).

**2. Créer et importer la base.** Depuis le panneau de votre hébergeur, créez une base de données MySQL, puis importez le fichier `database.sql` (via phpMyAdmin : onglet « Importer »).

**3. Renseigner les identifiants.** Ouvrez le fichier `config/config.php` et remplacez les quatre lignes de base de données par les informations fournies par votre hébergeur :

```
define('DB_HOST', 'localhost');     // hôte MySQL (souvent localhost)
define('DB_NAME', 'votre_base');    // nom de la base créée
define('DB_USER', 'votre_user');    // utilisateur MySQL
define('DB_PASS', 'votre_mot_passe'); // mot de passe MySQL
```

C'est le **seul fichier à modifier**. Rien d'autre à toucher.

**Sauvegarde des fichiers téléversés.** Le logo, la signature, le tampon, les photos du menu, les vidéos et les documents du coffre sont enregistrés dans le dossier `uploads/`. Ce dossier (et son sous-dossier `uploads/coffre/`) est créé automatiquement au premier chargement. Assurez-vous qu'il est **accessible en écriture** (permission 755 ou 775 selon votre hébergeur). Lors de vos sauvegardes, pensez à sauvegarder à la fois la **base de données** et le **dossier `uploads/`**.

**Encodage.** La base et l'application utilisent utf8mb4 : les accents et caractères spéciaux (é, è, à, œ, —) sont gérés correctement partout.

## 🔐 Comptes, connexion Google et mots de passe

### Connexion avec Google

Pour activer « Continuer avec Google » :
1. Allez sur https://console.cloud.google.com/apis/credentials
2. Créez un « ID client OAuth 2.0 » de type « Application Web »
3. Ajoutez comme URI de redirection autorisée : `https://VOTRE-SITE/google-callback.php`
4. Collez l'identifiant et le secret dans `config/config.php` (GOOGLE_CLIENT_ID et GOOGLE_CLIENT_SECRET)

Si ces champs restent vides, le bouton Google n'apparaît simplement pas. Quand un utilisateur se connecte pour la première fois via Google, un compte client est créé automatiquement et il est invité à compléter son profil.

### Modifier ses identifiants

Chaque utilisateur (admin, employé, client) dispose d'une page **« Mon profil »** accessible depuis son menu. Il peut y modifier son nom, son identifiant, son email, son téléphone et son mot de passe. Un utilisateur venu de Google peut aussi y définir un mot de passe classique.

### Mot de passe oublié

Le système fonctionne par code, sans dépendre d'un service d'email :
1. L'administrateur ouvre **« Comptes & mots de passe »**, trouve l'utilisateur et clique sur **« Générer un code »**. Un code à 8 caractères s'affiche, valable 24 heures.
2. L'administrateur communique ce code à la personne (téléphone, message…).
3. La personne va sur la page de connexion, clique sur **« Mot de passe oublié ? »**, saisit son identifiant et le code, puis choisit un nouveau mot de passe.

L'administrateur peut aussi, depuis la même page, réinitialiser directement un mot de passe en cas de dépannage.

## 🪪 Badges & cartes professionnelles

Le module **« Badges & cartes »** (menu admin) permet d'émettre des badges et cartes professionnelles aux couleurs de l'entreprise.

### Émettre un badge
Deux types de porteurs :
- **Employé** : sélectionnez-le dans la liste, ses informations se remplissent automatiquement.
- **Membre externe** : invité, prestataire… saisi manuellement, avec son organisation.

Ajoutez une photo (portrait carré recommandé), une date d'expiration facultative, puis validez. Un **matricule unique** est généré automatiquement.

### Deux formats d'impression
- **Carte professionnelle** (💳) : format carte de visite 85,6 × 54 mm, recto (photo + identité) et verso (coordonnées + QR).
- **Badge** (🪪) : format tour de cou 54 × 86 mm, avec photo ronde, QR et matricule.

Chacun s'ouvre dans une page imprimable avec le bouton « Imprimer / Enregistrer en PDF ».

### Le QR code
Il contient une **vCard** : en le scannant avec un téléphone, la personne enregistre directement le contact (nom, poste, société, téléphone, email, matricule) dans son répertoire.

### Format du matricule (paramétrable)
Dans **Paramètres → Badges & matricules**, vous choisissez :
- le préfixe des employés (ex : HEL) et des externes (ex : EXT) ;
- le format du matricule à l'aide de jetons : `{PREFIXE}` `{ANNEE}` `{MOIS}` `{SEQ3}` `{SEQ4}` `{SEQ5}`.

Exemple : `{PREFIXE}-{ANNEE}-{SEQ4}` donne `HEL-2026-0001`.

## 🔒 Sécurité de session (nouveau)

- **Connexion unique** : si un compte se connecte sur un nouvel appareil, la session précédente est automatiquement fermée. Un même compte ne peut donc pas être actif à deux endroits en même temps.
- **Déconnexion sur inactivité** : après 30 minutes sans activité, l'utilisateur est déconnecté et doit se reconnecter. Le délai se règle dans `admin/includes/auth.php` (constante `INACTIVITE_MAX`).

## 🎨 Nouveau style du site (iOS 26 « Liquid Glass »)

Le site vitrine a été enrichi d'une couche visuelle inspirée d'iOS 26 : surfaces en verre translucide profond, halos lumineux animés en arrière-plan, boutons en pilule, apparition douce des sections au défilement. Tout est ajouté dans `assets/css/ios26.css` sans modifier la structure du site — aucune fonctionnalité existante n'est affectée.

## 🪪 Cartes & badges — modèle premium et QR sécurisé (mise à jour)

Les cartes professionnelles et badges reprennent désormais le modèle premium navy & or : photo ronde cerclée d'or, courbes élégantes, monogramme de l'entreprise, informations en deux colonnes (groupe sanguin, dates, poste…), et au verso les services, le QR et le bandeau de valeurs. Le badge est la version verticale du même design.

### Nouveaux champs
À l'émission d'un badge, vous pouvez renseigner : groupe sanguin, date de naissance, date d'embauche, en plus des informations existantes.

### QR sécurisé (protection en cas de perte)
Le QR ne contient plus les coordonnées en clair : il pointe vers une **page de vérification en ligne** (`verifier-badge.php`). Quand on le scanne :
1. La page confirme d'abord que la personne est un **membre authentique** de l'entreprise (nom, photo, matricule), sans exposer ses coordonnées.
2. Les coordonnées complètes ne s'affichent qu'après un **clic volontaire supplémentaire**, et seulement si le badge est actif.

Un badge suspendu, expiré ou contrefait est immédiatement signalé. Pour que le scan fonctionne depuis un téléphone, renseignez l'adresse du site dans **Paramètres → Adresse du site** (ex : https://www.groupehelisce.com).

### Matricule robuste et personnalisable
Le format se règle dans **Paramètres → Badges & matricules** avec un **aperçu en direct**. Jetons disponibles : `{PREFIXE}` `{ANNEE}` `{MOIS}` `{SEQ3}` `{SEQ4}` `{SEQ5}`. L'unicité est garantie (protection anti-collision testée sur générations multiples).

## 🪪 Matricule = identité de l'employé (mise à jour)

Le matricule est désormais **l'identifiant officiel de l'employé**, généré automatiquement à la création de sa fiche (menu **Employés & accès**). Il le suit partout : badge, carte, documents.

**Créer une carte ou un badge est devenu très simple :**
1. Dans le module **Badges & cartes**, choisissez « Employé ».
2. Sélectionnez la personne dans la liste : nom, poste, département, groupe sanguin, téléphone, email et **matricule** se remplissent automatiquement.
3. Ajoutez la photo et la date de validité si besoin, puis émettez.

Le badge reprend exactement le **même matricule** que l'employé — un seul identifiant, aucune double saisie. Les membres externes (invités, prestataires) continuent d'être créés manuellement dans le module Badges.

La fiche employé comporte maintenant les champs département, groupe sanguin, date de naissance et photo, utilisés pour le badge et la carte. Le matricule s'affiche en or sur le badge et dans une pastille dorée sur la carte, avec le libellé « Matricule ».

## 🗂️ Rangement des documents (nouveau)

Quand les documents s'accumulent, ils sont désormais rangés proprement à tous les niveaux :

**Admin — Factures, Proformas, Coffre**
- Des **filtres rapides** en haut : par client, par mois, par année (application immédiate).
- Une **arborescence repliable** : Année → Mois → Client, avec un compteur à chaque niveau. On déplie ce qu'on veut consulter.
- Un bouton bascule **Arborescence / Liste** selon la préférence du moment.

**Espace client**
- Ses documents sont rangés par **type** : Mes factures, Mes devis (proformas), Mes bons de sortie — chaque type replié par année et par mois.

**Espace employé**
- Ses documents (rapports, demandes, bulletins) sont regroupés par **année et par mois**.

Tout reste fluide même avec des centaines de documents.

## Mise à jour — Messagerie, tableau de bord et rangement

**⚠️ Cette mise à jour ajoute des colonnes à la base.** Après avoir remplacé le dossier `traiteur`, il faut **supprimer la base `traiteur_db` puis réimporter `database.sql`** (via phpMyAdmin ou en ligne de commande).

### Messagerie étendue
- L'administrateur peut écrire aux **clients et aux employés** (filtres « Tous / Employés / Clients »).
- Les clients disposent d'une **messagerie** dans leur espace pour échanger avec l'administration.
- Les **pièces jointes** sont acceptées jusqu'à **200 Mo** : documents, images, vidéos, PDF, fichiers CAO (DWG, DXF…).
- Le fichier `.htaccess` à la racine configure PHP pour ces envois volumineux. Sur un hébergement mutualisé, si les gros fichiers sont refusés, augmentez `upload_max_filesize` et `post_max_size` dans la configuration PHP de l'hébergeur.

### Tableau de bord enrichi
- Une **horloge** en temps réel (heure et date en français).
- Une carte des **comptes actuellement en ligne**, avec le rôle, la **ville** et l'**adresse IP réelle** de chaque connexion. La ville est déduite de l'IP à la connexion (nécessite un accès Internet sortant du serveur ; en local, l'IP privée est indiquée comme « Réseau local »).

### Rangement généralisé
Le rangement (filtres + arborescence Année → Mois → Client) s'applique désormais aussi aux **bons de sortie/entrée** et aux **bulletins de paie**, en plus des factures, proformas, coffre et rapports.
