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
