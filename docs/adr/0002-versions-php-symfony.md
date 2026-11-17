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

## Révision du 2026-11-17 — passage à Symfony 8.1

**Statut** : Accepté (demande du porteur, migration jugée urgente).

- Version : `"extra.symfony.require": "8.1.*"` et tous les composants Symfony en `8.1.*`. DoctrineBundle passe en 3.4. Les bundles tiers sont compatibles sans changement de version majeure : scheb/2fa 8.7, nelmio/security 3.9, flysystem-bundle 3.7, stripe-php 17.6, Symfony UX 3.7. PHP 8.4 inchangé.
- Préalable : zéro dépréciation en 7.4 (parenthèses autour de `??` dans Twig, macro à la racine, option `enable_native_lazy_objects` retirée, nouveau format flysystem).
- Recette framework-bundle 8.1 : `Kernel::getAllowedEnvs()` limite `APP_ENV` à `prod`, `dev`, `test`. Le fichier `AGENTS.md` de la recette n'est pas repris.
- Vérifications : suite PHPUnit complète (967 tests), PHPStan, Deptrac et php-cs-fixer sans erreur, aucune dépréciation au démarrage.

**Conséquences**
- (−) 8.1 n'est maintenue que jusqu'en janvier 2027 : prévoir 8.2 (sortie fin novembre 2026), puis 8.3, jusqu'à 8.4 LTS (novembre 2027). Chaque montée mineure suit la même procédure : zéro dépréciation, `composer update "symfony/*" -W`, comparaison des recettes, suite complète.
- (−) Les lots préparés en 7.4 doivent être rebasés : conflits attendus sur `composer.json`, `composer.lock` et `config/`. On garde la version 8.1 de ces fichiers et on réapplique uniquement les ajouts de dépendances du lot.
