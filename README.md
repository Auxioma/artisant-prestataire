# TrouveMoi.com — Artisans & Prestataires

Plateforme de mise en relation entre clients et artisans vérifiés. Stack : Symfony 8.1 · PHP 8.4 · PostgreSQL 16 + PostGIS · Redis · Meilisearch.
Architecture : monolithe modulaire en Clean Architecture et CQRS. Voir [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) et l'[index de la documentation](docs/README.md).

## Démarrer (Docker)

```bash
docker compose up -d --wait
docker compose exec php composer install
docker compose exec php bin/console doctrine:migrations:migrate -n
docker compose exec php bin/console app:settings:sync      # paramètres administrables par défaut (annexe G)
open http://localhost:8090/healthz
```

Services de développement : Mailpit http://localhost:8025 · Meilisearch http://localhost:7700 · S3 (SeaweedFS) http://localhost:8333 · PostgreSQL `127.0.0.1:5434`.
Les ports se changent via les variables `TM_HTTP_PORT`, `TM_DB_PORT`, `TM_REDIS_PORT`, `TM_MEILI_PORT`, `TM_MAILPIT_PORT` et `TM_S3_PORT`, à placer dans `.env.local` ou dans l'environnement. Les images sont multi-architecture (Apple Silicon compris).

## Installation sans Docker (serveur)

