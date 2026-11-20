# SECURITY.md — Modèle de menaces et conformité ASVS

> Statut : **proposition — Étape 0**. Référentiel : **OWASP ASVS 4.0.3 niveau 2**, CDC §10 (sécurité), §11 (RGPD), §12 (anti-fraude), cadrage §5.
> Signalement de vulnérabilité : `security@trouvemoi.com` (à créer). Réponse sous 72 h, publication `/.well-known/security.txt`.

---

## 1. Actifs et surfaces d'attaque

| Actif | Sensibilité | Où |
|---|---|---|
| Comptes (identifiants, sessions, 2FA admin) | Critique | Identity, Administration |
| Coordonnées clients (adresse, téléphone) avant acceptation | Haute — valeur commerciale (contournement de la plateforme) | QuoteRequest, Messaging |
| Justificatifs artisans (Kbis, assurance, **pièce d'identité**) | Critique (usurpation d'identité) | ArtisanProfile |
| Crédits et abonnements | Haute (financier) | Billing |
| Clés Stripe, Brevo, KEK de chiffrement | Critique | coffre de secrets |
| KEK de chiffrement des fichiers (`STORAGE_KEKS`) | Critique | coffre de secrets uniquement ; versionnées (`kek_id` stocké avec chaque fichier), rotation par ajout d'une KEK courante ; la clé nulle de développement est refusée en production |
| Clés Sirene (INSEE) et Turnstile (secrète) | Élevée | coffre de secrets (`INSEE_SIRENE_API_KEY`, `TURNSTILE_SECRET_KEY`) ; le dépôt ne contient que les clés de test publiques de Cloudflare |
| Clés Meilisearch (recherche / administration) | Élevée | coffre de secrets ; clé de recherche limitée à l'action `search` sur les index `tm_*`, jamais exposée au navigateur (le front passe par `/api/suggestions`) ; clé d'administration réservée au worker et à `app:search:reindex` ; clé maître hors de l'application |
| Avis certifiés, classement | Haute (réputation, obligations L111-7) | Review, Search |
| Back-office | Critique (accès global) | Administration |

**Points d'entrée** : pages publiques et formulaires (www), API internes Turbo/Live, endpoint webhook Stripe, uploads, back-office (admin.), workers Messenger (messages sérialisés), Scheduler, appels sortants (Sirene, VIES, BAN, HIBP, Turnstile, Stripe, Brevo).

**Limites de confiance** : navigateur ↔ app, app ↔ Stripe (webhooks entrants), app ↔ stockage S3, app ↔ ClamAV, utilisateur A ↔ utilisateur B (même rôle), front ↔ back-office.

## 2. STRIDE par module

S = usurpation · T = altération · R = répudiation · I = divulgation · D = déni de service · E = élévation de privilèges

### Identity (A01–A07, RG-040, RG-045)
| | Menace | Contrôles |
|---|---|---|
| S | Credential stuffing, force brute | `login_throttling` 5/15 min par compte ET par IP (`auth.login_max_attempts`), délai progressif, Turnstile après échecs, HIBP `NotCompromisedPassword`, Argon2id, alerte nouvel appareil E-05 |
| S | Prise de contrôle via reset | token aléatoire 256 bits **haché** en base, usage unique, TTL 1 h, 3/h, invalidation de toutes les sessions + remember-me après reset, E-04 |
| S | Fixation de session | `session.migrate()` à la connexion, cookies `__Host-` `Secure; HttpOnly; SameSite=Lax` |
| S | Account takeover via connexion sociale (V2) | liaison seulement après saisie du mot de passe du compte existant |
| I | Énumération de comptes | message unique « Email ou mot de passe incorrect », réponses identiques reset/inscription/newsletter, temps de réponse constant (hash factice) |
| T | Open redirect post-login | `redirect` validé contre une liste blanche de **noms de routes** internes |
| R | Contestation d'une acceptation CGU | `legal_acceptance` append-only (version + hash du document + IP hachée) — RG-002 |
| D | Spam d'inscriptions | rate limit + Turnstile + blocage des emails jetables (`antifraud.block_disposable_emails`) |

