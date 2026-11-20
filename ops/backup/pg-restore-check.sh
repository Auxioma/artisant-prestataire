#!/bin/sh
# Test de restauration (CDC §10, SECURITY.md §7 — mensuel, T-906b) : restaure une sauvegarde chiffrée dans une base
# VIERGE et temporaire, contrôle son contenu, puis la supprime. Ne touche jamais la base de production.
#
# Usage : pg-restore-check.sh <fichier .dump.age>
# Variables : ADMIN_URL (rôle autorisé à créer une base, URL sur la base « postgres »), AGE_IDENTITY (fichier de clé
#             privée age, monté depuis le coffre le temps du test), CHECK_DB (défaut trouvemoi_restore_check).
# Code de sortie 0 = restauration exploitable.
set -eu
file="${1:?fichier de sauvegarde manquant}"
: "${ADMIN_URL:?ADMIN_URL manquant}" "${AGE_IDENTITY:?AGE_IDENTITY manquant}"
db="${CHECK_DB:-trouvemoi_restore_check}"
case "$db" in trouvemoi_restore_*) ;; *) echo "CHECK_DB doit commencer par trouvemoi_restore_ (protection)"; exit 2;; esac
target=$(printf '%s' "$ADMIN_URL" | sed "s#/postgres\(?.*\)\{0,1\}\$#/$db\1#")
[ "$target" != "$ADMIN_URL" ] || { echo "ADMIN_URL doit viser la base « postgres »"; exit 2; }

if [ -f "$file.sha256" ]; then
  if ! (cd "$(dirname "$file")" && sha256sum -c "$(basename "$file").sha256" >/dev/null 2>&1); then
    echo "EMPREINTE INVALIDE : sauvegarde altérée ou incomplète"; exit 1
  fi
  echo "empreinte vérifiée"
fi
export PGOPTIONS='-c client_min_messages=warning'
start=$(date +%s)
psql "$ADMIN_URL" -qAtc "DROP DATABASE IF EXISTS $db" -c "CREATE DATABASE $db"
trap 'psql "$ADMIN_URL" -qAtc "DROP DATABASE IF EXISTS '"$db"'" >/dev/null 2>&1 || true' EXIT

age --decrypt --identity "$AGE_IDENTITY" "$file" \
  | pg_restore --dbname="$target" --no-owner --no-privileges --exit-on-error

tables=$(psql "$target" -qAtc "SELECT count(*) FROM pg_tables WHERE schemaname IN ('identity','artisan','request','quote','billing','messaging','admin','catalog')")
migrations=$(psql "$target" -qAtc "SELECT count(*) FROM public.doctrine_migration_versions")
users=$(psql "$target" -qAtc "SELECT count(*) FROM identity.user_account" 2>/dev/null || echo '?')
audit=$(psql "$target" -qAtc "SELECT count(*) FROM admin.audit_log")
echo "tables métier : $tables · migrations : $migrations · comptes : $users · journal d'audit : $audit · durée : $(( $(date +%s) - start )) s"
[ "$tables" -gt 50 ] && [ "$migrations" -gt 0 ] || { echo "RESTAURATION INCOMPLÈTE"; exit 1; }
echo "RESTAURATION OK — consigner le résultat (docs/exploitation.md §4)"
