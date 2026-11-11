# ADR-0004 — PostGIS pour la géographie, Meilisearch pour l'autocomplétion et le plein texte

**Statut** : Proposé · **Date** : 2026-11-11

## Contexte
- RG-021 : un résultat doit être à une distance compatible avec le rayon **ET** la commune doit appartenir à la zone d'intervention de l'artisan (mode rayon ou liste de communes/départements).
- RG-010 : une saisie libre non reconnue est traitée en plein texte. L'autocomplétion des métiers, synonymes et communes démarre dès 2 caractères (debounce 200 ms).
- RG-020 : la pertinence est un score pondéré administrable (métier, distance, note, réactivité, complétude, disponibilité).
- §8 : TTFB < 600 ms, cache des recherches fréquentes.

## Décision
- **PostGIS est la source de vérité géographique** : `geography(Point)` pour les centroïdes de communes (RG-022 : jamais l'adresse exacte de l'artisan), `ST_DWithin` indexé GiST, zones d'intervention matérialisées dans `artisan.service_area_commune` (liste INSEE pré-calculée à chaque modification de zone, RG-042 effet immédiat).
- La **recherche d'artisans** (P02) est une requête SQL paramétrée sur une **projection** `search.artisan_search_view` (vue matérialisée rafraîchie par événements). Elle calcule le score RG-020 avec les poids `search.weights` passés en paramètres, et la note bayésienne RG-012/RG-024.
- **Meilisearch** sert à l'autocomplétion (métiers + synonymes, communes + codes postaux) et au plein texte (RG-010 : noms d'entreprises, descriptions, blog/FAQ). Les identifiants trouvés sont ensuite filtrés et ordonnés par PostGIS.
- Indexation Meilisearch asynchrone (transport `search_index`). Clé API de recherche limitée au serveur (aucune clé exposée au navigateur, l'autocomplétion passe par un endpoint Symfony soumis au rate limit).

## Conséquences
- (+) Exactitude géo et transactionnelle, aucune désynchronisation sur l'éligibilité (diffusion RG-062 en SQL).
- (−) Deux moteurs à maintenir. Meilisearch reste secondaire : en cas de panne, on retombe sur `pg_trgm` + `unaccent` (mode dégradé).

## Alternatives écartées
- Meilisearch `_geoRadius` seul : il ne gère pas les zones par liste de communes ni l'intersection RG-021, et il introduit une incohérence éventuelle avec la base.
- Elasticsearch : surdimensionné pour le MVP.
