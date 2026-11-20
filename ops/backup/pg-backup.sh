#!/bin/sh
# Sauvegarde chiffrée de PostgreSQL (CDC §10 : quotidienne, chiffrée, rétention 30 j — T-906b).
# Dump au format personnalisé (compressé, restaurable table par table), chiffré avec age pour une clé PUBLIQUE :
# ni le serveur ni le stockage des sauvegardes ne peuvent le relire ; la clé privée reste hors ligne (coffre).
#
# Variables : PGURL (rôle trouvemoi_owner ou lecture seule, jamais affichée), AGE_RECIPIENT (clé publique age1…),
#             BACKUP_DIR (défaut /backups), RETENTION_DAYS (défaut 30), RCLONE_TARGET (facultatif, ex. « s3:sauvegardes/pg »).
# Dépendances : pg_dump 16, age, sha256sum, (rclone).
set -eu
: "${PGURL:?PGURL manquant}" "${AGE_RECIPIENT:?AGE_RECIPIENT manquant}"
BACKUP_DIR="${BACKUP_DIR:-/backups}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
umask 077
mkdir -p "$BACKUP_DIR"
name="trouvemoi-$(date -u +%Y%m%dT%H%M%SZ).dump.age"
tmp="$BACKUP_DIR/.$name.partial"
trap 'rm -f "$tmp"' EXIT

pg_dump --dbname="$PGURL" --format=custom --compress=6 --no-owner --no-privileges \
  | age --encrypt --recipient "$AGE_RECIPIENT" --output "$tmp"
mv "$tmp" "$BACKUP_DIR/$name"
(cd "$BACKUP_DIR" && sha256sum "$name" > "$name.sha256")
echo "sauvegarde $name ($(wc -c < "$BACKUP_DIR/$name") octets)"

if [ -n "${RCLONE_TARGET:-}" ]; then
  rclone copy "$BACKUP_DIR/$name" "$RCLONE_TARGET/"
  rclone copy "$BACKUP_DIR/$name.sha256" "$RCLONE_TARGET/"
  rclone delete --min-age "${RETENTION_DAYS}d" "$RCLONE_TARGET/"
fi
find "$BACKUP_DIR" -maxdepth 1 -name 'trouvemoi-*.dump.age*' -mtime +"$RETENTION_DAYS" -delete
