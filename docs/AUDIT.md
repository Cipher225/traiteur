# Audit général — Application Groupe Helisce

Analyse complète de l'application de gestion (traiteur & événementiel) : sécurité, fonctionnalité, structure et qualité. Réalisée sur l'ensemble du code (61 fichiers PHP, ~8 800 lignes, 26 tables, 946 règles CSS).

---

## Synthèse

| Dimension | Évaluation |
|---|---|
| Sécurité | Solide — aucune faille critique détectée |
| Fonctionnalité | Complète — toutes les pages opérationnelles |
| Structure | Saine — dépendances et base cohérentes |
| Qualité du code | Bonne — pratiques homogènes |

L'application est **prête pour un déploiement**. Quelques points d'amélioration secondaires sont listés en fin de rapport, aucun n'étant bloquant.

---

## 1. Sécurité

### Points forts confirmés

**Injections SQL — protégé.** Toutes les requêtes utilisent des requêtes préparées (PDO avec paramètres liés). Les rares variables interpolées directement dans une requête sont systématiquement castées en entier `(int)` avant usage, ce qui neutralise tout risque d'injection.

**XSS (cross-site scripting) — protégé.** Aucune sortie de variable utilisateur (`$_GET`, `$_POST`) n'est affichée sans passer par la fonction d'échappement `e()`. On compte 1 418 appels à cette fonction dans le code.

**CSRF — protégé intégralement.** Les 28 fichiers contenant des formulaires POST vérifient tous un jeton CSRF via `csrf_check()`. 60 champs de jeton sont générés côté formulaire. Aucune action sensible n'est possible sans jeton valide.

**Mots de passe — sécurisés.** Tous les mots de passe sont hachés avec `password_hash()` (algorithme par défaut, bcrypt). Aucun mot de passe n'est jamais stocké ou comparé en clair. La vérification utilise `password_verify()`.

**Contrôle d'accès — cohérent.** Chaque page protégée inclut le contrôle d'authentification (`auth.php` côté admin, `inc.php` côté client). Les pages réservées à l'administrateur vérifient `is_admin()` (11 fichiers). Le seul fichier sans contrôle direct (`pdf_template.php`) est une bibliothèque de constantes sans sortie, incluse par d'autres pages — sans risque.

**Isolation des données (IDOR) — protégé.** Un client ne peut consulter que ses propres documents : `doc.php` vérifie que le document appartient bien au client connecté (`$doc['client_id'] === $cid`), sinon renvoie une erreur 403. Un client ne peut donc pas accéder aux factures d'un autre en modifiant l'URL.

**Uploads de fichiers — encadrés.** La fonction d'upload valide l'extension (liste blanche : jpg, jpeg, png, webp, gif pour les images), limite la taille (5 Mo images, 60 Mo vidéos), génère un nom de fichier aléatoire et utilise `move_uploaded_file()`. Les dossiers `uploads/` et `uploads/coffre/` bloquent l'exécution de tout script PHP via `.htaccess` — un fichier malveillant téléversé ne pourrait pas être exécuté.

**Sessions — durcies.** La session est régénérée à la connexion (`session_regenerate_id(true)`, anti-fixation). Le système de session unique empêche la double connexion simultanée. La déconnexion automatique après 30 minutes d'inactivité est en place.

**Brute-force — limité.** Le login bloque après 5 tentatives échouées, avec un verrou de 5 minutes.

**Tokens — cryptographiques.** Les jetons de vérification (badges, documents, réinitialisation, OAuth) utilisent `random_bytes()`, source aléatoire sûre.

**Vérification des badges — sécurisée.** Le QR code d'un badge pointe vers une page de vérification en ligne à deux étapes : la première confirme l'authenticité sans exposer les coordonnées (protège en cas de perte), la seconde affiche les détails seulement après action volontaire et si le badge est actif.

### Recommandations sécurité (non bloquantes)

