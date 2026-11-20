# Procédure de gestion des incidents de sécurité et des violations de données

> TrouveMoi.com — Auxioma. Établie le 20/11/2026 (T-905c, `SECURITY.md` §7, RGPD art. 33 et 34). Les noms et
> numéros de l'équipe d'astreinte sont à compléter par le porteur avant la mise en ligne ; les commandes et réglages
> cités existent dans l'application.

## 1. Rôles

| Rôle | Personne | Responsabilités |
|---|---|---|
| Responsable de l'incident | *à désigner* (direction Auxioma) | Décide, informe la direction, signe la notification CNIL |
| Responsable technique | *à désigner* | Qualifie, confine, corrige, préserve les preuves |
| DPO (si désigné) ou référent RGPD | *à désigner* | Évalue le risque pour les personnes, rédige la notification |
| Support | *à désigner* | Répond aux utilisateurs, centralise les signalements |

Contacts externes : hébergeur (support et astreinte), Stripe, prestataire d'e-mails, prestataire de test d'intrusion,
CNIL (téléservice de notification), dépôt de plainte (police, gendarmerie ou plateforme Pharos selon le cas).

## 2. Détection

- Supervision : erreurs applicatives, indisponibilité, files de messages bloquées (T-906).
- **Intégrité du journal d'audit** : `bin/console app:audit:verify` (quotidien par la supervision ; code 1 = journal
  altéré).
- Back-office, `/admin/logs` : connexions (échecs répétés, nouveaux appareils), actions d'audit, webhooks Stripe,
  exports.
- Rapprochement Stripe ↔ registre des crédits : `bin/console app:billing:reconcile` (écart = anomalie à qualifier).
- Signalements : `security.txt` (`SECURITY_CONTACT`), formulaire de contact, signalements de contenus, Stripe.

Toute personne qui constate un incident prévient **immédiatement** le responsable technique ; l'heure de prise de
connaissance fait courir le délai de 72 h.

## 3. Qualification (dans l'heure)

1. **Que s'est-il passé ?** Atteinte à la **confidentialité** (accès ou fuite), à l'**intégrité** (modification) ou à la
   **disponibilité** (perte, chiffrement, panne) ?
2. **Des données personnelles sont-elles concernées ?** Si oui, c'est une **violation de données** (RGPD art. 4.12) :
   elle est inscrite au registre (§7), qu'elle soit notifiée ou non.
3. **Quelles données, combien de personnes ?** Attention particulière aux justificatifs des professionnels (pièces
   d'identité), aux coordonnées des clients, aux messages, aux comptes du back-office.
4. **Gravité** : faible (aucun risque probable pour les personnes), risque (notification CNIL), risque élevé
   (notification CNIL et information des personnes).

## 4. Confinement (leviers disponibles)

| Situation | Action |
|---|---|
| Attaque en cours sur le site public | Mode maintenance : BO13 « Maintenance » (`site.maintenance`) — le site répond 503, le back-office reste accessible ; blocage d'adresses au CDN |
| Compte utilisateur compromis | Suspension du compte (BO02, motif obligatoire : connexion refusée, sessions fermées), puis réinitialisation du mot de passe par l'utilisateur, qui peut aussi révoquer ses appareils depuis son espace « Sécurité » |
| Compte du back-office compromis | Désactivation du membre (BO14), réinitialisation de la double authentification, revue de ses actions dans `/admin/logs` |
| Sessions à invalider pour tous | Vider le stockage des sessions ; changer `APP_SECRET` invalide aussi les liens signés et les cookies « se souvenir de moi » |
| Secret divulgué | Rotation dans le coffre (`secrets:set`) puis redéploiement : clés Stripe (« roll » dans le tableau de bord), secret de webhook, clés Meilisearch, Sirene, Turnstile, mots de passe PostgreSQL (`ops/postgres/roles.sql`) |
| Clé de chiffrement des fichiers exposée | Ajouter une nouvelle KEK courante dans `STORAGE_KEKS` (rotation sans rechiffrer : ADR-0008), puis ré-envelopper les clés de fichiers ; si les fichiers chiffrés ont aussi fui, traiter comme une fuite de justificatifs |
| Paiements ou crédits anormaux | Suspendre les forfaits concernés (BO08), `app:billing:reconcile`, contacter Stripe |

## 5. Préservation des preuves

Avant toute correction destructive : instantané de la base et du stockage, copie des journaux (application, serveur
web, CDN, hébergeur), résultat de `app:audit:verify`, export des journaux du back-office. Noter chaque action avec
l'heure et son auteur. Ne pas éteindre une machine compromise sans l'accord de l'hébergeur (mémoire, journaux).

## 6. Notification et information

| Destinataire | Quand | Comment |
|---|---|---|
| **CNIL** (art. 33) | Dans les **72 h** suivant la prise de connaissance, sauf si la violation n'engendre pas de risque pour les personnes ; notification en plusieurs temps possible si l'enquête est en cours | Téléservice de notification de violations de la CNIL : nature, catégories et nombre approximatif de personnes et d'enregistrements, contact, conséquences probables, mesures prises |
| **Personnes concernées** (art. 34) | Dans les meilleurs délais si le risque est **élevé** (ex. pièces d'identité, coordonnées en masse, accès aux messages) | E-mail en langage clair : ce qui s'est passé, données concernées, conséquences, mesures prises, conseils (changer son mot de passe, vigilance face à l'hameçonnage), contact |
| Sous-traitants / responsables conjoints | Sans délai si leurs données ou systèmes sont concernés | Selon les DPA |
| Autorités judiciaires | En cas d'acte malveillant | Plainte |

Un sous-traitant qui subit une violation doit prévenir Auxioma sans délai (clause des DPA).

## 7. Registre des violations (art. 33.5)

Tenu par le DPO ou le référent RGPD, conservé 5 ans, pour **toute** violation, notifiée ou non :

| Champ | Contenu |
|---|---|
| Date et heure de survenue, de découverte | |
| Description | Nature, cause, systèmes |
| Données et personnes concernées | Catégories, nombre approximatif |
| Conséquences probables | |
| Mesures de confinement et de correction | |
| Notification CNIL | Date, numéro, ou motif de non-notification |
| Information des personnes | Date, moyen, ou motif de non-information |

## 8. Après l'incident

Retour d'expérience sous 15 jours : chronologie, cause racine, ce qui a fonctionné ou non, actions correctives avec
responsable et échéance ; mise à jour de `SECURITY.md`, de l'AIPD (`docs/aipd.md`) et de cette procédure. Exercice de
simulation une fois par an.
