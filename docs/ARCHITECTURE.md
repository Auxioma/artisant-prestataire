# TrouveMoi.com — Architecture logicielle

> Statut : **proposition — en attente de validation (Étape 0)**
> Sources : CDC fonctionnel v1.0 (`docs/cdc/TrouveMoi_Cahier_des_charges_fonctionnel.pdf`), maquettes Figma V2 (`gFu3z6vBrvV1BEexiWib0K`), document de cadrage.
> Règle d'arbitrage : **CDC > Figma** pour le métier ; **Figma audit V2.1 > CDC** pour les corrections d'accessibilité déjà appliquées.

---

## 1. Vue d'ensemble

TrouveMoi est un **monolithe modulaire** Symfony. Il est découpé en *bounded contexts* (modules) qui respectent la Clean Architecture. Chaque module est indépendant au niveau du code. Tous partagent une base PostgreSQL, avec un schéma PostgreSQL par module.

On écarte les micro-services : le volume du MVP (lancement en Normandie) ne les justifie pas, et ils compliqueraient la transaction atomique d'envoi de devis (RG-101). Si un module doit un jour être extrait, la séparation stricte des modules le permet sans refonte (voir ADR-0001).

```
                    ┌──────────────────────────── Navigateur ─────────────────────────────┐
                    │ Pages Twig SSR + Turbo/Stimulus/Live Components · Stripe Checkout   │
                    └───────────────┬───────────────────────────────┬─────────────────────┘
                                    │ HTTPS (CDN, HSTS, CSP nonce)  │
              www.trouvemoi.com     │                               │   admin.trouvemoi.com (2FA, IP allowlist opt.)
                    ┌───────────────▼───────────────────────────────▼─────────────────────┐
                    │                 FrankenPHP / php-fpm — Symfony 7.4 LTS               │
                    │  UI (contrôleurs, formulaires, voters, composants Twig, webhooks)    │
                    │  Application (commands / queries / handlers, ports)                  │
                    │  Domain (agrégats, VO, règles RG, événements)                        │
                    │  Infrastructure (Doctrine XML, adapters Stripe/Sirene/S3/ClamAV…)    │
                    └──┬─────────┬──────────┬───────────┬───────────┬───────────┬──────────┘
                       │         │          │           │           │           │
               PostgreSQL 16  Redis     Meilisearch   S3 privé    ClamAV     Services externes
               + PostGIS     (Messenger, (autocompl.,  (MinIO en   (scan      Stripe · Brevo ·
               (source de    cache,      plein texte)  dev)        async)     API Sirene · VIES ·
               vérité)       rate limit,                                      Turnstile · HIBP ·
                             sessions)                                        géocodage BAN
                       ▲
                       │ workers Messenger (async, failed, stripe_webhooks, mail, scan, index)
                       │ Symfony Scheduler (expirations, alertes J-30/J-7/J0, purges RGPD, rapprochement Stripe)
```

## 2. Couches et règle de dépendance

```
 UI ─────────► Application ─────────► Domain
 Infrastructure ──► Application, Domain   (implémente les ports)
 Shared ◄── tous (Kernel : VO, Clock, bus, exceptions)
```

