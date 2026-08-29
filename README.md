# Groupe Helisce — Application de gestion

Application de gestion pour traiteur : devis, factures, proformas, bons de livraison,
stock, employés, comptabilité, paiement en ligne Wave, espace client et site vitrine.

## Déploiement (Dokploy)

**Tout est déjà configuré.** Aucune variable d'environnement à créer.

1. Dans Dokploy : `Create Service` → **Compose**
2. Onglet **Provider** → GitHub → ce dépôt → branche `main`
3. **Compose Path** : `docker-compose.yml`
4. Cliquer sur **Deploy**

Au premier démarrage, la base est créée et remplie automatiquement.

### Après le déploiement

- Ouvrir `https://votre-domaine/admin/`
- Identifiants : `admin` / `admin123`
- **Changer ce mot de passe immédiatement** (Paramètres → Mot de passe)

### Une fois le domaine branché

Dans Dokploy, onglet **Environment**, ajouter :

```
SITE_URL=https://votre-domaine.com
```

Cette adresse sert aux QR codes d'authentification des documents et aux
notifications de paiement Wave.

## Mise à jour

1. Remplacer les fichiers du dossier local par ceux de la nouvelle version
2. GitHub Desktop : *Commit* puis *Push*
3. Dokploy : *Deploy*

Les données (clients, factures, paiements, fichiers) sont conservées : elles vivent
dans des volumes Docker persistants et les migrations n'effacent jamais rien.

## Sécurité — à faire avant l'ouverture aux clients

Modifier les mots de passe de la base dans `docker-compose.yml`
(`MARIADB_PASSWORD`, `MARIADB_ROOT_PASSWORD` et le `DB_PASS` du service `app` —
les deux mots de passe `traiteur` doivent rester identiques), puis redéployer.

> À faire **avant** le premier déploiement de préférence : MariaDB ne prend en compte
> le mot de passe qu'à la création initiale de la base.

## Structure

| Dossier | Contenu |
|---|---|
| `admin/` | Espace d'administration et générateurs de documents |
| `espace-client/` | Espace réservé aux clients |
| `config/` | Configuration, base de données, emails, Wave |
| `assets/` | Feuilles de style, images, scripts |
| `docker/` | Script de démarrage du conteneur |
| `docs/` | Documentation détaillée |
| `database.sql` | Schéma complet (premier déploiement) |
| `migrations.sql` | Mises à jour de structure (à chaque déploiement) |
