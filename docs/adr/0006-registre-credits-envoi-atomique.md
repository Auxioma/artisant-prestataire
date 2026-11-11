# ADR-0006 — Registre de crédits append-only et envoi atomique d'un devis

**Statut** : Proposé · **Date** : 2026-11-11

## Contexte
RG-044, RG-100, RG-101, RG-103, RG-104, RG-107, RG-109, §4.2 (concurrence sur la dernière place), Q2–Q7.

## Décision
1. **Ledger** `billing.credit_ledger_entry` en **ajout seul** : `amount` (entier ≠ 0), `bucket` (`free|plan|extra`), `type` (`free_grant|period_grant|quote_debit|refund|manual|expiry`), `reference_type/id`, `admin_id`, `reason`, `created_at`.
   - Solde par bucket = `SUM(amount)`. Un *read model* `credit_balance` est mis à jour dans la même transaction pour l'affichage (jauge header). Il est recalculable et vérifié par une tâche quotidienne (écart = alerte).
   - **Protection en base** : trigger `BEFORE UPDATE OR DELETE` qui lève une exception, plus `REVOKE UPDATE, DELETE` pour le rôle applicatif.
   - **Unicité** : index unique partiel `(artisan_id) WHERE type = 'free_grant'` (RG-044 : une seule fois) ; unique `(type, reference_type, reference_id)` pour `quote_debit`, `refund` et `period_grant` (idempotence) ; `CHECK (type <> 'manual' OR (admin_id IS NOT NULL AND reason <> ''))`.
   - Expiration des crédits de période (`plans.rollover = non`) : écriture `expiry` du reliquat du bucket `plan` à la fin de période, déclenchée par le webhook de renouvellement ou par le Scheduler.
2. **Envoi atomique** (voir ARCHITECTURE §6.1) : une transaction ; `SELECT … FOR UPDATE` sur la demande puis sur `billing.credit_account` (ligne d'ancrage par artisan, toujours dans cet ordre) ; la policy de domaine vérifie (a)–(f) ; débit et changement d'état dans la même transaction.
   - Port `Billing\Application\Port\CreditAccount::debitForQuote(ArtisanId, QuoteId): BucketDebited`, implémenté par Billing, appelé par le handler Quote **dans la transaction du command bus**.
3. **Tests de concurrence** obligatoires : deux connexions PDO réelles, synchronisées par `pg_advisory_lock` de test, sur la dernière place → exactement 1 devis envoyé, 1 débit, l'autre brouillon intact. Même chose pour deux devis du même artisan au dernier crédit.

## Alternatives écartées
- Colonne `balance` mise à jour : elle viole RG-107, sans audit.
- Verrou optimiste seul : il exige des rejeux côté client, avec une UX dégradée sur la dernière place.
- Verrou applicatif Redis : il n'est pas transactionnel avec PostgreSQL.
