# ADR-0012 — Front : Twig SSR, Symfony UX, AssetMapper, design tokens

**Statut** : Proposé · **Date** : 2026-11-11

## Décision
- **Rendu serveur Twig** pour toutes les pages, publiques et connectées (SEO §7, TTFB, simplicité). Interactivité via **Turbo** (Drive, Frames, Streams), **Stimulus** et **Live Components** (éditeur de devis, filtres de recherche, tunnel P11).
- **AssetMapper** (pas de Node en production). Une feuille de styles unique `assets/styles/app.css`, découpée en `@layer tokens, base, components, utilities`.
- **Tokens** : `assets/styles/tokens.css` reprend les variables du CDC §2.3. On y ajoute les tokens Figma (`01 · Design System`, extraits via `get_variable_defs`) et les **corrections de l'audit V2.1** : texte orange `#B84A00`, gris muted `#5B6B82`, taille minimale 12 px. Aucune couleur hors tokens (stylelint `color-no-hex` hors `tokens.css`).
- **Composants** `TwigComponent` (annexe B / bibliothèque Figma) : Button (5 états), Input (7 états), Select, Checkbox, Badge, Toast (`aria-live`), Modal (focus piégé, bottom sheet en mobile), CardArtisan (verticale/horizontale), Stepper, CreditGauge (4 états), Skeleton, EmptyState. Chaque composant a ses états *loading / empty / error / success*.
- **Poppins** auto-hébergée (WOFF2, sous-ensemble latin, `font-display: swap`, préchargement 400/600). **Lucide** en sprite SVG.
- **CSP stricte avec nonces** (nelmio) : pas d'`unsafe-inline`. Les contrôleurs Stimulus remplacent tout `onclick`. Les scripts tiers (Stripe.js, Turnstile) sont listés explicitement.
- Analytics : `dataLayer` alimenté **uniquement** après consentement CMP (catégorie mesure d'audience).

## Alternative écartée
SPA (React/Vue) : SEO et performance plus coûteux, deux bases de code.
