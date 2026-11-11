# ADR-0001 — Monolithe modulaire, Clean Architecture, CQRS, Deptrac

**Statut** : Proposé · **Date** : 2026-11-11

## Contexte
La plateforme couvre 14 domaines fonctionnels (CDC P/A/C/PR/BO). Plusieurs règles exigent une **cohérence transactionnelle forte entre modules** : l'envoi d'un devis touche Quote, QuoteRequest et Billing (RG-101). L'équipe est réduite et le lancement est régional (Q27).

## Décision
- Un **monolithe modulaire** Symfony, avec un dossier par bounded context (`src/<Module>/{Domain,Application,Infrastructure,UI}`).
- **Domain en PHP pur.** Les ports sont déclarés en Application et implémentés en Infrastructure.
- **CQRS léger** : command bus transactionnel, query bus en lecture (DBAL/projections autorisés), event bus pour l'intégration inter-modules.
- **Deptrac** dans la CI, avec deux axes : les couches (Domain ← Application ← UI/Infra) et les modules (dépendances inter-modules uniquement via `Shared` ou les ports `Application\Port\Public*` déclarés).

## Conséquences
- (+) La transaction RG-101 reste locale (une seule base) et testable.
- (+) La frontière entre modules est vérifiée par la machine : on peut extraire un module plus tard.
- (−) Plus de boilerplate (DTO, mapping XML, ports). On l'accepte : c'est la règle de dépendance imposée.
- (−) Pas d'associations Doctrine entre modules : les références passent par l'identifiant (VO). Les jointures inter-modules se font dans les **queries** (SQL de lecture), jamais dans les agrégats.

## Alternatives écartées
- Micro-services : coût opérationnel trop élevé, et transactions distribuées (sagas) pour RG-101.
- Symfony « classique » (entités annotées, services fat) : il ne respecte pas l'exigence de domaine pur.
