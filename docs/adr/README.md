# Architecture Decision Records

Format : contexte → décision → conséquences → alternatives écartées. Statuts possibles : *Proposé*, *Accepté*, *Remplacé par ADR-xxxx*.
Tous les ADR sont **Proposés** tant que l'Étape 0 n'est pas validée.

| # | Titre | Statut |
|---|---|---|
| [0001](0001-monolithe-modulaire-clean-architecture.md) | Monolithe modulaire, Clean Architecture, CQRS, Deptrac | Proposé |
| [0002](0002-versions-php-symfony.md) | PHP 8.4 + Symfony 8.1 (révisé le 2026-11-17, cap 8.4 LTS) | Accepté |
| [0003](0003-persistance-doctrine-xml-uuid-v7.md) | Doctrine ORM mapping XML, UUID v7, schémas par module | Proposé |
| [0004](0004-postgis-vs-meilisearch.md) | PostGIS pour la géo, Meilisearch pour le texte | Proposé |
| [0005](0005-machines-a-etats.md) | Machines à états : enums dans les agrégats plutôt que Symfony Workflow | Proposé |
| [0006](0006-registre-credits-envoi-atomique.md) | Registre de crédits append-only et envoi atomique | Proposé |
| [0007](0007-parametres-administrables.md) | Paramètres administrables historisés (RG-014) | Proposé |
| [0008](0008-chiffrement-documents.md) | Stockage et chiffrement des documents | Proposé |
| [0009](0009-factures-stripe.md) | Factures Stripe Invoicing vs facturation interne | Proposé |
| [0010](0010-webhooks-stripe.md) | Traitement des webhooks Stripe | Proposé |
| [0011](0011-messenger-transport-outbox.md) | Transport Messenger Redis + outbox | Proposé |
| [0012](0012-front-twig-symfony-ux.md) | Front Twig SSR + Symfony UX + tokens CSS | Proposé |
| [0013](0013-back-office-roles-permissions.md) | Back-office isolé, 2FA, permissions fines | Proposé |
| [0014](0014-masquage-coordonnees.md) | Détection et masquage des coordonnées | Proposé |
| [0015](0015-pdf-devis-dompdf.md) | PDF des devis : gabarit Twig rendu par dompdf | Proposé |
