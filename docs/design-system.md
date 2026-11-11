# Design system — fondations (T-108)

> Sources : bibliothèque Figma V2 « TM — Bibliothèque de composants V2 » (fichier `gFu3z6vBrvV1BEexiWib0K`, nœud `59:4`), audit V2.1 (nœud `150:2`), CDC §2.
> Les cadres « [DÉPRÉCIÉ] Foundations / Component Gallery » de la page `01 · Design System` et la page `99 · Archive` ne sont **pas** utilisés.
> Toute modification relève de RG-001. Écarts CDC ↔ Figma : P-05, P-24 à P-27 dans [points-en-suspens.md](decisions/points-en-suspens.md).

## Fichiers

| Fichier | Rôle |
|---|---|
| `assets/styles/tokens.css` | **Seul endroit où une couleur est écrite.** Primitives `--tm-*` (Figma), variables imposées par le CDC §2.3 (`--primary`…), rôles sémantiques (`--color-*`), typographie, espacements, rayons, ombres, mouvement, z-index |
| `assets/styles/fonts.css` | Poppins 400/500/600/700, WOFF2, sous-ensemble latin, `font-display: swap` (licence OFL : `assets/fonts/poppins/OFL.txt`) |
| `assets/styles/base.css` | Remise à zéro, styles de titres responsives, classes typographiques `.tm-*`, focus visible, lien d'évitement, conteneur 1200 px, grille 12 colonnes, titre de section à trait orange, icônes |
| `assets/icons/sprite.svg` | 39 icônes Lucide 0.460.0 (ISC), identifiants = noms Figma ; regénération : `php tools/icons/build-sprite.php` |
| `templates/components/Icon.html.twig` | `<twig:Icon name="search" size="sm" />` — décorative par défaut, `label="…"` pour une icône porteuse de sens, `trade` pour un picto métier orange |
| `/_dev/design-system` | Planche de contrôle visuel (environnement dev uniquement) |

## Règles d'usage

- Les composants consomment les **rôles sémantiques** (`--color-text`, `--color-danger-bg`…), jamais les primitives directement, sauf cas documenté.
- Bouton principal : `--gradient-orange` avec **texte `--primary`** (jamais blanc sur orange : 2,6:1). Bouton de recherche : `--gradient-blue` avec texte blanc.
- Texte orange : uniquement `--color-accent-text` (`#B84A00`). Texte vert : `--color-success-text` (`#007A47`). Les étoiles `--color-star` sont toujours doublées de la note chiffrée.
- Aucun texte sous 12 px. Cible tactile ≥ 44 px (`--control-height`). Champs de 48 px, rayon 8.
- Survol des cartes : `--shadow-hover` + `translateY(var(--hover-lift))` ; `--hover-lift` vaut 0 si l'utilisateur demande un mouvement réduit.

## Garde-fous automatisés (`tests/Unit/Design/DesignTokensTest.php`)

- **Contrastes WCAG 2.1 AA** calculés depuis `tokens.css` sur 21 paires texte/fond utilisées par les maquettes ; les paires interdites du CDC §2.4 (blanc sur orange, blanc sur vert) restent signalées comme non conformes.
- Variables obligatoires du CDC §2.3 présentes.
- Aucune taille de police sous 12 px.
- **Aucune couleur codée en dur** (hex, `rgb()`, `hsl()`) hors `tokens.css`, dans les CSS et les gabarits Twig.
- Chaque `<twig:Icon name="…">` utilisé existe dans le sprite.

## Composants Twig (T-109)

Composants anonymes dans `templates/components/`, styles dans `assets/styles/components/` (couche `@layer components`), comportements Stimulus dans `assets/controllers/`. Planche de contrôle : `/_dev/components` (dev uniquement).

