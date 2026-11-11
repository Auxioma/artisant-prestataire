# ADR-0010 — Traitement des webhooks Stripe

**Statut** : Proposé · **Date** : 2026-11-11

## Décision
- Endpoint `POST /webhooks/stripe`, hors firewall applicatif, hors CSRF, **rate limité** et limité en taille (256 Ko). Il lit le corps brut et appelle `Webhook::constructEvent($payload, $sig, $secret, tolerance: 300)`. Une signature invalide renvoie 400 et un événement de sécurité.
- Idempotence : `INSERT INTO billing.processed_stripe_event (event_id, type, received_at, payload_hash) ON CONFLICT DO NOTHING`. Si l'événement existe déjà, on renvoie 200 sans retraitement.
- Le traitement est **asynchrone** (transport `stripe_webhooks`, retries 5× exponentiels, puis `failed` avec alerte ADM). On re-récupère l'objet via l'API Stripe (`retrieve`) pour éviter de traiter un état périmé (événements hors ordre).
- **Seuls les webhooks modifient** l'abonnement et les crédits. `success_url` n'affiche qu'un état « en cours de confirmation ».
- Événements traités : liste du CDC §5. Journal consultable en BO15 (sans données carte, payload tronqué).
- Toutes les requêtes Stripe mutantes portent une `Idempotency-Key` déterministe (`sha256(command_name + aggregate_id + intent)`).
- Rapprochement quotidien (Scheduler 03:00) : abonnements actifs, dernières factures et statut du Customer, comparés à la base. Les écarts vont dans `billing.reconciliation_issue` et dans le BO.