| Couche | Contenu | Peut dépendre de | Interdit |
|---|---|---|---|
| **Domain** | Agrégats, entités, Value Objects, événements de domaine, interfaces de repository, policies (règles RG), exceptions | `Shared\Domain` uniquement | Symfony, Doctrine, Stripe, HTTP, `DateTime` direct (passer par `Clock`) |
| **Application** | Commands, Queries, Handlers, DTO, **ports** (`PaymentGateway`, `CompanyRegistry`, `FileStorage`, `AntivirusScanner`, `Mailer`, `SettingsProvider`, `TransactionManager`…) | Domain, `Shared\Application` | Contrôleurs, Doctrine, SDK tiers |
| **Infrastructure** | Repositories Doctrine + mapping XML, adapters (StripeGateway, SireneClient, FlysystemStorage, ClamAvScanner, BrevoMailer), handlers Messenger de transport, tâches Scheduler | Application, Domain, bibliothèques | UI |
| **UI** | Contrôleurs Web/Admin/Webhook, FormTypes, TwigComponents, **Voters**, commandes console | Application (dispatch), Domain (lecture d'énums/VO) | Infrastructure (sauf via DI), logique métier |

**Inter-modules** : un module ne référence jamais le *Domain* d'un autre module. Il passe par :
1. les **identifiants** (`ArtisanId`, `RequestId` : Value Objects de `Shared`) ;
2. les **événements d'intégration** publiés sur l'event bus (par ex. `ArtisanVerified` → Billing attribue le crédit gratuit) ;
3. des **ports applicatifs** exposés par le module fournisseur (par ex. `Billing\Application\Port\CreditAccount` appelé par `Quote` lors de l'envoi). C'est la seule dépendance synchrone autorisée, et elle doit être déclarée dans Deptrac.

**Contrôle automatique** : `deptrac.yaml` définit des *layers* (`*\Domain`, `*\Application`, `*\Infrastructure`, `*\UI`) et des *collectors* par module. La CI échoue en cas de violation (ADR-0001).

## 3. Modules (bounded contexts)

| Module | Responsabilité | Références CDC | Dépend (ports) de |
|---|---|---|---|
| `Shared` | Kernel : `Uuid` (v7), `Email`, `Siret`, `Money`, `InseeCode`, `GeoPoint`, `PhoneNumber`, `Clock`, interfaces `CommandBus`/`QueryBus`/`EventBus`, `DomainException` | — | — |
| `Identity` | Comptes, authentification, sessions, 2FA, consentements (CMP), acceptations légales versionnées, RGPD self-service | A01–A07, C07, RG-002, RG-040, RG-045, RG-090, §11 | Notification |
| `Catalog` | Catégories / sous-catégories / métiers, synonymes, questionnaires, prix indicatifs, référentiel INSEE (communes, départements, régions) | BO06, BO07, P03–P05 | — |
| `ArtisanProfile` | Profil pro, SIRET/Sirene, zone d'intervention, justificatifs chiffrés, vérification, portfolio, agenda, offres | A03, PR06, PR08, PR10, PR14, BO03, BO09, RG-026, RG-030–032, RG-041–044, RG-130 | Catalog, Notification |
| `Search` | Recherche géo + plein texte, classement bayésien, pertinence pondérée, pages SEO métier × ville, « mieux notés », « disponibilités » | P01–P08, RG-010–013, RG-020–025 | Catalog, ArtisanProfile (read models) |
| `QuoteRequest` | Dépôt multi-étapes, brouillons, pièces jointes, score de risque, diffusion, cycle de vie | P11, C02, PR02, PR03, RG-060–066, RG-070, RG-111 | Catalog, ArtisanProfile, Moderation |
| `Quote` | Éditeur, versions, mentions obligatoires, PDF, envoi atomique, décision, RDV | PR04, PR05, C03, RG-071–073, RG-100–103, RG-120–121 | QuoteRequest, Billing (`CreditAccount`), ArtisanProfile |
| `Billing` | Registre de crédits (ledger), forfaits, abonnements, factures, codes promo, webhooks Stripe, rapprochement | §4, §5, PR11–PR13, BO08, RG-044, RG-104–109 | Notification |
| `Messaging` | Conversations, masquage des coordonnées, pièces jointes, signalements | C04, PR07, RG-080, RG-081 | QuoteRequest, Quote |
| `Review` | Avis certifiés, réponses, score de risque des avis | C06, PR09, RG-033–036 | Quote, Moderation |
| `Moderation` | Files de modération, signalements, règles automatiques, décisions tracées | BO05, RG-031, RG-035, RG-063 | — |
| `Content` | CMS (accueil 10 blocs, pages, blog, FAQ, menus, médiathèque, libellés), SEO (meta, redirections, sitemaps, robots) | P01, P12–P18, BO10, BO12, §7 | Catalog |
| `Notification` | Emails, in-app, SMS (V2), modèles, digests, anti-saturation, journal d'envoi | §6, annexe F, BO11, C08 | — |
| `Administration` | Rôles admin et permissions, paramètres (annexe G) historisés, AuditLog, exports, KPI, support | BO01, BO13–BO16, annexe E, annexe G | tous (lecture) |

## 4. Structure de dossiers

```
src/
  Shared/{Domain,Application,Infrastructure,UI}/
  Identity/
    Domain/          User, UserStatus, Credentials, LegalAcceptance, Consent, events…
    Application/     Command/RegisterClient, Command/ResetPassword, Query/…, Port/PasswordBreachChecker…
    Infrastructure/  Doctrine/Mapping/*.orm.xml, Doctrine/Repository/*, Security/UserProvider, Hibp/…
    UI/              Web/Controller/*, Form/*, Security/Voter/*, Twig/Component/*
  Catalog/ … ArtisanProfile/ … (même découpage)
config/
  packages/ · routes/ · doctrine/ (inclusion des mappings XML par module)
templates/
  components/ (Button, Input, Select, Checkbox, Badge, Toast, Modal, CardArtisan, Stepper, CreditGauge, Skeleton, EmptyState)
  layouts/ · public/ · auth/ · client/ · pro/ · admin/ · email/
assets/
  styles/tokens.css (variables CDC §2.3 + tokens Figma + audit V2.1) · controllers/ (Stimulus) · icons/sprite.svg (Lucide)
migrations/
tests/{Unit,Integration,Functional,E2E}/<Module>/
docs/{adr,cdc,decisions}/ · ARCHITECTURE.md · SECURITY.md · data-model.md · traceability.md
docker/ · compose.yaml · deptrac.yaml · phpstan.dist.neon · .php-cs-fixer.dist.php
```

## 5. CQRS et bus

- **Command bus** (synchrone par défaut, middleware `doctrine_transaction`) : un handler par cas d'usage. Il retourne au plus un identifiant, jamais une entité.
- **Query bus** (synchrone, sans transaction d'écriture) : renvoie des **read models / DTO**. Il peut lire en DBAL/SQL (projections, PostGIS) sans charger les agrégats.
- **Event bus** : les événements de domaine sont collectés par l'agrégat (`releaseEvents()`). Ils sont publiés **après commit** via le middleware `dispatch_after_current_bus`. Les listeners inter-modules sont routés en **asynchrone**.
- **Transports Messenger** : `async` (défaut), `async_priority` (emails transactionnels critiques : reset, validation), `stripe_webhooks`, `file_scan`, `search_index`, `failed`. Retry exponentiel (3 essais, ×2, max 1 h) puis transport `failed`, consultable en BO.
- **Outbox** : les événements d'intégration critiques (Billing, Quote) sont écrits dans la table `outbox_message` **dans la même transaction**, puis relayés par un worker. On évite ainsi la perte d'un événement si Redis est indisponible au moment du commit (ADR-0011).

## 6. Flux clés

### 6.1 Envoi atomique d'un devis (RG-100, RG-101, RG-104, RG-107)

```
PR04 ─ POST /espace-pro/devis/{quoteId}/envoyer (CSRF, Voter QUOTE_SEND)
  └─ SendQuoteCommand ─► SendQuoteHandler  [1 transaction, isolation READ COMMITTED]
       1. SELECT … FROM quote_request WHERE id = :rid FOR UPDATE          (verrou demande)
       2. SELECT … FROM billing.credit_account WHERE artisan_id = :aid FOR UPDATE (verrou compte crédits)
       3. Domain : QuoteSendingPolicy::assertCanSend(request, artisan, quote, balance)
            (a) request.status = open          (b) activeQuotes < request.maxQuotes (figé)
            (c) artisan.status = verified       (d) aucun devis actif de cet artisan sur la demande
            (e) RG-120 mentions obligatoires    (f) crédit disponible (buckets free → plan → extra)
       4. Quote::send() → statut « envoyé », version 1, pdf_hash
       5. CreditLedger::append(quote_debit, −1, bucket choisi, ref = quote.id)  [unique (type, reference_id)]
       6. Request::registerQuote() → si activeQuotes = maxQuotes : statut « complète » + event RequestBecameFull
       7. COMMIT → events (QuoteSent → E-12 ; RequestBecameFull → E-33 / E-19b ; seuils → E-42)
  Échec (a)/(b)/(d) → QuoteSendingRefused, rollback : aucun débit, brouillon conservé, message RG-101
  Échec (f)         → redirection forfaits, brouillon conservé
```
Ordre des verrous fixe (demande puis compte crédits) pour éviter les interblocages. Le test d'intégration de concurrence lance deux processus/connexions sur la dernière place et doit obtenir 1 succès, 1 refus, 1 seul débit.

### 6.2 Abonnement Stripe (§4.3, §5)

```
PR12 → CreateCheckoutSessionCommand (prix lu en base, Idempotency-Key) → redirection Stripe Checkout
Stripe → POST /webhooks/stripe (pas de CSRF, signature obligatoire)
  └─ vérification Webhook::constructEvent → INSERT processed_stripe_event(event_id) ON CONFLICT DO NOTHING
       → 200 immédiat → message StripeEventReceived (transport stripe_webhooks)
            └─ handler : invoice.paid → Subscription::activate/renew → CreditLedger period_grant (+ expiry de la période précédente si rollover = non)
success_url : affiche « paiement en cours de confirmation » (polling Turbo), ne modifie AUCUN état.
Scheduler quotidien : StripeReconciliation (abonnements, factures) → écarts en BO.
```

### 6.3 Vérification artisan (A03, BO03, RG-041–044, RG-130)

Inscription → Sirene (SIRET valide, actif, unique) → justificatifs (upload → quarantaine → scan ClamAV → chiffrement → stockage privé) → statut `pending_verification` → décision d'un modérateur (AuditLog) → `ArtisanVerified` → Billing : `free_grant` (unicité en base) → E-21.

### 6.4 Diffusion d'une demande (RG-062, RG-063)

`RequestSubmitted` → `RiskScorer` (mots-clés, liens, coordonnées, doublons, vélocité, IP/appareil) → si score ≥ `moderation.request_risk_threshold` alors `pending_moderation`, sinon `open` → `DispatchRequest` : requête PostGIS (métier ∈ métiers de l'artisan ∧ commune ∈ zone ∧ vérifié ∧ actif ∧ non en pause ∧ non bloqué) → `request_dispatch` + notifications E-30 (immédiat ou digest).

## 7. Choix techniques (résumé, détails dans `docs/adr/`)

| Sujet | Choix | ADR |
|---|---|---|
| Style d'architecture | Monolithe modulaire, Clean Architecture, CQRS, Deptrac | 0001 |
| Versions | PHP 8.4, Symfony 7.4 LTS (support jusqu'en nov. 2028 / sécurité nov. 2029) | 0002 |
| Persistance | Doctrine ORM, mapping XML, UUID v7, un schéma PostgreSQL par module | 0003 |
| Recherche géographique / plein texte | PostGIS = source de vérité géo (RG-021) ; Meilisearch = autocomplétion et plein texte (RG-010) | 0004 |
| Machines à états | Enums + transitions dans les agrégats ; Symfony Workflow non utilisé dans le domaine | 0005 |
| Crédits | Ledger append-only + verrou pessimiste + contraintes d'unicité | 0006 |
| Paramètres | `SettingsProvider` historisé, typé, mis en cache, aucune valeur métier en dur | 0007 |
| Documents | Stockage S3 privé, chiffrement enveloppe libsodium, URLs signées ≤ 5 min | 0008 |
| Factures | Stripe Invoicing personnalisé (Q24) | 0009 |
| Webhooks Stripe | Signature, idempotence, async, rapprochement | 0010 |
| Messagerie asynchrone | Messenger + Redis (Streams) + outbox | 0011 |
| Front | Twig SSR, Symfony UX, AssetMapper, tokens CSS, TwigComponents | 0012 |
| Back-office | Firewall + hôte séparés, 2FA TOTP, permissions fines (presets de rôles + rôles personnalisés) | 0013 |
| Masquage des coordonnées | Détection côté domaine, original chiffré pour la modération | 0014 |

## 8. Environnements et exploitation

- **Dev** : Docker Compose avec `frankenphp` (ou php-fpm + caddy), `postgres:16` + PostGIS 3.4, `redis:7`, `getmeili/meilisearch`, `clamav/clamav`, `axllent/mailpit`, et **SeaweedFS** comme S3 privé de développement (les images MinIO ne sont plus publiées sur Docker Hub, voir P-21), plus des conteneurs dédiés pour le worker Messenger, le relais d'outbox et le Scheduler.
- **CI** (GitHub Actions) : `composer validate`, `composer audit`, PHP-CS-Fixer (dry-run), PHPStan niveau max (+ `phpstan-symfony`, `phpstan-doctrine`), Deptrac, PHPUnit (Unit, Integration avec services Postgres/Redis), tests fonctionnels, gitleaks, lint Twig/YAML/conteneur, `doctrine:schema:validate`.
- **Prod** (hébergeur UE à choisir, voir points en suspens) : secrets via `secrets:set` (coffre Symfony, clé de déchiffrement hors dépôt), CDN pour les assets et médias publics, sauvegardes PostgreSQL chiffrées quotidiennes (rétention 30 j) avec test de restauration mensuel, supervision (Sentry auto-hébergé ou UE, sans PII), disponibilité cible 99,5 %.
- **Observabilité** : Monolog JSON avec processeur de masquage des PII (`PiiMaskingProcessor` : emails, téléphones, IBAN, tokens) ; `request_id` corrélé ; métriques Messenger (profondeur des files, échecs).

## 9. Qualité et Definition of Done

Voir cadrage §7. Chaque module livré doit fournir : des tests unitaires du domaine (une méthode de test par règle RG citée), des tests d'intégration des repositories et adapters, des tests fonctionnels des contrôleurs (dont les tests IDOR « A ne peut pas accéder à B »), des migrations réversibles, des fixtures marquées `[DEMO]`, la mise à jour de `docs/traceability.md` et la liste des contrôles ASVS couverts (voir `docs/SECURITY.md`).