| Composant | Figma | Usage | Accessibilité garantie (testée) |
|---|---|---|---|
| `<twig:Button>` | 59:142 | `variant` primary · search · secondary · tertiary · danger ; `href` → lien ; `icon`, `loading`, `disabled`, `block` | libellé conservé en chargement + `aria-busy` ; lien désactivé sans `href` + `aria-disabled` |
| `<twig:Field>` | 59:219 | champ hors formulaire Symfony (recherche…) ; `help`, `error`, `success`, `icon` | `label for`, `aria-describedby` vers l'aide ou l'erreur, `aria-invalid`, icône d'état |
| Thème `form/theme.html.twig` | 59:219, 93:1510 | **tous les formulaires Symfony** (enregistré globalement) | idem `Field` ; choix multiples en `fieldset` + `legend` ; aide toujours échappée (même `help_html`) |
| `<twig:Select>` | 93:1511 | liste native ; `prefix` (« Trier : ») ou `label` masqué | étiquette associée obligatoire |
| `<twig:Checkbox>` | 93:1510 | case ou radio (`type="radio"`) ; `locked` = toujours actif (P02.3) | zone cliquable ≥ 44 px ; valeur verrouillée soumise via champ caché |
| `<twig:Badge>` | 59:250 | verified · available · new · promo · featured · pending · refused · neutral · direct | toujours un texte (+ icône), jamais la couleur seule |
| `<twig:Toast>` / `<twig:ToastRegion>` | 63:72 | région dans `base.html.twig`, alimentée par les flashs `success/info/warning/error` | `aria-live`, `role="alert"` pour les erreurs ; fermeture auto 5 s (jamais pour une erreur), pause au survol/focus |
| `<twig:Modal>` | 74:121 | `<dialog>` natif, blocs `content` et `actions` ; ouverture via `modal-opener` | focus piégé, Échap, retour du focus au déclencheur, `aria-labelledby` ; bottom sheet < 768 px |
| `<twig:Stepper>` | 63:102 | `steps`, `current`, `errorStep` | `aria-current="step"`, état textuel masqué ; mobile « Étape n/N » + barre |
| `<twig:CreditGauge>` | 74:235 | `remaining`, `total`, `state` (normal · alert · critical · exhausted), `status`, `ctaHref` | anneau décoratif, information portée par le texte ; épuisement annoncé (`role="alert"`, RG-106) |
| `<twig:CardArtisan>` / `<twig:CardArtisanSkeleton>` | 66:32, 66:129 | carte verticale ; `favorite` (null = pas de bouton) | note chiffrée « 4,8 sur 5 » doublant les étoiles ; cœur `aria-pressed` 44 × 44 ; `alt` de la photo ; carte cliquable via le lien du nom |
| `<twig:EmptyState>` | 74:178 | `variant` empty · error, actions en contenu | erreur annoncée (`role="alert"`) |
| `<twig:Skeleton>` | 66:129 | lignes de chargement | décoratif ; pulsation coupée en mouvement réduit |

L'**état de la jauge** n'est pas calculé dans le gabarit : l'application le déduit des seuils administrables (`credits.alert_thresholds`, `credits.plan_alert_pct`, RG-105) et le transmet.
La **carte artisan horizontale** (résultats P02) et la variante **compacte** seront réalisées avec la page de résultats (T-302).

## Mise en page commune (T-110)

- `base.html.twig` rend par défaut `<twig:Header variant="{{ header_variant ?? 'visitor' }}" active="{{ active_nav ?? null }}">` et `<twig:Footer />` ; une page surcharge le bloc `header` pour fournir les compteurs (`unreadMessages`, `unreadNotifications`) et, pour l'artisan, `credits` `{remaining, total, state}` (état calculé par l'application, RG-105).
- **Feuilles de styles** : chargées par des balises `<link>` dans `base.html.twig`, **jamais importées depuis JavaScript** (AssetMapper les transformerait en modules `data:` bloqués par la CSP, ce qui empêcherait Stimulus de démarrer — test `LayoutTest::testImportmapContainsNoDataUrlModule`).
- Navigation : motif *disclosure* (`disclosure` : Échap, clic extérieur, un seul menu ouvert), menu mobile en `<dialog>` (focus piégé, retour au burger), en-tête collant réduit à 64 px au défilement (`header`).
- `tm_path('route')` renvoie `#` tant que la page cible n'existe pas (P-32) ; `tm_setting_int()` pour toute valeur métier affichée (ex. « 100 devis offerts ») ; `tm_social_links()` n'affiche que les réseaux renseignés en BO13 (URL HTTPS sur le domaine du réseau).
- Planche : `/_dev/layout?variant=visitor|client|artisan|tunnel&credits=72|14|0`.

## Consentement cookies (T-111)

- `<twig:CookieConsent />` est inclus dans `base.html.twig` juste après le lien d'évitement. Le bandeau est rendu masqué ; `consent_controller` l'affiche s'il n'existe pas de cookie `tm_consent` valide (version = `cookies.policy_version`, âge < `cookies.consent_months`).
- **Charger un traceur** : ne jamais l'insérer directement. Utiliser un espace réservé, activé seulement après consentement :
  `<script type="text/plain" data-consent-category="audience" data-src="https://…" nonce="{{ csp_nonce('script') }}"></script>` (catégories : `audience`, `social`, `marketing` ; ajouter le domaine à la CSP).
- **Événements analytics** : `import { track } from '../lib/analytics.js'; track('search_submitted', {…})` — nom en snake_case, ignoré sans consentement « mesure d'audience ».
- Rouvrir les préférences : tout élément portant `data-cookie-consent-open` (ex. « Gérer mes cookies » du pied de page).
- Incrémenter `cookies.policy_version` en BO redemande le consentement à tous les visiteurs.
