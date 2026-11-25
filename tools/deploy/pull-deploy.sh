#!/usr/bin/env bash
# Déploiement « tiré » par le serveur trouvemoi.pro (Plesk), sans aucun secret sur GitHub : lancé par cron sous
# l'utilisateur Plesk du site. Si main a avancé sur GitHub ET que la CI (« Qualité et tests ») de ce commit a réussi,
# le code est mis à jour dans httpdocs puis tools/deploy/production-remote.sh est exécuté. Sinon : rien.
# Installation et suivi : docs/exploitation.md (« Déploiement tiré par le serveur »).
set -euo pipefail
REPO="${DEPLOY_REPO:-Auxioma/artisant-prestataire}"
BRANCH="${DEPLOY_BRANCH:-main}"
SITE_DIR="${DEPLOY_PATH:-/var/www/vhosts/trouvemoi.pro/httpdocs}"
CHECK="Qualité et tests"
log() { echo "[$(date '+%F %T')] $*"; }

cd "$SITE_DIR"
exec 9>"${TMPDIR:-/tmp}/trouvemoi-deploy.lock"
flock -n 9 || exit 0   # un déploiement est déjà en cours

[ -d .git ] || git init -q
git fetch -q --depth=1 "https://github.com/$REPO.git" "$BRANCH"
target=$(git rev-parse FETCH_HEAD)
current=$(git rev-parse -q --verify HEAD || true)
[ "$target" != "$current" ] || exit 0

# Seulement un commit validé par la CI.
status=$(curl -fsS -H 'Accept: application/vnd.github+json' "https://api.github.com/repos/$REPO/commits/$target/check-runs?per_page=100" \
  | python3 -c 'import json,sys; r=[c for c in json.load(sys.stdin)["check_runs"] if c["name"]==sys.argv[1]]; print(r[0]["conclusion"] or r[0]["status"] if r else "absent")' "$CHECK") || status="inconnu"
if [ "$status" != "success" ]; then
  log "commit ${target::8} : CI « $CHECK » = $status, on attend"; exit 0
fi

# Garde-fou (dépôt déjà compromis) : jamais de kit .vscode ni de JS piégé.
if git ls-tree -d --name-only FETCH_HEAD .vscode | grep -q . || git grep -qI -e createRequire FETCH_HEAD -- assets templates config public; then
  log "commit ${target::8} REFUSÉ : fichiers suspects (.vscode/ ou createRequire)"; exit 1
fi

log "déploiement de ${target::8} (précédent : ${current:0:8})"
git reset -q --hard "$target"
rm -rf var/cache/prod public/assets
bash tools/deploy/production-remote.sh
log "terminé"