- **Type MIME réel des uploads** : la validation se fait sur l'extension du fichier. Ajouter une vérification du type MIME réel (via `finfo`) renforcerait la défense, même si le blocage d'exécution PHP dans `uploads/` couvre déjà l'essentiel du risque.
- **En-têtes de sécurité HTTP** : en production, ajouter des en-têtes comme `X-Content-Type-Options`, `X-Frame-Options` et une politique CSP au niveau du serveur (ou d'un `.htaccess` racine) apporterait une protection supplémentaire côté navigateur.
- **HTTPS obligatoire** : une fois en ligne, forcer HTTPS (redirection + cookies `Secure`) est indispensable, notamment parce que le QR des badges pointe vers une adresse publique.

---

## 2. Fonctionnalité

**Compilation.** Les 61 fichiers PHP compilent sans la moindre erreur de syntaxe.

**Rendu des pages.** Toutes les interfaces ont été testées et s'affichent correctement :
- 26 pages d'administration (tableau de bord, factures, proformas, bons, comptabilité, employés, badges, coffre, paramètres, etc.).
- 4 pages de l'espace client (accueil, commandes, commander, profil).
- Le site vitrine public.
- Les pages de connexion et d'inscription.
- Les documents imprimables (factures, proformas, bulletins de paie, badges et cartes).

**Cohérence métier vérifiée.** Le matricule employé est bien réutilisé comme identifiant unique sur son badge. Le bulletin de paie recalcule le net si nécessaire. Le rangement des documents (arborescence + filtres) fonctionne côté admin, client et employé.

---

## 3. Structure

**Base de données — cohérente.** Les 26 tables sont toutes en moteur InnoDB (transactionnel, avec support des clés étrangères) et en encodage utf8mb4_unicode_ci (accents et caractères spéciaux corrects). L'import complet du schéma crée bien les 26 tables avec toutes leurs colonnes.

**Intégrité référentielle — saine.** Aucun enregistrement orphelin détecté : pas de facture sans client valide, pas de badge sans employé valide, pas de bulletin sans employé.

**Dépendances — intactes.** Aucun `require`/`include` ne pointe vers un fichier inexistant. Aucune fonction n'est définie en double (pas de conflit de déclaration).

**Organisation — claire.** Le code est bien réparti : logique partagée dans `admin/includes/` (64 fonctions helper), configuration isolée dans `config/`, un seul fichier à éditer pour le déploiement (`config/config.php`). Les composants réutilisables (rangement, gabarit documentaire, badges) évitent la duplication.

### Recommandation structure (non bloquante)

- **Nettoyage du fichier `database.sql`** : au fil du développement, le fichier a accumulé 57 instructions `ALTER TABLE` en fin de fichier (certaines colonnes ajoutées plusieurs fois). L'import sur une base vierge fonctionne parfaitement, mais le fichier n'est pas « idempotent » (le relancer sur une base existante produit des erreurs sans conséquence). Pour une version finale propre, ces ALTER pourraient être fusionnés directement dans les instructions `CREATE TABLE` correspondantes. C'est un travail cosmétique, sans impact fonctionnel.

---

## 4. Qualité du code

**Cohérence.** Les pratiques sont homogènes dans tout le projet : même fonction d'échappement, même gestion CSRF, même structure de page, même charte visuelle (navy & or).

**CSS.** Les 4 fichiers de style (946 règles) ont tous leurs accolades équilibrées — aucune règle cassée.

**Robustesse.** 13 blocs `try/catch` sur les opérations sensibles, vérifications d'existence systématiques avant usage des données, 73 troncatures de longueur sur les entrées.

**Configuration production.** Aucun `display_errors` activé en dur (pas de fuite d'information technique vers les visiteurs). Le fichier `config.php` centralise tous les réglages d'hébergement avec des commentaires explicatifs.

---

## Conclusion

L'application présente un **niveau de sécurité solide** (protection contre les injections SQL, XSS, CSRF, IDOR, brute-force, et uploads malveillants), une **couverture fonctionnelle complète** et testée, et une **structure saine**. Aucune faille critique ni aucun défaut bloquant n'a été identifié.

Les recommandations formulées sont des améliorations de confort et de robustesse pour l'exploitation en production (type MIME des uploads, en-têtes HTTP, HTTPS, nettoyage cosmétique du schéma), sans caractère urgent.

En l'état, l'application est apte à être déployée et utilisée.
