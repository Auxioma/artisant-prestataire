# ADR-0014 — Détection et masquage des coordonnées (RG-080, RG-112, RG-022bis)

**Statut** : Proposé · **Date** : 2026-11-11

## Décision
- Service de domaine `ContactInfoDetector` (PHP pur) qui détecte téléphones FR/intl (y compris les chiffres en lettres, séparateurs et espacements), emails (`at`, `[at]`, `arobase`, `(point)`), URLs et domaines, handles de réseaux sociaux. Les motifs supplémentaires sont administrables (BO05.3) et passés en paramètre, jamais exécutés comme code : regex compilées avec limite de backtracking, timeout `pcre.backtrack_limit`.
- S'applique aux messages, aux descriptions de demande (RG-063 : bonus de score), aux champs texte des devis et aux réponses d'avis, **tant que le devis n'est pas accepté** (`messages.mask_contacts_before_accept`).
- Le message stocké contient la version **masquée** (`body`). L'**original** est chiffré (enveloppe, ADR-0008) dans `body_original_encrypted`, lisible seulement par la modération avec motif tracé (BO02.2, annexe E).
- Identité client masquée avant acceptation : prénom + commune (RG-112). Adresse exacte et téléphone révélés à l'artisan seulement après `QuoteAccepted` (RG-022bis), via une query dédiée qui vérifie l'état.