### ArtisanProfile (A03, BO03, RG-041–044, RG-130)
| | Menace | Contrôles |
|---|---|---|
| S | Faux artisan / SIRET d'un tiers | API Sirene (état actif, raison sociale), unicité SIRET, contrôle humain, liste noire SIRET/téléphone/IBAN, cohérence nom ↔ SIRET |
| E | Multi-comptes pour cumuler le crédit gratuit | crédit à la **vérification** (RG-044), empreinte appareil + IP hachées, UQ `free_grant`, détection de doublons (téléphone, IBAN, appareil) dans la file BO03 |
| I | Fuite de justificatifs | stockage privé, chiffrement enveloppe (ADR-0008), Voter + rôle habilité, **journalisation de chaque consultation**, URLs ≤ 5 min, suppression de la pièce d'identité après décision |
| T | Fichier malveillant (polyglotte, PDF JS, malware) | magic bytes, ré-encodage, rejet des PDF actifs, ClamAV en quarantaine, `Content-Disposition: attachment` |
| I | Exposition de l'adresse exacte | centroïde de commune uniquement (RG-022) ; adresse du siège chiffrée |
| T | XSS stockée dans la description du profil | html-sanitizer liste blanche, pas de `|raw` sans sanitizer, CSP nonce |

### Search / Content (P01–P10, P12–P18, BO10, BO12)
| | Menace | Contrôles |
|---|---|---|
| D | Scraping / épuisement (autocomplétion, recherche) | Rate Limiter par IP + empreinte, cache HTTP, pagination plafonnée, `search.page_size` |
| T | Injection SQL (PostGIS) / filtres Meilisearch | requêtes paramétrées uniquement, construction des filtres Meilisearch par liste blanche d'attributs, aucune concaténation |
| T | Manipulation du classement | note bayésienne (RG-012), avis certifiés uniquement, critères exposés (L111-7) |
| T | XSS via CMS / blog | sanitizer côté écriture **et** échappement Twig à l'affichage ; éditeurs CMS limités au rôle Contenu |
| I | Fiche d'un artisan suspendu indexée | 404/410 (P10), sitemaps générés depuis les statuts |

### QuoteRequest (P11, RG-060–066)
| | Menace | Contrôles |
|---|---|---|
| S | Demandes frauduleuses / spam | Turnstile, email vérifié (RG-040), score de risque RG-063 → modération, anti-doublon 72 h, plafond de demandes ouvertes (RG-065) |
| I | IDOR sur une demande | `RequestVoter` : propriétaire client ; artisan seulement s'il est dans `request_dispatch` et vérifié (ou aperçu Q15 sans coordonnées) ; UUID v7 |
| I | Fuite des coordonnées client | identité masquée (prénom + commune, RG-112), adresse révélée après acceptation (RG-022bis) via une query dédiée |
| T | Altération de `max_quotes` | figé dans l'agrégat, jamais lu depuis l'entrée utilisateur |

### Quote (PR04, C03, RG-100–103, RG-120–121)
| | Menace | Contrôles |
|---|---|---|
| T | Condition de course sur la dernière place / double débit | transaction + `FOR UPDATE` + UQ (ADR-0006), tests de concurrence |
| E | Envoi sans crédit / sans vérification | policy de domaine (a)–(f), Voter `QUOTE_SEND` |
| T | Altération d'un PDF de devis envoyé | versions immuables, `pdf_sha256` vérifié au téléchargement |
| I | IDOR sur un devis | `QuoteVoter` : artisan auteur ou client propriétaire de la demande ; pas d'accès des autres artisans |
| R | Contestation d'une acceptation | événement horodaté, AuditLog, IP hachée, version acceptée figée (RG-071) |

