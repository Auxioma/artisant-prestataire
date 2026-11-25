#!/usr/bin/env bash
# CI : retrouve l'accès SSH au serveur parmi les secrets du dépôt, quels que soient les noms choisis par le client
# (APP_HOST, SSH_HOST, SERVER_HOST, DEPLOY_HOST…), sans jamais afficher de valeur. Entrée : SECRETS_JSON
# (= toJSON(secrets)). Sortie : SSH (commande prête à l'emploi) dans $GITHUB_ENV, ready=true|false dans $GITHUB_OUTPUT.
set -euo pipefail
# Premier secret non vide parmi les noms donnés (valeur sur la sortie standard, jamais affichée dans le journal).
pick() { python3 -c 'import json,os,sys; d=json.loads(os.environ.get("SECRETS_JSON") or "{}"); print(next((d[k] for k in sys.argv[1:] if d.get(k)), ""), end="")' "$@"; }
names() { python3 -c 'import json,os; print(" ".join(sorted(k for k in json.loads(os.environ.get("SECRETS_JSON") or "{}") if k != "github_token")))'; }

found=$(names)
echo "Secrets disponibles (noms seulement) : ${found:-aucun}"
host=$(pick APP_HOST SSH_HOST SERVER_HOST DEPLOY_HOST REMOTE_HOST HOST PROD_SSH_HOST PRODUCTION_SSH_HOST STAGING_SSH_HOST)
user=$(pick APP_USER SSH_USER SSH_USERNAME SERVER_USER DEPLOY_USER REMOTE_USER USERNAME PROD_SSH_USER PRODUCTION_SSH_USER STAGING_SSH_USER)
pass=$(pick APP_PASS SSH_PASS SSH_PASSWORD SERVER_PASSWORD DEPLOY_PASSWORD REMOTE_PASSWORD PASSWORD PROD_SSH_PASS PRODUCTION_SSH_PASS)
key=$(pick DEPLOY_SSH_KEY SSH_KEY SSH_PRIVATE_KEY SERVER_SSH_KEY PRIVATE_KEY PROD_SSH_KEY PRODUCTION_SSH_KEY STAGING_SSH_KEY)
port=$(pick APP_PORT SSH_PORT SERVER_PORT DEPLOY_PORT REMOTE_PORT PORT PROD_SSH_PORT PRODUCTION_SSH_PORT STAGING_SSH_PORT)
port=${port:-22}

if [ -z "$host" ] || [ -z "$user" ] || { [ -z "$pass" ] && [ -z "$key" ]; }; then
  echo "::warning::Aucun accès SSH au serveur dans les secrets de ce dépôt (hôte, utilisateur, mot de passe ou clé) : déploiement non effectué. Le propriétaire du dépôt doit les ajouter (Settings › Secrets and variables › Actions), voir docs/exploitation.md."
  echo "ready=false" >> "$GITHUB_OUTPUT"; exit 0
fi
for v in "$host" "$user"; do echo "::add-mask::$v"; done
opts="-q -o StrictHostKeyChecking=no -o ConnectTimeout=20 -o ServerAliveInterval=30 -p $port"
if [ -n "$key" ]; then
  install -d -m 700 ~/.ssh && printf '%s\n' "$key" > ~/.ssh/deploy_key && chmod 600 ~/.ssh/deploy_key
  echo "SSH=ssh $opts -i $HOME/.ssh/deploy_key $user@$host" >> "$GITHUB_ENV"
  echo "Accès SSH : clé"
else
  sudo apt-get update -qq >/dev/null && sudo apt-get install -y -qq sshpass >/dev/null
  { echo "SSHPASS<<__FIN__"; printf '%s\n' "$pass"; echo "__FIN__"; } >> "$GITHUB_ENV"
  echo "SSH=sshpass -e ssh $opts $user@$host" >> "$GITHUB_ENV"
  echo "Accès SSH : mot de passe"
fi
echo "ready=true" >> "$GITHUB_OUTPUT"