PostgreSQL 16 + PostGIS 3 requis. Avant la première migration, un superutilisateur crée les extensions
(le propriétaire de la base n'en a pas le droit) :

```bash
sudo -u postgres psql -d trouvemoi -f ops/postgres/extensions.sql
bin/console doctrine:migrations:migrate -n
```

Procédure complète de mise en production : [docs/exploitation.md](docs/exploitation.md).

## Référentiel géographique

```bash
docker compose exec php php -d memory_limit=512M bin/console app:geo:import    # API officielle (~13 s, idempotent)
docker compose exec php bin/console app:artisan:rebuild-coverage    # après un import géo : communes couvertes des artisans
docker compose exec php bin/console app:geo:seo-pages --min-population=5000  # pages villes SEO (BO07.1)
```

## Autocomplétion (Meilisearch)

```bash
docker compose exec php bin/console app:search:reindex   # reconstruit les index métiers + communes (~10 s, sans coupure)
curl 'http://localhost:8090/api/suggestions?type=metier&q=plombi'
curl 'http://localhost:8090/api/suggestions?type=lieu&q=st%20etienne'
```

À lancer après `app:catalog:import` et `app:geo:import` ; ensuite les modifications du référentiel des métiers sont indexées automatiquement par le worker. Si Meilisearch est indisponible, l'endpoint répond depuis PostgreSQL (en-tête `X-Search-Mode: fallback`). Après un changement de variables dans `compose.yaml`, recréer les conteneurs (`docker compose up -d`) : un simple redémarrage garde l'ancien environnement.

## Services tiers

Sirene (INSEE), géocodage BAN (Géoplateforme IGN), VIES, Turnstile et HIBP passent chacun par un client HTTP dédié à hôte fixe, interdit de réseau privé (anti-SSRF). En développement, Turnstile utilise les clés de test publiques de Cloudflare (toujours valides) ; Sirene demande une clé du portail des API de l'INSEE (`INSEE_SIRENE_API_KEY` dans `.env.local`, ou `secrets:set` en production), sans quoi le service est signalé indisponible.

## Documents légaux et inscription

```bash
docker compose exec php bin/console app:legal:publish cgu config/legal/cgu.html
docker compose exec php bin/console app:legal:publish privacy config/legal/politique-de-confidentialite.html
docker compose exec php bin/console app:legal:publish cgv_pro config/legal/cgv-professionnels.html   # inscription artisan (A03)
```

L'inscription (`/inscription/client`) reste indisponible (503) tant que les CGU et la politique de confidentialité n'ont pas de version publiée. Les textes de `config/legal/` sont provisoires et marqués « [À RÉDIGER PAR LE JURISTE] » (Q28). Republier crée une nouvelle version ; `--substantial` exigera une nouvelle acceptation (RG-002).

## Notifications (e-mails)

```bash
docker compose exec php bin/console app:notification:sync              # catalogue annexe F + modèles par défaut (à chaque déploiement)
docker compose exec php bin/console app:notification:sync --env=test    # base de test (fait aussi par la CI)
```

Contenus par défaut : `config/notification/defaults/<code>.{subject,preheader,html,txt,in_app}.twig` (Twig en bac à sable, variables en français comme `prenom` ou `lien`, fonction `bouton(lien, libellé)`). Un modèle modifié en back-office n'est plus remplacé. En dev, les e-mails arrivent dans Mailpit (http://localhost:8025) ; en production `MAILER_DSN=brevo+api://CLÉ@default` via `secrets:set`. Journal d'envoi : table `notification.delivery`.

## Stockage de fichiers

```bash
docker compose exec php bin/console app:storage:setup   # crée les buckets privés (SeaweedFS en dev)
```

Tout fichier passe par `FileUploader` (contrôle, ré-encodage, quarantaine), puis par l'analyse ClamAV dans le worker, avant d'être disponible. Les justificatifs sont chiffrés avec la clé maîtresse `STORAGE_KEKS`. La valeur de `.env` est une clé nulle de développement, refusée en production. Pour générer une vraie clé : `php -r 'echo "k1:".base64_encode(random_bytes(32));'`, puis `bin/console secrets:set STORAGE_KEKS` et `STORAGE_KEK_CURRENT=k1`. Pour une rotation, ajouter `;k2:…` et passer `STORAGE_KEK_CURRENT` à `k2` : les anciens fichiers restent lisibles.

## Back-office

```bash
docker compose exec php bin/console app:admin:invite moi@exemple.test "Prénom Nom"   # premier super-admin : lien d'activation affiché (et envoyé par e-mail)
```

Ouvrir le lien, choisir un mot de passe, scanner le QR code avec une application d'authentification et conserver les 10 codes de secours ; se connecter ensuite sur `/admin/connexion` (mot de passe puis code). Les autres administrateurs sont invités depuis **Équipe et rôles** (`/admin/equipe`). En production, `ADMIN_HOST` (ex. `admin.trouvemoi.com`) réserve le back-office à cet hôte ; `admin.ip_allowlist` peut le restreindre à des plages d'adresses.

## Qualité (identique à la CI)

```bash
docker compose exec php composer qa   # dans le conteneur : PostGIS requis (absent du PostgreSQL Homebrew)
composer qa          # PHP-CS-Fixer (dry-run) + PHPStan max + Deptrac (couches et modules) + PHPUnit
composer cs:fix      # corrige le style
APP_ENV=test bin/console doctrine:migrations:migrate -n   # base de test (suffixe _test)
```

## Règles du dépôt

- Le domaine (`src/*/Domain`) est du PHP pur : Deptrac fait échouer le build si cette règle est violée.
- Aucune valeur métier en dur (RG-014) : on la lit via `SettingsProvider`. Une règle PHPStan dédiée vérifie les policies et les handlers.
- Commits au format Conventional Commits, avec la référence CDC : `feat(quote): envoi atomique du devis (RG-101)`.
- Les secrets ne se commitent jamais : utiliser `bin/console secrets:set`. gitleaks tourne en CI.

## Référentiel des services (Catalog)

```bash
docker compose exec php bin/console app:catalog:import          # référentiel initial (config/catalog/referentiel.yaml), idempotent
docker compose exec php bin/console app:catalog:tree            # arborescence
docker compose exec php bin/console app:catalog:job plombier --new-slug=plombier-chauffagiste   # crée les 301
docker compose exec php bin/console app:catalog:job debouchage --deactivate [--replace-with=plombier]
```
