# Plan de livraison du MVP — tickets

> Statut : **proposition — Étape 0**. Périmètre : lot MVP de l'annexe H, dans l'ordre recommandé.
> Taille : **S** ≤ 1 j · **M** 2–3 j · **L** 4–5 j · **XL** > 5 j (à découper avant de démarrer). Estimations indicatives pour 1 développeur senior.
> Chaque ticket hérite de la **Definition of Done** (cadrage §7) : CI verte, tests (unitaires domaine, intégration, fonctionnels + IDOR), migrations réversibles, conformité Figma sur les 3 breakpoints, états vides/erreur, traçabilité et contrôles ASVS listés dans la PR.
> Format de commit : `feat(<module>): <résumé> (<réf CDC>)`.

## Avancement

| Ticket | Statut | Remarque |
|---|---|---|
| T-101 Squelette + `Shared` | ✅ | Symfony 7.4.23 LTS, PHP 8.4 (plateforme Composer 8.4.27) |
| T-102 Docker Compose | ✅ | Stack démarrée et vérifiée sur Apple Silicon : PHP 8.4.27 (FrankenPHP 1.13.3), PostGIS 3.5, tous services sains ; circuit asynchrone commande → outbox → Redis → worker vérifié de bout en bout ; suite QA complète verte dans le conteneur. S3 dev = SeaweedFS (P-21) |
| T-103 CI | ✅ | Toutes les étapes exécutées en local avec succès ; premier passage sur GitHub à faire après la création du dépôt distant |
| T-104 Bus + outbox | ✅ | |
| T-105 SettingsProvider | ✅ | 101 clés (annexe G + points en suspens) |
| T-106 Socle sécurité | 🟨 | En-têtes, sessions, Argon2id, limites, masquage des PII : faits. Nonces CSP utilisés dès T-110 ; limites branchées aux routes au fil des lots |
| T-107 AuditLog | ✅ | Partitionnement mensuel reporté à T-806 |
| T-108 Design system | ✅ | Tokens Figma V2 + audit V2.1, Poppins auto-hébergée, sprite Lucide (39), composant Icon, gabarit de base (nonce CSP, lien d'évitement), planche `/_dev/design-system` ; écarts P-24 à P-27 |
| T-109 Composants Twig | ✅ | Button, Field + thème de formulaire Symfony, Select, Checkbox/Radio, Badge, Toast (+ région, flashs), Modal (`<dialog>`), Stepper, CreditGauge, CardArtisan verticale + squelette, EmptyState, Skeleton ; planche `/_dev/components`. Carte horizontale et compacte → T-302 |
| T-110 Mise en page commune | ✅ | En-tête 4 variantes (visiteur, client, artisan + jauge compacte, tunnel) + méga-menu + menus compte + menu mobile `<dialog>`, pied de page (accordéon mobile, réseaux et newsletter conditionnels), fil d'Ariane + JSON-LD ; **correctif CSP** : CSS chargées par `<link>` (l'import JS bloquait tout le JavaScript) ; planche `/_dev/layout` |
| T-111 CMP cookies | ✅ | Bandeau non bloquant + préférences `<dialog>`, interrupteurs (`Switch`), cookie `tm_consent` versionné (6 mois), preuve serveur append-only `identity.consent` (IP hachée, origine vérifiée, limite de débit), scripts gardés `type="text/plain"`, `dataLayer` filtré (`assets/lib/analytics.js`), retrait = rechargement ; vérifié en navigateur |
| T-112 Référentiel Catalog | ✅ | Agrégats `Category` (2 niveaux) et `Job` (synonymes, justificatifs, désactivation avec remplacement), mapping XML, cas d'usage audités, slugs réservés, import idempotent du référentiel initial (6 catégories, 24 métiers), méga-menu alimenté et mis en cache, **301 automatiques** (chaînes aplaties, boucles supprimées, préfixe métier × ville). Écrans BO06 → T-209 (back-office authentifié) ; commandes console en attendant |
| T-113 Référentiel géographique | ✅ | Import officiel (API Découpage administratif : COG INSEE, codes postaux, centroïdes) — 18 régions, 101 départements, 34 875 communes en 13 s, idempotent, audité ; slugs stables `nom-cp` ; communes disparues désactivées (garde-fou 90 %) ; COM hors périmètre (P-35) ; requêtes PostGIS par rayon et recherche sans accents / par code postal (index GiST et trigramme vérifiés) ; activation des pages villes par seuil de population ; mise à jour annuelle planifiée (15 février) |
| T-114 Autocomplétion | ✅ | Module Search : index Meilisearch métiers (nom, synonymes, catégorie ; tolérance aux fautes, français) et communes (34 875, classement par population, synonymes st/ste) ; réindexation complète sans coupure (index de transit + échange atomique) ; mise à jour asynchrone sur les événements Catalog (nouvelles tentatives, transport d'échec) ; `GET /api/suggestions` (≥ 2 car., 8 suggestions, texte brut, 30 req./10 s par IP) ; **repli PostgreSQL** si le moteur est indisponible (`X-Search-Mode: fallback`) ; codes postaux toujours en correspondance exacte ; clés de recherche et d'administration séparées. Champ de saisie et liste déroulante → T-301 (accueil) |
| T-115 Services tiers | ✅ | Ports et adaptateurs : Sirene 3.11 (`CompanyRegistry`), géocodage BAN via la Géoplateforme IGN (`AddressGeocoder`), VIES (`VatNumberChecker`, clé TVA française contrôlée localement), Turnstile (`HumanVerification`), HIBP branché sur la contrainte `NotCompromisedPassword` ; clients scoped à hôte fixe, délais courts, reprises sur les requêtes idempotentes ; **anti-SSRF** : `NoPrivateNetworkHttpClient` placé contre le transport (testé, rebond DNS compris) ; essais réels concluants (BAN, Turnstile, HIBP, VIES ; Sirene en attente de clé, P-37). Points P-37 à P-39 |
| T-116 Stockage de fichiers | ✅ | Registre `shared.stored_file` commun ; Flysystem (S3 privé : quarantaine / documents, mémoire en test) ; pipeline `FileUploader` : taille (paramètre), type réel (finfo), images ré-encodées par GD (EXIF/GPS retirés, orientation appliquée, polyglottes neutralisés, bombe de décompression bloquée, 4096 px max), PDF actifs refusés (JS, Launch, fichiers joints, y compris compressés ou obscurcis ; PDF chiffrés refusés) ; analyse **ClamAV asynchrone** (INSTREAM, nouvelles tentatives, malware → suppression + événement de sécurité) ; **chiffrement enveloppe** libsodium des justificatifs (DEK par fichier, KEK versionnées, rotation) ; lecture journalisée ou URL pré-signée (≤ 5 min, `attachment`) ; destruction par crypto-shredding. Vérifié contre SeaweedFS et le vrai ClamAV. Contrôleurs et Voters : avec leurs modules (T-207, T-402) |
| Lot 1 | ✅ | Socle terminé |
| T-201 Compte utilisateur | ✅ | Agrégat `User` (client / artisan ; statuts et transitions gardées, anonymisation terminale libérant l'e-mail), mapping XML `identity.user_account` (unicité partielle, CHECK Argon2id), politique de mot de passe commune (longueur paramétrable ≥ 12, 128 car. acceptés, sans règle de composition, refus si l'e-mail y figure, HIBP en dernier et non bloquant en cas de panne), hachage Argon2id explicite, `RegisterUser` / `ChangePassword` audités, fournisseur Symfony + `UserChecker` ; sessions sans empreinte et fermées après changement de mot de passe ou de statut ; `ROLE_VERIFIED` réservé aux e-mails confirmés (RG-040) |
| T-202 Inscription client | ✅ | A01 (Figma 159:1781) et A02 (15:24) intégrés sur 3 points de rupture (photos WebP, bannière de page, sprite +11 icônes) ; profil client particulier / entreprise (SIRET facultatif vérifié par Sirene, accepté non vérifié en cas de panne, P-43), téléphone facultatif, code postal vérifié dans le référentiel ; e-mails jetables refusés (10 181 domaines, CC0) ; Turnstile vérifié côté serveur (refus si indisponible, P-39) ; limite 5 inscriptions / h / IP ; **anti-énumération** (même réponse, empreinte toujours calculée, titulaire prévenu par événement) ; documents légaux versionnés immuables, HTML assaini, preuves d'acceptation avec empreinte du texte ; pages /cgu, /politique-de-confidentialite ; validation en ligne, jauge de robustesse, afficher / masquer le mot de passe ; événements `signup_type_select` et `sign_up` (avec consentement). Écarts Figma ↔ CDC : P-42 |
| T-203 → T-213 | ⬜ | Prochaine étape : validation de l'e-mail (T-203) |

## Lot 0 — Cadrage *(ce livrable)*
| ID | Ticket | Réf. | Taille |
|---|---|---|---|
| T-000 | ARCHITECTURE, ADR, modèle de données, SECURITY, points en suspens, plan | cadrage §7 | — |
| T-001 | **Validation par le porteur** des points en suspens bloquants (Q5, Q18, Q28, P-02, P-05) | annexe I | — |

## Lot 1 — Socle technique, design system, référentiels
| ID | Ticket | Réf. | Taille | Dépend |
|---|---|---|---|---|
| T-101 | `composer create-project` Symfony 7.4 LTS / PHP 8.4, arborescence modulaire, `Shared` (VO Uuid v7, Email, Siret, Money, InseeCode, GeoPoint, Clock) | ADR-0001/2/3 | M | T-001 |
| T-102 | Docker Compose : FrankenPHP, Postgres 16 + PostGIS, Redis, Meilisearch, ClamAV, Mailpit, MinIO, worker, scheduler | §3 cadrage | M | T-101 |
| T-103 | CI : PHPStan max, Deptrac (couches + modules), PHP-CS-Fixer, PHPUnit, composer audit, roave, gitleaks, lint, `schema:validate`, migrations up/down | §5.9 | M | T-101 |
| T-104 | Bus Messenger (command/query/event), middleware de transaction, outbox, transports, `failed` en Doctrine | ADR-0011 | M | T-101 |
| T-105 | `SettingsProvider` + `admin.setting(_history)` + seed annexe G + clés proposées + règle PHPStan « pas de littéral métier » | RG-014, ADR-0007 | M | T-104 |
| T-106 | Socle sécurité : en-têtes nelmio (CSP nonce, HSTS…), cookies `__Host-`, CSRF Turbo/Live, Rate Limiter (config des politiques), `PiiMaskingProcessor`, pages d'erreur | §10, ASVS V3/V7/V14 | M | T-101 |
| T-107 | AuditLog append-only chaîné + partitionnement | BO15, ASVS V7 | S | T-104 |
| T-108 | Design system : extraction des tokens Figma (`get_variable_defs`) → `tokens.css`, Poppins auto-hébergée, sprite Lucide, styles texte | §2, ADR-0012, P-05 | M | T-101 |
| T-109 | Composants Twig : Button, Input, Select, Checkbox, Badge, Toast, Modal, Stepper, Skeleton, EmptyState, CreditGauge, CardArtisan + page de démo interne | annexe B | L | T-108 |
| T-110 | Layouts : header (4 variantes), footer, fil d'Ariane (BreadcrumbList), lien d'évitement | §3.1, §3.3 | M | T-109 |
| T-111 | CMP cookies conforme CNIL + `consent` + `dataLayer` conditionnel | §3.2 | M | T-110 |
| T-112 | Catalog : catégories / sous-catégories / métiers / synonymes (domaine + Doctrine + BO06 CRUD minimal + redirection 301 sur changement de slug) | BO06 | L | T-105 |
| T-113 | Import INSEE communes / départements / régions + centroïdes PostGIS + codes postaux (commande console idempotente) | BO07 | M | T-102 |
| T-114 | Indexation Meilisearch (métiers, synonymes, communes) + endpoint d'autocomplétion rate limité | RG-010, ADR-0004 | M | T-112, T-113 |
| T-115 | Ports et adapters sortants : HttpClient scoped (Sirene, BAN, VIES, HIBP, Turnstile) avec `NoPrivateNetworkHttpClient` | §5.3 | S | T-101 |
| T-116 | Stockage de fichiers : Flysystem quarantaine/documents, pipeline d'upload, ClamAV asynchrone, chiffrement enveloppe | ADR-0008, RG-061 | L | T-104 |

## Lot 2 — Identité, inscription et vérification artisan
| ID | Ticket | Réf. | Taille | Dépend |
|---|---|---|---|---|
| T-201 | Identity : agrégat User, statuts, Argon2id explicite, `NotCompromisedPassword`, longueur ≥ 12 | A02, ASVS V2 | M | T-105 |
| T-202 | A01 choix du type de compte + A02 inscription client (Turnstile, emails jetables, acceptations légales versionnées) | A01, A02, RG-002 | M | T-201 |
| T-203 | A06 validation d'email (token haché) + blocage RG-040 + changement d'email (E-06) | A06, RG-040 | M | T-201 |
| T-204 | A04 connexion : login_throttling compte+IP, captcha après échecs, message unique, redirection en liste blanche, remember-me 30 j, nouvel appareil E-05 | A04, RG-045 | M | T-201 |
| T-205 | A05 mot de passe oublié : anti-énumération, token haché, TTL 1 h, 3/h, invalidation des sessions, E-04 | A05 | M | T-201 |
| T-206 | Notification (socle) : modèles E-xx en base, Twig sandbox, Brevo via Mailer, journal d'envoi, in-app minimal | §6, annexe F | L | T-104 |
| T-207 | A03 inscription artisan multi-étapes : compte, entreprise (Sirene, Luhn, unicité SIRET RG-041), métiers (≤ `pro.max_jobs`), zone (RG-042), justificatifs, récapitulatif | A03 | XL → découper (a: étapes 1–3, b: 4–6) | T-207a T-112, T-116 |
| T-208 | ArtisanProfile : machine à états RG-043, complétude, matérialisation `service_area_commune` | RG-042, RG-043 | M | T-207 |
| T-209 | Back-office socle : firewall + hôte admin, admin_user, 2FA TOTP, presets de rôles et permissions, timeout, ré-authentification, layout BO | ADR-0013, BO14 | L | T-106 |
| T-210 | BO03 file de vérification : visionneuse de justificatifs (accès journalisé), décision (RG-130), demande de complément, refus motivé, E-20/E-21/E-22/E-24 | BO03 | L | T-208, T-209 |
| T-211 | Billing (amorce) : `credit_account` + ledger append-only + `free_grant` sur `ArtisanVerified` (UQ RG-044) | RG-044, RG-107, ADR-0006 | M | T-210 |
| T-212 | Scheduler : alertes d'expiration des justificatifs J-30/J-7/J0, masquage +15 j, relances de dossier incomplet J+2/J+7 | RG-032, RG-130, E-23, E-25 | M | T-210 |
| T-213 | BO02 / BO03 listes et fiches utilisateurs (sans impersonation), suspension | BO02, BO03 | M | T-209 |

## Lot 3 — Recherche, fiches et pages publiques
| ID | Ticket | Réf. | Taille | Dépend |
|---|---|---|---|---|
| T-301 | Projection `artisan_search_view` + score RG-020 (poids paramétrés) + note bayésienne RG-012/024 | RG-012, RG-020–024 | L | T-208 |
| T-302 | P02 recherche et résultats (filtres, carte avec centroïdes RG-022, pagination « afficher plus », états) | P02, RG-021 | L | T-301, T-114 |
| T-303 | P10 fiche artisan publique (404/410, téléphone au clic tracé RG-030, schema LocalBusiness) | P10 | M | T-301 |
| T-304 | P03 / P04 / P05 catégories, métiers, métier × ville avec seuil d'indexation RG-023 | P03–P05 | L | T-301 |
| T-305 | P06 annuaire « Nos professionnels » (non cité explicitement en annexe H — à confirmer) | P06 | M | T-301 |
| T-306 | Content : CMS accueil 10 blocs (BO10.1) + P01 accueil | P01, BO10 | L | T-109 |
| T-307 | Pages P12, P13, P14 (FAQ), P16 (contact + Turnstile + E-90), P17 légales versionnées, P18 système | P12–P18 | L | T-306 |
| T-308 | SEO : meta et gabarits, redirections, sitemaps segmentés, robots, données structurées (BO12 de base) | §7, BO12 | M | T-304 |
| T-309 | ArtisanProfile : PR08 édition du profil public (sanitizer), portfolio | PR08, RG-031 | M | T-208 |

## Lot 4 — Dépôt de demande et diffusion
| ID | Ticket | Réf. | Taille | Dépend |
|---|---|---|---|---|
| T-401 | Agrégat QuoteRequest + machine RG-066 + `max_quotes` figé + RG-064/065 (doublons, plafond) | RG-064–066, RG-070 | M | T-105 |
| T-402 | P11 tunnel multi-étapes (Live Component), brouillon autosauvegardé RG-060, questionnaire par métier, photos (pipeline T-116), création de compte en fin de tunnel (E-11) | P11, RG-060, RG-061 | XL → découper (a: étapes 1–3, b: 4–6 + compte) | T-401 |
| T-403 | Score de risque RG-063 + file de modération des demandes (BO04/BO05) + E-10b | RG-063, BO05 | M | T-401 |
| T-404 | Diffusion RG-062 (requête PostGIS, demande directe + diffusion élargie, blocages) + `request_dispatch` + E-30/E-31 (immédiat / digest) | RG-062 | L | T-401, T-208 |
| T-405 | PR02 demandes disponibles + PR03 détail (identité masquée RG-112, aperçu avant vérification Q15) + Voter | PR02, PR03, RG-111, RG-112 | M | T-404 |
| T-406 | C01 tableau de bord client + C02 mes demandes (modifier tant qu'aucun devis, complément, clôturer, dupliquer) | C01, C02 | M | T-401 |
| T-407 | Scheduler : expiration RG-064, relance de brouillon E-19c, E-19 J-3 | RG-060, RG-064 | S | T-401 |

## Lot 5 — Devis, crédits, acceptation
| ID | Ticket | Réf. | Taille | Dépend |
|---|---|---|---|---|
| T-501 | Agrégat Quote + versions + lignes + calculs HT/TVA/TTC (Money) + machine RG-121 | RG-121 | M | T-401 |
| T-502 | PR04 éditeur de devis (Live Component), brouillon automatique, contrôle des mentions RG-120 (liste administrable) | PR04, RG-120 | L | T-501 |
| T-503 | PDF de devis (gabarit Twig → PDF, `pdf_sha256`, stockage privé) | PR04.4 | M | T-502, T-116 |
| T-504 | **Envoi atomique RG-101** : verrous, policy (a)–(f), débit ledger buckets RG-104, passage en `complète`, E-12/E-33/E-19b + **tests de concurrence** | RG-100–101, RG-104, RG-107 | L | T-501, T-211 |
| T-505 | Alertes de crédits RG-105 (E-42/E-42b) + jauge header / PR01 (4 états) + blocage RG-106 | RG-105, RG-106 | M | T-504 |
| T-506 | Révisions RG-102, retrait RG-103 (place libérée, P-09), relance unique (P-11), expiration de validité | RG-102, RG-103, RG-121 | M | T-504 |
| T-507 | C03 détail d'une demande : jauge, comparaison, acceptation (RG-071/072, modale juridique), refus motivé, non retenus (E-35), révélation des coordonnées (RG-022bis, E-13/E-34) | C03 | L | T-504 |
| T-508 | PR05 mes devis envoyés + export CSV | PR05 | M | T-504 |
| T-509 | PR01 tableau de bord artisan | PR01 | M | T-505 |
| T-510 | BO04 demandes et devis (re-diffuser, étendre la zone, clôturer, supprimer avec restitution automatique pour fraude) | BO04, Q2 | M | T-504, T-209 |

## Lot 6 — Stripe
| ID | Ticket | Réf. | Taille | Dépend |
|---|---|---|---|---|
| T-601 | BO08 forfaits (Plan, PlanPrice → Product/Price Stripe, statut, archivage) + paramètres de crédits | BO08, RG-108 | M | T-209, **Q5** |
| T-602 | `PaymentGateway` port + adapter stripe-php (Idempotency-Key, clés dans le coffre) + Customer par artisan | §5 | M | T-601 |
| T-603 | PR11 abonnement / PR12 tunnel (Checkout, Stripe Tax, VIES, codes promo) | PR11, PR12 | L | T-602 |
| T-604 | Webhooks : endpoint signé, `processed_stripe_event`, traitement async, handlers (activation, renouvellement → `period_grant` + `expiry` RG-109, upgrade Q7, downgrade, résiliation, échec, grâce, suspension, annulation à 30 j), E-40 à E-46 | §4.3, §5, ADR-0010 | XL → découper (a: socle + checkout/subscription, b: invoices/paiements, c: grâce/suspension) | T-602 |
| T-605 | PR13 factures (réplique + archive 10 ans) et moyens de paiement (Customer Portal) | PR13, ADR-0009 | M | T-604 |
| T-606 | BO08.3 codes promo synchronisés avec Stripe + remboursements / avoirs (rôle finance, ré-authentification) | BO08, §5 | M | T-604 |
| T-607 | Rapprochement quotidien Stripe ↔ base + vérification nocturne des soldes du ledger | ADR-0006, ADR-0010 | M | T-604 |

## Lot 7 — Messagerie, RDV, avis, agenda, favoris
| ID | Ticket | Réf. | Taille | Dépend |
|---|---|---|---|---|
| T-701 | Messaging : conversations, RG-081, masquage RG-080 (ADR-0014), original chiffré, pièces jointes, signalement, E-17 regroupé | C04, PR07 | L | T-504 |
| T-702 | PR06 agenda : règles de disponibilité, créneaux, export iCal privé révocable | PR06, RG-013, RG-025 | L | T-208 |
| T-703 | RDV : proposition / confirmation / report / annulation, verrou 24 h RG-073, rappel J-1, prestation réalisée → `terminée` | C03.6, PR06.2 | M | T-702, T-507 |
| T-704 | Review : dépôt certifié RG-033/034, score de risque, modération a priori (BO05.1), réponse modérée RG-036, E-16/E-18/E-37/E-38 | C06, PR09 | L | T-703 |
| T-705 | C05 favoris, C07 profil et compte (export RGPD E-07, suppression E-08/E-09 RG-090), C08 centre de notifications et préférences | C05, C07, C08 | L | T-206 |
| T-706 | BO05 modération : signalements, règles automatiques (mots interdits, motifs de coordonnées, seuils) | BO05 | M | T-701, T-704 |

## Lot 8 — CMS, SEO et back-office restant
| ID | Ticket | Réf. | Taille | Dépend |
|---|---|---|---|---|
| T-801 | BO10 pages (constructeur de blocs), FAQ, menus, médiathèque (alt obligatoire, WebP/AVIF), libellés | BO10 | XL → découper | T-306 |
| T-802 | BO11 modèles d'emails (éditeur, test, aperçu) + journal d'envoi | BO11 | M | T-206 |
| T-803 | BO12 SEO complet (rapport 404, import CSV des redirections) | BO12 | M | T-308 |
| T-804 | BO13 paramètres généraux (UI d'édition des settings + historique) | BO13 | M | T-105 |
| T-805 | BO01 tableau de bord KPI + files d'attente | BO01 | L | lots 2–6 |
| T-806 | BO15 journaux, exports CSV journalisés, finance de base (MRR, revenus, impayés) | BO15 | L | T-107 |
| T-807 | Impersonation (super-admin, bandeau, blocage financier, audit) | BO02, ADR-0013 | M | T-209 |
| T-808 | Scheduler RGPD : purges et anonymisations (§11) | §11, RG-090 | M | T-705 |

## Lot 9 — Recette et mise en production
| ID | Ticket | Réf. | Taille |
|---|---|---|---|
| T-901 | Jeux de données de démonstration **[DEMO]** clairement marqués (artisans fictifs en Normandie, aucun faux avis présenté comme réel) | Q27, cadrage §8 | M |
| T-902 | Tests E2E des parcours critiques (Playwright/Panther) : dépôt → devis → acceptation → avis ; souscription Stripe (mode test) | — | L |
| T-903 | Audit RGAA 4.1 (outils + manuel), corrections, déclaration d'accessibilité | §9 | L |
| T-904 | Performance : budgets LCP/INP/CLS, cache HTTP, CDN, images AVIF/WebP | §8 | M |
| T-905 | Pentest externe + checklist SECURITY.md §7 + AIPD | §10, §11 | L |
| T-906 | Exploitation : sauvegardes chiffrées et test de restauration, supervision, runbooks, DNS (SPF/DKIM/DMARC/CAA) | §10 | M |

## Hors MVP (rappel de l'annexe H)
V2 : offres et promotions (PR10, P09, BO09), « mieux notés » et « disponibilités » avancées (P07, P08), blog (P15), SMS, connexion sociale (A07), 2FA artisans, SEPA, synchronisation Google/Outlook, newsletter intégrée, packs de crédits, mise en avant payante, réouverture de demande, support/tickets (BO16), prix indicatifs calculés, multi-utilisateurs.
V3 : PWA et applications natives, signature électronique, paiement marketplace (Stripe Connect / DAC7), avis hors plateforme, IA, multilingue, API partenaires.

> ⚠️ Écart à signaler : l'annexe H classe P07, P08, P15, BO16 et les offres en V2, alors que le sommaire du CDC les spécifie entièrement. Ce plan suit **l'annexe H**. Le modèle de données les prévoit déjà, pour éviter toute refonte.
