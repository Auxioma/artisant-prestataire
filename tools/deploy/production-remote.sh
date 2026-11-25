#!/usr/bin/env bash
# Mise en production sur le serveur du client (Plesk, sans Docker), lancée par le job « deploy » de la CI sous
# l'utilisateur Plesk du site, après `git reset --hard` sur le commit de main (même modèle que le déploiement
# trouvemoi.eu du client). Ne touche jamais à .env.local, var/ ni aux clés de secrets (fichiers non suivis).
#   DEPLOY_RESTART commande facultative après déploiement (ex. redémarrage du relais outbox, du scheduler)
set -euo pipefail
cd "$(dirname "$0")/../.."
export APP_ENV=prod APP_DEBUG=0

[ -f .env.local ] || [ -f .env.local.php ] || { echo "::error::.env.local absent : configuration de production introuvable" >&2; exit 1; }

# Garde-fou (dépôt déjà compromis) : jamais de kit .vscode ni de JS piégé en production.
if [ -d .vscode ] || grep -rlI createRequire assets templates config public 2>/dev/null | grep -q .; then
  echo "::error::fichiers suspects détectés (.vscode/ ou createRequire) : déploiement annulé" >&2; exit 1
fi

# PHP >= 8.4.1 de Plesk (le php du système peut être plus ancien).
for v in $(ls /opt/plesk/php 2>/dev/null | sort -rV); do
  if [ -x "/opt/plesk/php/$v/bin/php" ] && "/opt/plesk/php/$v/bin/php" -r 'exit(PHP_VERSION_ID >= 80401 ? 0 : 1);'; then
    export PATH="/opt/plesk/php/$v/bin:$PATH"; break
  fi
done
php -r 'exit(PHP_VERSION_ID >= 80401 ? 0 : 1);' || { echo "::error::PHP >= 8.4.1 introuvable (php = $(php -r 'echo PHP_VERSION;'))" >&2; exit 1; }
php -v | head -1

COMPOSER=(composer)
command -v composer >/dev/null || COMPOSER=(php /usr/local/psa/var/modules/composer/composer.phar)
"${COMPOSER[@]}" install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --classmap-authoritative --no-scripts
[ -f .env.local.php ] && [ ! -f .env.local ] || "${COMPOSER[@]}" dump-env prod

console() { php -d memory_limit=512M bin/console "$@" --no-interaction; }
rm -rf var/cache/prod
console cache:clear
console assets:install public
console importmap:install
console asset-map:compile
console doctrine:migrations:sync-metadata-storage
console doctrine:migrations:migrate --allow-no-migration
console app:settings:sync
console app:notification:sync
console app:labels:sync
console app:search:reindex || echo "::warning::réindexation de la recherche impossible (Meilisearch ?) : repli PostgreSQL"
console messenger:stop-workers   # les workers redémarrent sur le nouveau code (superviseur)
if [ -n "${DEPLOY_RESTART:-}" ]; then bash -c "$DEPLOY_RESTART"; fi
echo "✓ $(git log -1 --format='%h %s' 2>/dev/null | cut -c1-80) déployé"
