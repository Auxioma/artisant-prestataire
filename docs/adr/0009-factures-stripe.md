# ADR-0009 — Factures : Stripe Invoicing personnalisé

**Statut** : Proposé (Q24 du CDC) · **Date** : 2026-11-11

## Décision
Les factures d'abonnement sont **générées par Stripe Invoicing** (logo, mentions légales de l'éditeur, préfixe de numérotation, pied de page TVA). L'application **réplique** les métadonnées (`billing.invoice` : numéro, montants HT/TVA/TTC, statut, `hosted_invoice_url`, `pdf_url`) à partir des webhooks `invoice.finalized` et `invoice.paid`. Elle archive aussi une copie du PDF dans le stockage privé (conservation 10 ans, §11) avec son empreinte.

- TVA : **Stripe Tax** recommandé (20 % France, autoliquidation UE avec n° VIES valide). Le n° de TVA est vérifié via VIES (client HTTP en liste blanche) et poussé dans `customer.tax_ids`. Paramètre `billing.tax_engine = stripe_tax|internal`.
- Avoirs : Stripe Credit Notes lors des remboursements (BO08, rôle finance).

## Conséquences
- (+) Conformité de la numérotation continue garantie par Stripe, moins de développement.
- (−) Dépendance à Stripe pour la mise en forme. Le PDF archivé localement sécurise la conservation légale.

## Alternative
Génération interne (Twig → PDF) à partir des événements Stripe : à retenir seulement si une mention légale manquait chez Stripe. Point ouvert P-03 dans `points-en-suspens.md`.