### Billing (§4, §5, PR11–PR13, BO08)
| | Menace | Contrôles |
|---|---|---|
| S | Faux webhook | signature `constructEvent` (tolérance 300 s), secret dans le coffre, endpoint hors CSRF uniquement |
| T | Rejeu de webhook / traitement en double | `processed_stripe_event` PK, handlers idempotents, re-fetch de l'objet Stripe |
| T | Manipulation du prix côté client | prix et Price ID lus en base, aucun montant reçu du navigateur ; `Idempotency-Key` |
| E | Activation via `success_url` | interdit : seuls les webhooks changent l'état |
| T | Altération du solde | ledger append-only (trigger + REVOKE), écritures manuelles motivées et plafonnées par rôle, AuditLog |
| I | Données carte | Stripe Checkout / Elements → PCI SAQ A ; aucune donnée carte en base ni dans les logs |
| R | Geste commercial contesté | `admin_id` + `reason` obligatoires (CHECK SQL), ré-authentification |

### Messaging (C04, PR07, RG-080, RG-081)
| | Menace | Contrôles |
|---|---|---|
| I | Contournement (échange de coordonnées) | détection et masquage (ADR-0014), original chiffré pour la modération |
| S | Démarchage par un artisan | RG-081 : création de conversation seulement sur une demande accessible ; Voter `CONVERSATION_START` |
| I | IDOR sur une conversation | `ConversationVoter` (participants uniquement) ; lecture admin motivée et tracée |
| D | Flood | rate limit par utilisateur et par conversation ; email regroupé 1/fil/15 min |
| T | ReDoS sur les motifs administrables | regex bornées, `pcre.backtrack_limit`, validation à l'enregistrement |

### Review (C06, PR09, RG-033–036)
| | Menace | Contrôles |
|---|---|---|
| S | Faux avis / auto-avis | réservé au client dont le devis a été accepté (RG-033), UQ par demande, score de risque (lien IP/appareil client ↔ artisan, rafales, similarité de texte), modération a priori (`reviews.moderation_mode`) |
| T | Modification abusive | fenêtre `reviews.edit_days`, historique |
| R | Contestation de la modération | motifs normalisés, `moderation.decision` append-only, email à l'auteur (RG-035) |

### Administration (BO01–BO16)
| | Menace | Contrôles |
|---|---|---|
| S | Vol de compte admin | pare-feu et contexte de sécurité séparés, hôte dédié (`ADMIN_HOST`), 2FA TOTP obligatoire (aucun compte actif sans 2FA, secret chiffré, anti-rejeu, codes de secours HMAC à usage unique), activation par invitation à lien unique haché, limitation des tentatives, liste blanche IP optionnelle, timeout d'inactivité, ré-authentification pour les actions sensibles, journalisation des connexions et échecs (T-209) |
| E | Escalade de rôle | permissions fines vérifiées par Voters, gestion des rôles réservée au super-admin, plafonds de gestes |
| E | Abus d'impersonation | super-admin uniquement, bandeau, AuditLog, actions financières bloquées |
| I | Exfiltration via exports | exports PII limités aux rôles de l'annexe E, journalisés, fichiers expirant (24 h), URL signée |
| R | Actions admin niées | AuditLog append-only à chaînage de hash, horodatage, IP |
| T | Modification de paramètre critique | historique avant/après, validation des bornes, rôle requis par clé |

### Notification
| | Menace | Contrôles |
|---|---|---|
| T | Injection dans les modèles d'email (BO11) | Twig sandbox (`SecurityPolicy` : tags, filtres et variables en liste blanche) |
| I | Fuite de PII dans les logs d'envoi | destinataire haché, aucun corps de message journalisé |
| S | Usurpation de l'expéditeur | SPF, DKIM, DMARC `p=reject` sur un sous-domaine d'envoi dédié |

