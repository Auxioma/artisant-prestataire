# ADR-0005 — Machines à états : enums + transitions dans les agrégats

**Statut** : Proposé · **Date** : 2026-11-11

## Contexte
Cycles à modéliser : compte pro (RG-043), demande (RG-066), devis (RG-121), abonnement (§4.3), RDV (C03.6), avis (RG-035), justificatif (BO03), document légal (RG-002).

## Décision
- Chaque statut est un **`enum` PHP backed** dans le Domain, avec une méthode `canTransitionTo(self $to): bool` et une table de transitions déclarative.
- Les transitions passent **uniquement** par des méthodes métier de l'agrégat (`Quote::accept()`, `Request::markFull()`…). Elles vérifient les gardes (RG) et émettent un événement de domaine. Il n'y a pas de setter de statut.
- Le diagramme de chaque machine est généré depuis le code (commande `app:state-machines:dump` → Mermaid dans `docs/`) pour rester synchronisé.
- **Symfony Workflow n'est pas utilisé dans le domaine**, car il dépend du framework. Il pourra servir plus tard pour des circuits purement BO (CMS : brouillon → programmé → publié), côté Infrastructure/UI.

## Conséquences
- (+) Domaine pur et testable unitairement, gardes métier au même endroit que l'état.
- (−) Pas de visualisation automatique de Workflow. On la compense par le dump Mermaid.
