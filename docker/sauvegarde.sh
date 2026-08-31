#!/bin/bash
# ============================================================================
#  SAUVEGARDE AUTOMATIQUE DE LA BASE DE DONNÉES
#  ----------------------------------------------------------------------------
#  Exécutée chaque nuit. Conserve les 7 dernières sauvegardes dans le dossier
#  uploads/sauvegardes, qui est un volume Docker PERSISTANT : les fichiers
#  survivent aux redéploiements.
# ============================================================================
set -uo pipefail

DOSSIER="/var/www/html/uploads/sauvegardes"
DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-traiteur_db}"
DB_USER="${DB_USER:-traiteur}"
DB_PASS="${DB_PASS:-Helisce2026Traiteur}"
GARDER=7

mkdir -p "$DOSSIER"
FICHIER="$DOSSIER/sauvegarde-$(date +%Y-%m-%d-%H%M).sql.gz"

if mysqldump -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" \
        --single-transaction --quick --default-character-set=utf8mb4 \
        "$DB_NAME" 2>/dev/null | gzip -9 > "$FICHIER"; then
    TAILLE=$(du -h "$FICHIER" | cut -f1)
    echo "$(date '+%Y-%m-%d %H:%M') | Sauvegarde réussie : $(basename "$FICHIER") ($TAILLE)" \
        >> "$DOSSIER/journal.txt"
else
    rm -f "$FICHIER"
    echo "$(date '+%Y-%m-%d %H:%M') | ÉCHEC de la sauvegarde" >> "$DOSSIER/journal.txt"
    exit 1
fi

# On ne conserve que les N plus récentes
cd "$DOSSIER" && ls -1t sauvegarde-*.sql.gz 2>/dev/null | tail -n +$((GARDER + 1)) | xargs -r rm -f
