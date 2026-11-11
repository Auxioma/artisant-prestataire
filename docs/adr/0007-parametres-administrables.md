# ADR-0007 — Paramètres administrables historisés (RG-014, annexe G, BO13)

**Statut** : Proposé · **Date** : 2026-11-11

## Décision
- Port `Shared\Application\Settings\SettingsProvider` avec des accesseurs **typés** : `int(SettingKey)`, `bool()`, `intList()`, `decimal()`, `duration()`… `SettingKey` est un enum qui reprend toutes les clés de l'annexe G, avec type, défaut, bornes de validation et rôle autorisé à les modifier (BO13, annexe E).
- Stockage : `admin.setting` (valeur courante) et `admin.setting_history` (append-only : avant/après, `admin_id`, motif, date). Le cache applicatif (pool Redis) est invalidé par l'événement `SettingChanged`.
- **Défauts** : seedés par migration depuis l'annexe G, jamais codés dans les handlers. Les points en suspens (annexe I) sont tous des clés avec la recommandation du CDC comme valeur par défaut.
- **Valeurs figées** : quand une règle exige la valeur au moment T (par ex. `max_quotes` d'une demande, RG-070 / §4.2), la valeur est **copiée dans l'agrégat** à la création.
- Les clés sensibles (Stripe, SMTP) ne sont **pas** dans `setting` mais dans le coffre de secrets Symfony. Le BO n'affiche que leur présence et leur empreinte (super-admin).
- Garde-fou CI : une règle PHPStan personnalisée interdit les littéraux numériques dans les classes `*Policy` et `*Handler` des modules métier (liste d'exceptions documentée : 0, 1, -1).