### Transverse : chaîne d'approvisionnement et infrastructure
| | Menace | Contrôles |
|---|---|---|
| T | Dépendance compromise | `composer.lock` versionné, `composer audit` bloquant, roave/security-advisories, Dependabot, versions d'image Docker figées par digest |
| I | Secret commité | gitleaks (pre-commit + CI), coffre `secrets:set`, `.env.local` ignoré |
| T | SSRF via appels sortants | `HttpClient` scoped par service avec `base_uri` fixe, `NoPrivateNetworkHttpClient` posé contre le transport par `ExternalHttpClientsPass` (après résolution de l'URL, donc aussi contre les redirections et le rebond DNS), timeouts, aucune URL fournie par l'utilisateur ; seuls des identifiants validés (SIRET Luhn, n° TVA) ou des paramètres de requête encodés varient — vérifié par `ExternalHttpClientsTest` |
| T | Désérialisation Messenger | sérialiseur Symfony (JSON) avec types attendus, pas de `unserialize` PHP natif sur des données externes |

## 3. Mapping ASVS 4.0.3 niveau 2

Les modules indiqués sont ceux qui **portent** le contrôle. Chaque PR de module liste les exigences couvertes avec leur test.

| Chapitre ASVS | Exigences clés (N2) | Implémentation | Modules |
|---|---|---|---|
| **V1** Architecture | 1.1.2 modèle de menaces · 1.4 contrôle d'accès centralisé · 1.5 validation des entrées · 1.6 gestion des clés · 1.14 séparation | ce document, Voters, DTO validés, coffre + KEK, Deptrac, BO isolé | tous |
| **V2** Authentification | 2.1.1 ≥ 12 car. · 2.1.7 mots de passe compromis · 2.2.1 anti-automatisation · 2.4.1 Argon2id · 2.5 récupération sécurisée · 2.8 TOTP | `password_hashers` argon2id explicite, HIBP, login_throttling + Turnstile, reset haché, scheb/2fa | Identity, Administration |
| **V3** Sessions | 3.2.1 nouvel ID à l'authentification · 3.3.1 invalidation à la déconnexion · 3.3.3 invalidation après changement de mot de passe · 3.4 attributs de cookie · 3.7.1 ré-authentification | `migrate()`, `logout` invalidate, `signature_properties`, `__Host-` cookies, `IS_AUTHENTICATED_FULLY` + reauth récent | Identity, Administration |
| **V4** Contrôle d'accès | 4.1.1 côté serveur · 4.1.3 moindre privilège · 4.2.1 IDOR · 4.2.2 CSRF · 4.3.1 2FA admin | Voters par ressource, UUID v7, CSRF sur formulaires + en-tête pour Turbo/Live, tests IDOR systématiques | tous |
| **V5** Validation, assainissement, encodage | 5.1.3 validation positive · 5.2.1 sanitizer HTML · 5.2.6 SSRF · 5.3.3 échappement contextuel · 5.3.4 requêtes paramétrées · 5.3.7 template injection | Symfony Validator sur DTO, html-sanitizer, HttpClient scoped, Twig autoescape, Doctrine/DBAL paramétré, Twig sandbox pour les modèles BO | tous |
| **V6** Cryptographie stockée | 6.2.1 algorithmes éprouvés · 6.2.5 modes sûrs · 6.4.1 gestion des clés · 6.3 aléa sûr | libsodium (XChaCha20-Poly1305), KEK versionnée dans le coffre, `random_bytes` | ArtisanProfile, Messaging, Administration |
| **V7** Erreurs et journalisation | 7.1.1 pas de secrets/PII · 7.1.3 événements de sécurité · 7.3.1 anti-injection de logs · 7.3.3 protection des logs · 7.4.1 message générique | `PiiMaskingProcessor`, AuditLog chaîné, Monolog JSON, pages d'erreur génériques | Shared, Administration |
| **V8** Protection des données | 8.1.1 cache · 8.2.2 stockage navigateur · 8.3.1 données sensibles hors URL · 8.3.4 identification · 8.3.8 rétention | `Cache-Control: private, no-store` sur les espaces connectés, aucun token en URL hors liens email à usage unique, purges Scheduler | tous |
| **V9** Communications | 9.1.1 TLS · 9.1.2 suites fortes · 9.2.1 TLS sortant vérifié | HSTS (preload après validation), TLS 1.2+ au CDN/proxy, vérification des certificats par HttpClient | infra |
| **V10** Code malveillant | 10.3.2 intégrité des dépendances | lockfile, audit, digests d'images, SRI pour les scripts CDN éventuels | CI |
| **V11** Logique métier | 11.1.1 séquence · 11.1.2 limites de débit métier · 11.1.4 anti-automatisation · 11.1.6 concurrence | machines à états (ADR-0005), Rate Limiter, verrous pessimistes RG-101 | QuoteRequest, Quote, Billing, Review |
| **V12** Fichiers | 12.1.1 taille · 12.2.1 type réel · 12.3 noms régénérés · 12.4.1 stockage hors webroot · 12.5.2 pas d'exécution · 12.6.1 SSRF fichiers | pipeline ADR-0008, ClamAV, S3 privé, `Content-Disposition: attachment` | ArtisanProfile, QuoteRequest, Messaging, Content |
| **V13** API / web services | 13.1.3 pas de secrets en URL · 13.2.1 méthodes HTTP · 13.2.6 Content-Type · 13.1.5 webhooks signés | routes avec `methods:` explicites, vérification du `Content-Type`, webhook Stripe signé | UI, Billing |
| **V14** Configuration | 14.1.1 build reproductible · 14.2.1 composants à jour · 14.3.2 pas de debug en prod · 14.4 en-têtes · 14.5.3 CORS | Docker, Dependabot, `APP_DEBUG=0`, nelmio : CSP nonce, HSTS, XFO DENY, nosniff, Referrer-Policy, Permissions-Policy ; pas de CORS ouvert | infra, Shared |

## 4. En-têtes HTTP cibles (nelmio/security-bundle)

```
Strict-Transport-Security: max-age=31536000; includeSubDomains   (+ preload après validation)
Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{n}' https://js.stripe.com https://challenges.cloudflare.com;
  style-src 'self' 'nonce-{n}'; img-src 'self' data: https://<cdn>; font-src 'self'; connect-src 'self' https://api.stripe.com;
  frame-src https://js.stripe.com https://hooks.stripe.com https://challenges.cloudflare.com; frame-ancestors 'none';
  base-uri 'self'; form-action 'self' https://checkout.stripe.com; object-src 'none'; upgrade-insecure-requests
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(self), camera=(), microphone=(), payment=(self "https://js.stripe.com")
Cross-Origin-Opener-Policy: same-origin
```
Le back-office applique une CSP plus restrictive (sans Stripe.js) et `Cache-Control: no-store`.

## 5. Rate limits par défaut (paramétrables, à ajouter à l'annexe G)

| Route / action | Politique | Clé proposée |
|---|---|---|
| Autocomplétion | 30 req / 10 s / IP (sliding window) | `ratelimit.autocomplete` |
| Recherche | 60 / min / IP | `ratelimit.search` |
| Connexion | 5 échecs / 15 min / compte et / IP | `auth.login_max_attempts`, `auth.login_window_min` |
| Inscription | 5 / h / IP | `ratelimit.signup` |
| Mot de passe oublié | 3 / h / compte, 10 / h / IP | `ratelimit.password_reset` |
| Dépôt de demande | 5 / h / utilisateur | `ratelimit.request_submit` |
| Messages | 30 / h / utilisateur, 10 / min / conversation | `ratelimit.message` |
| Révélation du téléphone | 20 / jour / utilisateur | `ratelimit.phone_reveal` |
| Contact, newsletter | 5 / h / IP | `ratelimit.contact`, `ratelimit.newsletter` |
| Webhook Stripe | 300 / min (global) | `ratelimit.stripe_webhook` |

## 6. Tests de sécurité automatisés (obligatoires par module)

- **IDOR** : pour chaque route paramétrée par un identifiant de ressource, tester l'accès par A à la ressource de B, attendu **404** (on ne révèle pas l'existence). Ces tests sont générés à partir d'un *provider* qui liste toutes les routes et leur Voter.
- **CSRF** : POST sans token → 403 ; vérification du token pour Turbo/Live.
- **Rate limit** : dépassement → 429 avec `Retry-After`.
- **Upload** : EICAR, polyglotte JPEG/PHP, PDF avec JS, extension trompeuse, > 10 Mo → rejet.
- **Webhook** : signature absente, invalide ou expirée → 400 ; rejeu → pas de double effet.
- **Énumération** : réponses identiques (corps, code, ordre de grandeur du temps) pour un email existant ou inexistant (login, reset, inscription, newsletter).
- **En-têtes** : présence et valeurs de la CSP et des autres en-têtes sur un échantillon de routes.
- **Logs** : test qui déclenche un flux complet et vérifie qu'aucun email ni téléphone en clair n'apparaît dans `var/log`.

