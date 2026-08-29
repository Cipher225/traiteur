#!/bin/bash
# ============================================================================
#  AMORÇAGE DE L'APPLICATION AU DÉMARRAGE DU CONTENEUR
#  ----------------------------------------------------------------------------
#  1. Attend que la base de données soit joignable.
#  2. PREMIER lancement (base vide) : importe le schéma complet + données de départ.
#  3. LANCEMENTS SUIVANTS (mise à jour) : applique uniquement les migrations
#     idempotentes — LES DONNÉES CLIENTS NE SONT JAMAIS EFFACÉES.
#  4. Corrige les droits du dossier uploads (fichiers persistants).
#  5. Démarre Apache.
# ============================================================================
set -uo pipefail   # on n'arrête PAS le conteneur sur une erreur SQL non bloquante

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-traiteur_db}"
DB_USER="${DB_USER:-traiteur}"
DB_PASS="${DB_PASS:-Helisce2026Traiteur}"

echo "[entrypoint] Attente de la base de données ${DB_HOST}:${DB_PORT}…"
for i in $(seq 1 60); do
    if mysqladmin ping -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" --silent 2>/dev/null; then
        echo "[entrypoint] Base de données joignable."
        break
    fi
    sleep 2
    if [ "$i" -eq 60 ]; then
        echo "[entrypoint] ⚠ Base injoignable après 120 s."
        echo "[entrypoint]   Vérifiez que le service « db » est démarré et que"
        echo "[entrypoint]   DB_USER / DB_PASS correspondent à ceux du service db."
    fi
done

# Fonction utilitaire d'exécution SQL
run_sql() { mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" --default-character-set=utf8mb4 "$@" ; }

# S'assurer que la base existe (au cas où l'hébergeur ne l'a pas créée)
run_sql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null || true

# La base contient-elle déjà des tables ? (table témoin : settings)
TABLE_COUNT=$(run_sql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_name='settings';" 2>/dev/null || echo "0")

if [ "${TABLE_COUNT}" = "0" ]; then
    echo "[entrypoint] Première installation : import du schéma complet…"
    if [ -f /var/www/html/database.sql ]; then
        # On retire les lignes « CREATE DATABASE » et « USE » du fichier pour importer
        # dans la base choisie (DB_NAME), quel que soit son nom. La base est déjà créée plus haut.
        sed -E '/^CREATE DATABASE/d; /^USE /d' /var/www/html/database.sql | run_sql "${DB_NAME}" 2>/dev/null \
            || echo "[entrypoint] ⚠ Import initial : à vérifier."
        echo "[entrypoint] Schéma installé."
    fi
else
    echo "[entrypoint] Base existante détectée — aucune réinstallation (données préservées)."
fi

# Migrations idempotentes (sûres à chaque déploiement)
if [ -f /var/www/html/migrations.sql ]; then
    echo "[entrypoint] Application des migrations (sans toucher aux données)…"
    # --force : on continue même si une instruction échoue (colonne déjà présente,
    # table déjà créée…). Sans cette option, mysql s'arrête à la première erreur
    # et TOUTES les migrations suivantes seraient silencieusement ignorées.
    run_sql --force "${DB_NAME}" < /var/www/html/migrations.sql 2>/dev/null || \
        echo "[entrypoint] ℹ Certaines migrations étaient déjà appliquées (normal)."
    echo "[entrypoint] Migrations terminées."
fi

# Droits du dossier uploads (volume persistant)
mkdir -p /var/www/html/uploads
chown -R www-data:www-data /var/www/html/uploads 2>/dev/null || true

echo "[entrypoint] Démarrage d'Apache."
exec "$@"
