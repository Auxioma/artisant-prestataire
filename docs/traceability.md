# Matrice de traçabilité — CDC → code → tests

> Mise à jour à **chaque module livré** (Definition of Done). Une ligne par écran ou par règle RG. Une colonne vide signifie « non implémenté ».
> Statuts : ⬜ à faire · 🟨 en cours · ✅ livré et testé.

## Règles de gestion (annexe D)

| Réf. | Règle (résumé) | Module | Classes (Domain / Application) | Tests | Ticket | Statut |
|---|---|---|---|---|---|---|
| RG-014 | Aucune valeur métier en dur | Shared / Administration | `Shared\Application\Settings\{SettingKey, SettingDefinition, SettingsProvider, TypedSettingsProvider}`, `Administration\Application\Command\ChangeSettingHandler`, `Administration\Infrastructure\Settings\{DbalSettingsProvider, DbalSettingStore}`, règle PHPStan `tools/phpstan/NoBusinessLiteralRule` | `SettingKeyTest`, `SettingDefinitionTest`, `ChangeSettingHandlerTest`, `SettingsPersistenceTest` | T-105 | ✅ |
| RG-041 | SIRET obligatoire, valide, actif, unique | ArtisanProfile | | | T-207 | ⬜ |
| RG-043 | Statuts du compte pro | ArtisanProfile | | | T-208 | ⬜ |
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
| Identifiants UUID v7 non énumérables | cadrage §5.2 | `AggregateId`, `UuidV7Generator` | `AggregateIdTest` | T-101 | ✅ |
| Règle de dépendance vérifiée | cadrage §4.1 | `deptrac.layers.yaml`, `deptrac.modules.yaml` | CI (sondes manuelles de violation : détectées) | T-103 | ✅ |

## Écrans

| Écran | Nœud Figma (page 10) | Contrôleur / composant | Voter | Tests fonctionnels | Breakpoints validés | Ticket | Statut |
|---|---|---|---|---|---|---|---|
| A04 Connexion | | | — | | ☐ 390 ☐ 768 ☐ 1440 | T-204 | ⬜ |
| PR04 Éditeur de devis | | | `QuoteVoter` | | ☐ 390 ☐ 768 ☐ 1440 | T-502 | ⬜ |
| … | | | | | | | |
