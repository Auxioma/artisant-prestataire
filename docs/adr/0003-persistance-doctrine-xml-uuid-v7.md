# ADR-0003 — Doctrine ORM, mapping XML, UUID v7, un schéma PostgreSQL par module

**Statut** : Proposé · **Date** : 2026-11-11

## Décision
- **Mapping XML** dans `src/<Module>/Infrastructure/Doctrine/Mapping/*.orm.xml`. Aucun attribut Doctrine dans le domaine.
- Les Value Objects sont des **embeddables** (`Email`, `Money`, `Siret`) ou des **types Doctrine personnalisés** (`ArtisanId`, `GeoPoint` → `geography(Point,4326)`) déclarés en Infrastructure.
- **Identifiants UUID v7** générés côté domaine (`Uuid::v7()` via un port `IdGenerator`), stockés en `uuid`. Ils sont triables dans le temps et non énumérables. Aucun identifiant séquentiel n'est exposé. Les numéros métier lisibles (`DEM-2026-000123`, `DEV-…`, factures) passent par des séquences dédiées et ne sont jamais utilisés comme clé d'accès.
- **Un schéma PostgreSQL par module** (`identity`, `catalog`, `artisan`, `search`, `request`, `quote`, `billing`, `messaging`, `review`, `moderation`, `content`, `notification`, `admin`). Les clés étrangères inter-schémas sont conservées pour l'intégrité. Doctrine n'a pas d'association entre modules.
- **Migrations** Doctrine réversibles (`down()` obligatoire et testé en CI : `migrate` → `migrate prev` → `migrate`).
- Verrouillage : `LockMode::PESSIMISTIC_WRITE` (RG-101) et `version` (verrou optimiste) sur les agrégats édités en BO (forfaits, paramètres, contenus).

## Alternatives écartées
- Attributs PHP : interdits dans le domaine par le cadrage.
- ULID : équivalent, mais UUID v7 est natif dans PostgreSQL 16 (type `uuid`) et dans symfony/uid.
