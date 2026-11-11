# Matrice de traçabilité — CDC → code → tests

> Mise à jour à **chaque module livré** (Definition of Done). Une ligne par écran ou par règle RG. Une colonne vide signifie « non implémenté ».
> Statuts : ⬜ à faire · 🟨 en cours · ✅ livré et testé.

## Règles de gestion (annexe D)

| Réf. | Règle (résumé) | Module | Classes (Domain / Application) | Tests | Ticket | Statut |
|---|---|---|---|---|---|---|
| RG-014 | Aucune valeur métier en dur | Shared / Administration | `SettingsProvider`, `SettingKey` | | T-105 | ⬜ |
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
| … | *(toutes les RG de l'annexe D sont ajoutées au fil des lots)* | | | | | |

## Écrans

| Écran | Nœud Figma (page 10) | Contrôleur / composant | Voter | Tests fonctionnels | Breakpoints validés | Ticket | Statut |
|---|---|---|---|---|---|---|---|
| A04 Connexion | | | — | | ☐ 390 ☐ 768 ☐ 1440 | T-204 | ⬜ |
| PR04 Éditeur de devis | | | `QuoteVoter` | | ☐ 390 ☐ 768 ☐ 1440 | T-502 | ⬜ |
| … | | | | | | | |
