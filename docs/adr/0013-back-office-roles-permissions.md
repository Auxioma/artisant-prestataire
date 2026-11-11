# ADR-0013 — Back-office isolé, 2FA obligatoire, permissions fines

**Statut** : Proposé · **Date** : 2026-11-11

## Contexte
Le cadrage fixe les rôles `ROLE_SUPER_ADMIN`, `ROLE_MODERATOR`, `ROLE_SUPPORT`, `ROLE_CONTENT`, `ROLE_FINANCE`. Le CDC BO14 ajoute « Lecture seule » et des **rôles personnalisés** avec une matrice module × action (voir, créer, modifier, supprimer, exporter).

## Décision
- **Firewall `admin`** distinct, avec `host: admin.trouvemoi.com` et un fournisseur d'utilisateurs dédié (table `admin.admin_user`, séparée des comptes clients/artisans). Cookie de session distinct (`__Host-tm_admin`, `SameSite=Strict`), timeout d'inactivité `admin.idle_timeout_min` (défaut 15), liste blanche IP optionnelle (`admin.ip_allowlist`).
- **2FA TOTP obligatoire** (scheb/2fa-bundle), avec codes de secours hachés et trusted device désactivé.
- **Permissions** : les rôles du cadrage sont des **presets** (+ `ROLE_READONLY`) qui accordent des permissions `admin.<module>.<action>`. Des rôles personnalisés sont possibles (BO14). Les Voters admin vérifient des **permissions**, pas des rôles. Les **plafonds** de gestes commerciaux sont portés par le rôle (`max_manual_credits`), vérifiés dans le domaine Billing.
- **Ré-authentification** (mot de passe + TOTP de moins de 5 min) pour : remboursement, rôles, clés Stripe, impersonation, exports PII, actions de masse sur les crédits.
- **Impersonation** : super-admin uniquement, `switch_user` restreint par un Voter, bandeau persistant, AuditLog début/fin. Les **actions financières** (souscription, résiliation, moyen de paiement) sont **bloquées** par un Voter qui détecte `IS_IMPERSONATOR`.

## Point ouvert
Le cadrage et le CDC divergent sur les rôles personnalisés : voir P-06 dans `points-en-suspens.md`. Par défaut, presets seuls au MVP et rôles personnalisés en option.
