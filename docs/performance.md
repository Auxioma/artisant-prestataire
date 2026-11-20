# Performance — CDC §8

## Budgets (75e percentile mobile)

| Indicateur | Cible | Mesure de laboratoire |
|---|---|---|
| LCP | < 2,5 s | `largest-contentful-paint` |
| INP | < 200 ms | Temps de blocage (TBT, tâches longues > 50 ms) en indicateur de laboratoire ; INP réel à suivre sur le terrain après la mise en ligne |
| CLS | < 0,1 | `layout-shift` hors saisie |
| TTFB pages publiques | < 600 ms (cache CDN) | `responseStart` |
| Poids page d'accueil | < 1,5 Mo au premier chargement | octets transférés |

## Méthode (T-904a, 20/11/2026)

`tools/perf/web-vitals.mjs`, piloté par `tools/a11y/cdp.mjs` (Chrome sans interface sur l'hôte) : mobile émulé
(412 × 823, densité 2,625), processeur ralenti × 4, réseau « 4G lente » du profil Lighthouse (150 ms d'aller-retour,
1,6 Mbit/s), cache et cookies vidés, médiane de 3 passages.

L'application est mesurée **en mode prod** sur une copie locale servie en HTTPS (HTTP/2, comme en production) :
conteneur `trouvemoi-perf` créé depuis l'image de développement, copie du code sans fichiers `.env.*.local`,
`APP_ENV=prod`, `asset-map:compile`, `APP_SECRET` jetable généré dans le conteneur. Le développement (`:8090`) sert les
fichiers un par un sans compilation et ne donne pas de mesure représentative.

```sh
INSECURE=1 BASE=https://localhost:8443 PAGES="/,/nos-professionnels" node tools/a11y/cdp.mjs tools/perf/web-vitals.mjs
```

Copier le dépôt avec `COPYFILE_DISABLE=1 tar …` : sans cette variable, le `tar` de macOS ajoute des fichiers `._*`
qui cassent le chargement des mappings Doctrine.

## Résultats

11 pages publiques (accueil, annuaire, catégories, catégorie, tunnel de demande, connexion, inscription, aide,
« Comment ça marche ? », tarifs, accessibilité). CLS et TBT nuls partout ; TTFB = aller-retour émulé (150 ms).

| | Avant | Après |
|---|---|---|
| LCP (pages à bannière) | 2,2 s (2,9 à 3,7 s en HTTP/1.1) | **1,5 s** |
| LCP (autres pages) | 1,4 à 2,0 s | **1,2 à 1,7 s** |
| Poids transféré | 285 à 362 Ko | **208 à 253 Ko** |
| Requêtes | 85 à 87 | **56 à 60** |

## Corrections

| Constat | Correction |
|---|---|
| Photo de bannière (élément LCP) en WebP 1 440 px seulement, sur mobile comme sur ordinateur | `<picture>` AVIF puis WebP, 960 et 1 440 px (`srcset` / `sizes`), priorité haute ; préchargement AVIF sur l'accueil (CDC P01 : préchargée, sans lazy-load) |
| Logo @3x (37 Ko) sur chaque page | AVIF @2x / @3x (16 Ko), WebP en repli |
| 46 modules JavaScript chargés sur chaque page, dont l'éditeur de devis, le tunnel ou l'agenda | Contrôleurs Stimulus chargés à la demande (`stimulusFetch: 'lazy'`), sauf ceux du gabarit commun (en-tête, pied de page, cookies, modales, mesure d'audience) ; composant Live également à la demande |
| Fichiers compilés servis sans durée de cache | `docker/frankenphp/Caddyfile` (image prod) : `Cache-Control: public, max-age=31536000, immutable` sur les fichiers existants de `/assets/` (nom = empreinte du contenu) |

Compression déjà active (zstd, br, gzip) ; polices WOFF2 en sous-ensemble latin, 400 et 600 préchargées.

## Cache des pages publiques (T-904b)

`PublicPageCacheSubscriber` : pour un visiteur **sans cookie de session** (`tm_sess`), les pages publiques sans donnée
personnelle (accueil, recherche, annuaire, catégories, métiers, villes, fiches artisans, aide, « Comment ça marche ? »,
tarifs, pages CMS, documents légaux, accessibilité) répondent
`Cache-Control: max-age=0, public, s-maxage=300` et `Vary: Cookie`. Le navigateur revalide toujours ; seul un cache
partagé (CDN) garde la page, 300 s par défaut (`site.public_cache_seconds`, BO13 « Maintenance », 0 = désactivé).

Restent privées : toute requête avec cookie de session (connecté, connexion assistée), toute réponse qui dépose un
cookie ou n'est pas un 200, les formulaires (connexion, inscription, contact, tunnel), l'espace client / pro, le
back-office, les API.

### Réglages du CDN (à la mise en place, T-906)

- Respecter `s-maxage` ; **ne pas mettre en cache** une requête qui porte le cookie `tm_sess` (règle de contournement),
  et ne pas dériver la clé de cache des autres cookies (`tm_consent`, `tm_cid`, `tm_demande`) : remplacer
  `Vary: Cookie` par cette règle, sinon chaque combinaison de cookies crée une entrée.
- **Purger le cache HTML à chaque mise en production** : une page en cache référence les fichiers compilés de la
  version précédente, supprimés par la nouvelle image.
- Les modifications du CMS, des paramètres ou des fiches apparaissent aux visiteurs anonymes au plus tard après
  `site.public_cache_seconds`.
- Fichiers compilés `/assets/*` : cache d'un an (`immutable`), sans purge nécessaire.

### Images hors écran

Déjà en place : cartes artisans, réalisations et photos d'avis en `loading="lazy"`, composant `Media` (médiathèque :
AVIF / WebP 480 / 960 / 1 600 px, `loading="lazy"` par défaut, dimensions intrinsèques). Les images au-dessus de la
ligne de flottaison (bannières, logo) restent chargées d'emblée.

## Suite

- Après le choix de l'hébergeur : CDN (règles ci-dessus), puis mesures terrain (CrUX ou mesure réelle) pour l'INP et
  le 75e percentile réel, et TTFB servi depuis le CDN.
