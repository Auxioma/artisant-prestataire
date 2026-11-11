# ADR-0002 — PHP 8.4 + Symfony 7.4 LTS

**Statut** : Proposé · **Date** : 2026-11-11

## Contexte
Le cadrage impose PHP 8.4 et Symfony 7.4 LTS, sous réserve de vérifier la dernière LTS stable au moment du `composer create-project`.
Calendrier Symfony : 7.4 LTS est sortie en novembre 2025 (maintenance jusqu'en novembre 2028, sécurité jusqu'en novembre 2029). 8.0 est sortie en novembre 2025 et 8.4 LTS est attendue en novembre 2027. **À la date du cadrage, 7.4 est donc la LTS stable en cours.**

## Décision
- `symfony/skeleton:7.4.*` avec `"extra.symfony.require": "7.4.*"`.
- PHP 8.4 : property hooks et visibilité asymétrique autorisés dans le domaine (VO immuables `public private(set)`), `readonly` classes pour les DTO et commandes.
- Vérification automatisée à la création : `symfony check:requirements` et `composer show symfony/framework-bundle`. La version exacte est consignée dans le premier commit.

## Conséquences
- Migration vers 8.4 LTS planifiable en 2028. On élimine les dépréciations au fil de l'eau (`SYMFONY_DEPRECATIONS_HELPER` strict en CI).
- Bundles à vérifier pour la compatibilité 7.4 : scheb/2fa-bundle 7.x, nelmio/security-bundle 3.x, stripe/stripe-php, league/flysystem-bundle 3.x, meilisearch/meilisearch-php, jsor/doctrine-postgis.
