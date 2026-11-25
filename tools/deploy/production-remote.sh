#!/usr/bin/env bash
# Mise en production sur le serveur du client (Plesk, sans Docker) : lancé par le job « deploy » de la CI après la
# copie du code (rsync). Exécuté à la racine du projet. Ne touche jamais à .env.local, var/ ni aux clés de secrets.
#   DEPLOY_PHP     binaire PHP (défaut /opt/plesk/php/8.4/bin/php)
#   DEPLOY_OWNER   propriétaire Plesk des fichiers, « utilisateur:groupe » (ex. trouvemoi:psacln) ; commandes lancées sous son nom
#   DEPLOY_RESTART commande facultative après déploiement (ex. redémarrage du relais outbox, du scheduler, de PHP-FPM)
set -euo pipefail
cd "$(dirname "$0")/../.."

PHP="${DEPLOY_PHP:-/opt/plesk/php/8.4/bin/php}"
OWNER="${DEPLOY_OWNER:-}"
[ -f .env.local ] || { echo "✗ .env.local absent : configuration de production introuvable" >&2; exit 1; }

# Garde-fou (dépôt déjà compromis) : jamais de kit .vscode ni de JS piégé en production.
if [ -d .vscode ] || grep -rlI createRequire assets templates config public 2>/dev/null | grep -q .; then
  echo "✗ fichiers suspects détectés (.vscode/ ou createRequire) : déploiement annulé" >&2; exit 1
fi

if [ -n "$OWNER" ] && [ "$(id -u)" = 0 ]; then
  chown -R "$OWNER" .
  run() { runuser -u "${OWNER%%:*}" -- "$@"; }
else
  run() { "$@"; }
fi
console() { run env APP_ENV=prod APP_DEBUG=0 "$PHP" -d memory_limit=512M bin/console "$@" --no-interaction; }

rm -rf var/cache/prod
console cache:warmup
console importmap:install
console asset-map:compile
console doctrine:migrations:migrate --allow-no-migration
console app:settings:sync
console app:notification:sync
console app:labels:sync
console app:search:reindex
console messenger:stop-workers   # les workers redémarrent sur le nouveau code (superviseur)
if [ -n "${DEPLOY_RESTART:-}" ]; then
  bash -c "$DEPLOY_RESTART"
fi
echo "✓ $(cat REVISION 2>/dev/null || echo 'révision inconnue') déployée"
