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

Un contrôle automatique couvre une partie seulement du RGAA. Restent à vérifier manuellement (T-903b) : navigation et
ordre au clavier, visibilité du focus, pertinence des alternatives et des intitulés, messages d'erreur et de statut
(lecteur d'écran), zoom à 200 % et reformatage à 320 px, contenus en mouvement, compatibilité NVDA / VoiceOver, puis
rédaction de la déclaration d'accessibilité.
