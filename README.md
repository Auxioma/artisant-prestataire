# TrouveMoi.com — Artisans & Prestataires

Plateforme de mise en relation entre clients et artisans vérifiés. Stack : Symfony 7.4 LTS · PHP 8.4 · PostgreSQL 16 + PostGIS · Redis · Meilisearch.
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

## Qualité (identique à la CI)

```bash
composer qa          # PHP-CS-Fixer (dry-run) + PHPStan max + Deptrac (couches et modules) + PHPUnit
composer cs:fix      # corrige le style
APP_ENV=test bin/console doctrine:migrations:migrate -n   # base de test (suffixe _test)
```

## Règles du dépôt

- Le domaine (`src/*/Domain`) est du PHP pur : Deptrac fait échouer le build si cette règle est violée.
- Aucune valeur métier en dur (RG-014) : on la lit via `SettingsProvider`. Une règle PHPStan dédiée vérifie les policies et les handlers.
- Commits au format Conventional Commits, avec la référence CDC : `feat(quote): envoi atomique du devis (RG-101)`.
- Les secrets ne se commitent jamais : utiliser `bin/console secrets:set`. gitleaks tourne en CI.
