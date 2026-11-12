# Matrice de traçabilité — CDC → code → tests

> Mise à jour à **chaque module livré** (Definition of Done). Une ligne par écran ou par règle RG. Une colonne vide signifie « non implémenté ».
> Statuts : ⬜ à faire · 🟨 en cours · ✅ livré et testé.

## Règles de gestion (annexe D)

| Réf. | Règle (résumé) | Module | Classes (Domain / Application) | Tests | Ticket | Statut |
|---|---|---|---|---|---|---|
| RG-014 | Aucune valeur métier en dur | Shared / Administration | `Shared\Application\Settings\{SettingKey, SettingDefinition, SettingsProvider, TypedSettingsProvider}`, `Administration\Application\Command\ChangeSettingHandler`, `Administration\Infrastructure\Settings\{DbalSettingsProvider, DbalSettingStore}`, règle PHPStan `tools/phpstan/NoBusinessLiteralRule` | `SettingKeyTest`, `SettingDefinitionTest`, `ChangeSettingHandlerTest`, `SettingsPersistenceTest` | T-105 | ✅ |
| RG-041 | SIRET obligatoire, valide, actif, unique | ArtisanProfile | `CompanyChecker`, index unique `artisan_profile_siret_uniq` | `ArtisanSignupTest` | T-207a | ✅ |
| RG-043 | Statuts du compte pro | ArtisanProfile | `ArtisanProfile::{submit,approve,reject,requestMoreInfo,suspend,reinstate,isPublic,canSendQuotes}`, `ChangeArtisanStatusHandler`, `RejectionReason` | `ArtisanStatusTest`, `ArtisanSignupCompletionTest` | T-208 | ✅ (écrans BO03 : T-210) |
| RG-044 | Crédit gratuit unique à la vérification | Billing | | | T-211 | ⬜ |
| RG-045 | Limitation des tentatives, nouvel appareil, non-énumération | Identity | | | T-204 | ⬜ |
| RG-062 | Diffusion aux éligibles | QuoteRequest | | | T-404 | ⬜ |
| RG-063 | Score de risque → modération | QuoteRequest / Moderation | | | T-403 | ⬜ |
| RG-066 | Cycle de statuts de la demande | QuoteRequest | | | T-401 | ⬜ |
| RG-080 | Masquage des coordonnées | Messaging | | | T-701 | ⬜ |
| RG-100 | Seul l'envoi v1 consomme 1 crédit | Quote / Billing | | | T-504 | ⬜ |
| RG-101 | Contrôle atomique à l'envoi | Quote / Billing | | concurrence | T-504 | ⬜ |
| RG-104 | Ordre de consommation | Billing | | | T-504 | ⬜ |
| RG-107 | Ledger append-only | Billing | | | T-211 | ⬜ |
| RG-121 | Cycle de statuts du devis | Quote | | | T-501 | ⬜ |
| RG-041 (partiel) | Format et clé de Luhn du SIRET | Shared | `Shared\Domain\ValueObject\Siret` | `SiretTest` | T-101 | 🟨 (unicité et Sirene : T-207) |
| RG-022 (partiel) | Point géographique = centroïde de commune | Shared | `Shared\Domain\ValueObject\{GeoPoint, InseeCode}` | `GeoAndInseeTest` | T-101 | 🟨 (PostGIS : T-113) |
| … | *(toutes les RG de l'annexe D sont ajoutées au fil des lots)* | | | | | |

## Exigences transverses (socle)

| Exigence | Réf. | Implémentation | Tests | Ticket | Statut |
|---|---|---|---|---|---|
| Journal d'audit append-only, inviolable | BO15, cadrage §5.8 | `Administration\Domain\Audit\AuditRecord` (hash chaîné), `DbalAuditTrail`, triggers `shared.forbid_mutation()` (UPDATE/DELETE/TRUNCATE) | `AuditRecordTest`, `AuditTrailTest` (dont la détection d'altération quand le trigger est contourné) | T-107 | ✅ (partitionnement mensuel : à faire, T-806) |
| Paramètres historisés avant/après | BO13 | `admin.setting_history` append-only + entrée d'audit | `SettingsPersistenceTest` | T-105 | ✅ |
| Outbox transactionnelle, at-least-once | ADR-0011 | `OutboxEventBus`, `OutboxRelay` (`SKIP LOCKED`), `app:outbox:relay` | `OutboxRelayTest` | T-104 | ✅ |
| Sérialisation JSON des messages (pas d'`unserialize`) | SECURITY.md (désérialisation) | `messenger.transport.symfony_serializer` | `OutboxRelayTest` | T-104 | ✅ |
| Aucune donnée personnelle dans les journaux | cadrage §5.8, ASVS 7.1.1 | `Shared\Infrastructure\Logging\PiiMaskingProcessor` | `PiiMaskingProcessorTest` | T-106 | ✅ |
| En-têtes de sécurité (CSP sans `unsafe-inline`, XFO, nosniff, Referrer, Permissions) | cadrage §5.4, ASVS 14.4 | `config/packages/nelmio_security.yaml` (HSTS en prod) | `SecurityHeadersTest` | T-106 | ✅ (nonces : utilisés dès le premier gabarit, T-110) |
| Argon2id explicite | cadrage §5.1, ASVS 2.4 | `config/packages/security.yaml` | — (vérifié au lot 2, T-201) | T-106 | 🟨 |
| Limites de débit | SECURITY.md §5 | `config/packages/rate_limiter.yaml` | — (branchées aux routes aux lots 2 à 7) | T-106 | 🟨 |
| Charte et design system | CDC §2, RG-001, audit V2.1 | `assets/styles/{tokens,fonts,base}.css`, `assets/icons/sprite.svg`, `templates/components/Icon.html.twig` | `DesignTokensTest` (contrastes AA, couleurs hors tokens, 12 px min., icônes), `DesignSystemPageTest` | T-108 | ✅ |
| Composants d'interface (annexe B) | CDC §3.3, §9, annexe B | `templates/components/*`, `templates/form/theme.html.twig`, `assets/styles/components/*`, `assets/controllers/{toast,modal,modal_opener}_controller.js` | `TwigComponentsTest` (12), `FormThemeTest` (5) | T-109 | ✅ |
| En-tête, pied de page, fil d'Ariane | CDC §3.1, §3.3, §7, P01.10, L111-7 | `templates/components/{Header,Footer,Breadcrumb,Logo}.html.twig`, `assets/styles/layout/*`, `assets/controllers/{header,disclosure,footer_accordion}_controller.js`, `Shared\UI\Twig\NavigationExtension` | `LayoutTest` (7, dont la non-régression CSP de l'importmap) | T-110 | ✅ |
| Bandeau cookies (CMP) et consentement | CDC §3.2, §11, cadrage §5.8 et §6 | `Identity\Domain\Consent\*`, `RecordCookieConsentHandler`, `DbalConsentLog`, `CookieConsentController`, `templates/components/{CookieConsent,Switch}.html.twig`, `assets/controllers/consent_controller.js`, `assets/lib/{consent,analytics}.js` | `CookieConsentTest` (6), `CookieConsentEndpointTest` (7), `CookieConsentComponentTest` | T-111 | ✅ |
| Référentiel des services | BO06, P01.1, P03, RG-010, A03.3, Q16 | `Catalog\Domain\{Category,Job}\*`, `Catalog\Application\Command\*`, `JobSlugPolicy`, `DbalMainCategoriesHandler`, `Catalog\UI\Twig\CatalogExtension`, `app:catalog:*` | `CatalogDomainTest` (12), `CatalogPersistenceTest` (4), `LayoutTest` (méga-menu) | T-112 | ✅ (écrans BO06 : T-209) |
| Redirections 301 sur changement de slug | BO06, BO12.2, CDC §7 | `RedirectRenamedCatalogPages`, `DbalRedirectStore`, `RedirectOnNotFoundSubscriber` | `RedirectStoreTest` (5), `RedirectOnNotFoundTest` (2) | T-112 | ✅ |
| Référentiel géographique | BO07.1, RG-021, RG-022, ADR-0004 | `Catalog\Application\Geo\GeoReferentialImporter`, `GeoApiGouvSource`, `JsonFileGeoSource`, `DbalGeoReferentialStore`, `DbalCommuneQueriesHandler`, `RefreshGeoReferentialTask`, `app:geo:import`, `app:geo:seo-pages` | `GeoReferentialImporterTest` (7), `GeoReferentialTest` (6, PostGIS) | T-113 | ✅ (BO07.2 couverture métier × département : après T-208) |
| Autocomplétion métiers et lieux | P01.2, RG-010, ADR-0004, cadrage §5.7 | `Search\Application\Query\AutocompleteHandler`, `SearchIndexer`, `ReindexJobsOnCatalogChange`, `MeilisearchAutocompleteIndex`, `AutocompleteController` (`/api/suggestions`), `app:search:reindex`, `DbalJobQueriesHandler` (repli) | `AutocompleteHandlerTest` (5), `SearchIndexerTest` (3), `MeilisearchAutocompleteIndexTest` (4), `MeilisearchIndexTest` (3, moteur réel), `JobSearchQueriesTest` (3), `AutocompleteEndpointTest` (4, dont limite de débit) | T-114 | ✅ (interface de saisie : T-301) |
| Services tiers sortants, anti-SSRF | cadrage §5.3, §5.8, RG-041, RG-045, ADR-0004, ADR-0009, ASVS 2.1.7 / 5.2.6 | `ExternalHttpClientsPass`, `InseeSireneCompanyRegistry`, `BanAddressGeocoder`, `ViesVatNumberChecker`, `TurnstileHumanVerification`, `hibp.client` | `ExternalHttpClientsTest` (24 : réseau privé, métadonnées, rebond DNS), `InseeSireneCompanyRegistryTest` (10), `BanAddressGeocoderTest` (4), `ViesVatNumberCheckerTest` (9), `TurnstileHumanVerificationTest` (5) | T-115 | ✅ (usages : T-204, T-207, T-402, T-6xx) |
| Fichiers : stockage privé, antivirus, chiffrement | cadrage §5.5, ADR-0008, RG-061, Q16, ASVS V12 / 6.2 | `FileUploader`, `ScanQuarantinedFile`, `FileReader`, `FileShredder`, `NativeFileSanitizer`, `ClamAvScanner`, `SodiumEnvelopeEncryption`, `FlysystemObjectStorage`, `DbalStoredFileRepository`, `app:storage:setup` | `NativeFileSanitizerTest` (16), `SodiumEnvelopeEncryptionTest` (14), `ClamAvScannerTest` (4), `ClamAvDaemonTest` (démon réel), `FileStoragePipelineTest` (8) | T-116 | ✅ (contrôleurs et Voters : T-207, T-402) |
| Compte utilisateur et mots de passe | A01, A02, A06, RG-040, cadrage §5.1, ASVS 2.1 / 2.4 / 3.3.3 | `Identity\Domain\User\User`, `PasswordPolicy`, `SymfonyPasswordHasher`, `HibpCompromisedPasswordChecker`, `RegisterUserHandler`, `ChangePasswordHandler`, `UserProvider`, `UserChecker`, `SecurityUser` | `UserTest` (14), `PasswordPolicyTest` (10), `SecurityUserTest` (4), `UserAccountTest` (7) | T-201 | ✅ (formulaires : T-202, T-204) |
| Choix du compte et inscription client | A01, A02, RG-002, RG-040, cadrage §5.1 / §5.8, Q28 | `RegistrationController`, `ClientRegistrationType`, `RegisterClientHandler`, `ClientProfile`, `FileDisposableEmailDetector`, `DbalLegalDocuments`, `DbalLegalAcceptanceLog`, `LegalDocumentController`, `app:legal:publish`, `templates/auth/*`, `assets/controllers/{signup,password,analytics}_controller.js` | `ClientRegistrationTest` (21), `ClientRegistrationRulesTest` (5), `LegalDocumentsTest` (1), contrôle navigateur (bascule entreprise, jauge, œil, validation en ligne) | T-202 | ✅ (e-mails E-01 / E-02 : T-203, T-206) |
| Validation et changement d'e-mail | A06, RG-040, E-01, E-06, ASVS 2.5 / 3.5 | `ConfirmEmailHandler`, `EmailTokenStatusHandler`, `ResendEmailVerificationHandler`, `RequestEmailChangeHandler`, `EmailVerificationSender`, `SecretToken`, `DbalEmailTokens`, `SymfonyIdentityMailer`, `EmailVerificationController`, `templates/email/*`, `EmailVerificationBanner` | `EmailVerificationTest` (6), `EmailVerificationPageTest` (4), `UserTest` (+2) | T-203 | ✅ (formulaire de changement d'adresse : C07) |
| Connexion et protection contre la force brute | A04, RG-045, E-05, cadrage §5.1, ASVS 2.2.1 / 3.4 / 5.1.5 | `LoginController`, `LoginSubscriber`, `LoginAttemptGuard`, `TargetPathPolicy`, `LoginSuccessHandler`, `UserChecker`, `RecordLoginHandler`, `DbalKnownDevices`, `security.yaml` (form_login, remember_me, logout) | `LoginTest` (10), `DeviceDescriptionTest` (6), `SecurityUserTest` | T-204 | ✅ (2FA artisans : V2 ; admin : T-209) |
| Mot de passe oublié et réinitialisation | A05, E-03, E-04, ASVS 2.5.1 / 2.5.6 / 3.3.3 | `PasswordResetController`, `RequestPasswordResetHandler`, `ResetPasswordHandler`, `ResetLinkStatusHandler`, `ResetLinkHolderHandler`, `SendPasswordChangedConfirmation`, `DbalPasswordResetTokens` | `PasswordResetTest` (7) | T-205 | ✅ |
| Socle des notifications | CDC §6, annexe F, BO11.1 / BO11.2, cadrage §5.9 | `NotificationDispatcher`, `SandboxedTemplateRenderer`, `TemplateCatalogSync`, `SymfonyMailerEmailSender`, `DbalDeliveryLog`, `DbalInAppNotifications`, `NotificationExtension`, `config/notification/*`, `NotificationIdentityMailer` | `NotificationDispatcherTest` (6, dont attaques du bac à sable) | T-206 | ✅ (écrans BO11 : back-office) |
| Inscription artisan, étapes 1 à 3 | A03.1–A03.3, RG-041, RG-043, P-46 | `ArtisanAccountController`, `RegisterArtisanAccountHandler`, `AccountRegistrar`, `ArtisanSignupController`, `ArtisanProfile`, `CompanyDetails`, `CompanyChecker`, `DeclareCompanyHandler`, `ChooseJobsHandler`, `SignupProgressHandler`, `templates/pro_signup/*`, `job_picker_controller.js` | `ArtisanSignupTest` (13), `ArtisanProfileTest` (7), contrôle navigateur (étapes 2 et 3) | T-207a | ✅ |
| Inscription artisan, étapes 4 à 7 | A03.4–A03.7, RG-022, RG-032, RG-042, RG-043, RG-044, ADR-0008, Q16, P-47, E-20 | `ServiceAreaController`, `InsuranceController`, `DocumentsController`, `PublicProfileController`, `ServiceArea`, `Insurance`, `Qualification`, `ArtisanDocument`, `SignupRequirements`, `DefineServiceAreaHandler`, `DeclareInsurancesHandler`, `UploadDocumentHandler`, `CompleteDocumentsStepHandler`, `UploadLogoHandler`, `SubmitSignupHandler`, `TrackDocumentScan`, `SendSignupReceived`, `NotificationSignupMailer`, Catalog `AreaCoverage` / `CommunesByInsee` / `Departments` / `JobRequirements`, Shared `UserContact`, `service_area_controller.js`, `dropzone_controller.js`, `char_count_controller.js` | `ArtisanSignupStepsTest` (17), `ArtisanSignupCompletionTest` (5), parcours navigateur complet (ClamAV et worker réels, E-20 reçu) | T-207b | ✅ |
| Complétude et couverture du profil pro | PR08.1, RG-011, RG-021, RG-042, RG-062 | `ProfileCompletion`, `ProfileCompletionCalculator`, `ProfileCompletionOf`, `RefreshProfileCompletion`, `ProfileContent` (`InterimProfileContent` jusqu'à T-309/T-702), `CoverageProjection` / `DbalCoverageProjection`, `app:artisan:rebuild-coverage` | `ArtisanStatusTest`, `ArtisanSignupCompletionTest` (projections, complétude), contrôle navigateur | T-208 | ✅ |
| Identifiants UUID v7 non énumérables | cadrage §5.2 | `AggregateId`, `UuidV7Generator` | `AggregateIdTest` | T-101 | ✅ |
| Règle de dépendance vérifiée | cadrage §4.1 | `deptrac.layers.yaml`, `deptrac.modules.yaml` | CI (sondes manuelles de violation : détectées) | T-103 | ✅ |

## Écrans

| Écran | Nœud Figma (page 10) | Contrôleur / composant | Voter | Tests fonctionnels | Breakpoints validés | Ticket | Statut |
|---|---|---|---|---|---|---|---|
| A04 Connexion | | | — | | ☐ 390 ☐ 768 ☐ 1440 | T-204 | ⬜ |
| PR04 Éditeur de devis | | | `QuoteVoter` | | ☐ 390 ☐ 768 ☐ 1440 | T-502 | ⬜ |
| … | | | | | | | |
