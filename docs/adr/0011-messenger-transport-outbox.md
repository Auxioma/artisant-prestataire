# ADR-0011 — Symfony Messenger sur Redis + outbox transactionnelle

**Statut** : Proposé · **Date** : 2026-11-11

## Contexte
Le cadrage laisse le choix entre Redis et RabbitMQ. Redis est déjà requis (cache, rate limiter, sessions, verrous).

## Décision
- **Redis Streams** (transport `redis://` de Messenger) au MVP : un seul service à opérer. Groupes de consommateurs, ack explicite, `delete_after_ack`. Les données persistent (AOF `everysec`).
- **Outbox** : les événements d'intégration émis dans une transaction métier (QuoteSent, RequestBecameFull, ArtisanVerified, CreditsDebited…) sont insérés dans `shared.outbox_message` dans la **même transaction**. Un relais (worker) les publie sur Messenger et les marque comme envoyés. Garantie *at-least-once* : les handlers sont idempotents (clé `message_id`).
- Transport `failed` en **Doctrine** (consultable et rejouable en BO).
- Migration vers RabbitMQ possible sans toucher au code métier (seul le DSN change) si la volumétrie ou le besoin de routage l'exigent.

## Alternative écartée
RabbitMQ dès le MVP : un service de plus, sans besoin de routage avancé identifié.