## 7. Checklist pré-production (pentest)

- [ ] Pentest externe (boîte grise) sur www + admin + webhook, rapport et corrections — cahier des charges prêt :
  `docs/pentest.md` ; à commander par le porteur
- [ ] Revue de la configuration TLS (SSL Labs A+), HSTS, DNS (CAA, DMARC `p=reject`) — enregistrements et étapes :
  `docs/exploitation.md` §7, à appliquer à la mise en ligne ; HSTS déjà envoyé en production (`nelmio_security.forced_ssl`)
- [~] Restauration de sauvegarde testée (RPO 24 h, RTO à définir) — scripts testés sur la base de test
  (`ops/backup/`, `docs/exploitation.md` §4) ; à refaire sur la production chaque mois, RTO 4 h proposé
- [ ] Rotation des secrets de test → prod, clés Stripe live restreintes (restricted keys) — porteur, à la mise en ligne
- [x] `APP_DEBUG=0`, profiler absent, `/_error` inaccessible — `ProductionConfigurationTest` démarre le noyau `prod` :
  pas de mode débogage, pas de profileur (paquet absent), aucune route `/_error`, `/_profiler`, `/_wdt`, `/_dev`
- [x] Revue des droits PostgreSQL — `ops/postgres/roles.sql` (rôle propriétaire pour les migrations, rôle applicatif
  sans droit de structure et propriétaire d'aucune table, sans UPDATE / DELETE sur les tables en ajout seul) ;
  vérifié sur une copie de la base. **À appliquer au déploiement** (T-906). Risque résiduel : P-156
- [~] AIPD réalisée (vérification d'identité, modération automatisée — §11) — projet `docs/aipd.md` (T-905b) ; reste la
  validation et la signature du responsable de traitement
- [x] `security.txt` publié (`/.well-known/security.txt`, RFC 9116, contact `SECURITY_CONTACT` à confirmer) ;
  procédure d'incident et de notification CNIL (72 h) : `docs/incidents.md` (noms de l'astreinte à compléter),
  intégrité du journal d'audit vérifiable par `app:audit:verify`

### Vérifications automatisées ajoutées avant le pentest (T-905a, 20/11/2026)

| Contrôle | Test | Résultat |
|---|---|---|
| Carte du contrôle d'accès (§6 IDOR / ASVS 4.1.1, 4.1.3) : toutes les routes de `/admin`, `/espace-pro`, `/espace-client`, `/inscription/pro/`, toutes méthodes, avec des identifiants d'essai | `AccessControlMapTest` | anonyme, client et artisan refusés hors de leur espace (≈ 550 couples route / méthode, jamais de 2xx ni de 5xx) ; une nouvelle route est couverte d'office |
| Journaux sans e-mail ni téléphone (§6 Logs / ASVS 7.1.1) après connexion échouée et réussie, mot de passe oublié, contact | `LogsWithoutPersonalDataTest` | aucune occurrence, tous canaux et niveaux |
| Surface de débogage en production | `ProductionConfigurationTest` | absente |
| Dépendances (ASVS 14.2.1) | `composer audit`, `importmap:audit` | aucune vulnérabilité connue au 20/11/2026 |
| `security.txt` | `SecurityTxtTest` | contact, expiration glissante < 1 an, adresse canonique |

Déjà couverts par les tests des modules : CSRF (formulaires, Turbo / Live), limites de débit (429 + `Retry-After`),
fichiers (EICAR, polyglotte, PDF avec JavaScript, taille), webhook Stripe (signature, rejeu), énumération (connexion,
mot de passe oublié, inscription), en-têtes (`SecurityHeadersTest`).
