# Accessibilité — RGAA 4.1 (CDC §9)

## 1. Audit outillé (T-903a, 20/11/2026)

### Méthode

Contrôles automatisables du RGAA exécutés dans Chrome sans interface sur les pages rendues
(`tools/a11y/rgaa-checks.mjs`, piloté par `tools/a11y/cdp.mjs`, sans bibliothèque tierce) :

| Critère RGAA | Contrôle |
|---|---|
| 1.1 | Image sans attribut `alt` ; SVG ni décoratif (`aria-hidden`) ni nommé |
| 3.2 | Contraste du texte sur son fond opaque (4,5:1, 3:1 pour le grand texte) |
| 6.2 | Lien sans intitulé |
| 8.2 | Identifiant dupliqué |
| 8.3 / 8.5 | Langue de la page, titre de la page |
| 9.1 | Un seul `h1`, pas de saut de niveau de titre |
| 11.1 | Champ de formulaire sans étiquette |
| 11.9 | Bouton sans intitulé |
| 12.6 | Zone principale (`main`) |

Lancement (hôte avec Google Chrome) : `PAGES="/,/connexion" node tools/a11y/cdp.mjs tools/a11y/rgaa-checks.mjs`
(application sur `http://localhost:8090`). Les pages connectées sont rendues par un test puis ouvertes en fichier
statique.

### Périmètre (49 pages)

- **Public (17)** : accueil, annuaire, catégories, catégorie, métier, fiche artisan, tunnel de demande, connexion,
  inscription (choix, client, pro), mot de passe oublié, aide, contact, « Comment ça marche ? », tarifs, confidentialité.
- **Espace client (8)** : tableau de bord, demandes, comparateur et choix confirmé, avis, notifications, messages.
- **Espace pro (9)** : tableau de bord, demandes, demande, éditeur de devis, devis, profil, abonnement, agenda, avis.
- **Back-office (14)** : connexion, tableau de bord, clients, artisans, file de vérification, demandes, modération,
  forfaits, CMS, communications, SEO, paramètres, équipe, journaux.

### Résultats et corrections

| Page | Constat | Critère | Correction |
|---|---|---|---|
| Accueil | Titre et sous-titre blancs sur la photo : contraste insuffisant sur les zones claires (voile réglable jusqu'à 0 % dans le CMS) | 3.2 | Dégradé marine derrière le texte, indépendant du voile (P-151) |
| Annuaire | Deux `h1` (bannière + section) et saut `h1` → `h3` sur les cartes | 9.1 | Titre de section en `h2` |
| Mes avis (client) | Aucun `h1` | 9.1 | `h1` « Mes avis » (masqué visuellement, comme les autres pages de l'espace) |
| Suivi du rendez-vous | Numéro d'étape blanc sur orange (2,6:1) | 3.2 | Numéro en marine sur orange (comme les boutons primaires) |

Écartés après vérification (faux positifs de l'outil) : texte réservé aux lecteurs d'écran (« Non lue »,
`tm-visually-hidden`) ; icône nommée par `aria-label` sur le SVG (bouton « Utiliser ma position »).

Résultat après corrections : **aucun constat restant** sur les 49 pages, hors faux positifs ci-dessus.

### Limites

Un contrôle automatique couvre une partie seulement du RGAA : voir l'audit manuel ci-dessous.

## 2. Audit manuel assisté (T-903b, 20/11/2026)

### Méthode

`tools/a11y/rgaa-assist.mjs` (même pilote Chrome) :

| Critère RGAA | Contrôle |
|---|---|
| 10.7 | 40 appuis réels sur Tab (focus « clavier ») ; chaque élément atteint est comparé à son aspect une fois quitté, sur lui-même, son parent et son grand-parent (indicateur porté par `:focus-within`) |
| 12.7 | Le premier Tab atteint le lien d'évitement « Aller au contenu » |
| 10.11 | Pas de défilement horizontal dans une fenêtre de 320 px |
| 10.4 | Pas de défilement horizontal dans une fenêtre de 640 px (zoom 200 % d'un écran de 1 280 px) |

Lancement : `PAGES="/,/connexion" node tools/a11y/cdp.mjs tools/a11y/rgaa-assist.mjs` (rapport
`tools/a11y/rapport-assist.json`, non suivi). Pages : parcours publics (accueil, annuaire, catégories, fiche artisan,
tunnel de demande, connexion, inscriptions, aide, contact, tarifs, documents légaux, 404, déclaration).

### Résultats et corrections

| Page | Constat | Critère | Correction |
|---|---|---|---|
| Connexion, contact, inscriptions | Champs à largeur minimale intrinsèque : la page déborde à 320 px | 10.11 | `.tm-field` en grille `minmax(0, 1fr)`, champ `min-width: 0` ; grilles des formulaires d'inscription idem |
| Tunnel de demande, inscription pro | En-tête (logo + « Aide / FAQ » + « Quitter ») trop large à 320 px | 10.11 | Logo réduit sous 360 px, écart réduit sous 1 024 px |
| Page 404 | Grille centrée qui déborde à 320 px | 10.11 | `minmax(0, 1fr)` |
| Déclaration d'accessibilité | Bouton à libellé long sur une ligne | 10.11 | Libellé raccourci |

Focus visible (10.7) et lien d'évitement (12.7) : conformes sur les pages contrôlées, hors cas ci-dessous.

### Écarts acceptés (P-153)

- **Cloudflare Turnstile** (contenu tiers) : largeur minimale de 300 px, éléments internes sans indicateur de focus
  propre ; déclaré dans les contenus tiers.
- **/connexion** : le champ e-mail reçoit le focus au chargement, donc le premier Tab ne mène pas au lien d'évitement
  (accessible par Maj+Tab) ; conservé, la page n'ayant qu'un objectif.

### Restant à vérifier (hors outil)

- Restitution avec NVDA (Windows, Firefox) et VoiceOver (macOS / iOS, Safari) : messages d'erreur des formulaires,
  messages de statut (`role="status"`), modales, menu des catégories, autocomplétion, accordéons.
- Pertinence des alternatives et des intitulés (lecture humaine).
- Audit complet des 106 critères par un tiers pour calculer un taux de conformité.

## 3. Déclaration d'accessibilité

Publiée sur `/accessibilite`, avec la mention « Accessibilité : non conforme » au pied de page (menu `footer_info`,
modifiable en BO10.5). L'état passera à « partiellement conforme » ou « totalement conforme » seulement après un audit
complet ; mettre alors à jour la page, le libellé du pied de page et la date d'établissement.
