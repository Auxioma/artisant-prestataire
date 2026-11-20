# Analyse d'impact relative à la protection des données (AIPD) — TrouveMoi.com

> **Projet** établi par l'équipe de développement le 20/11/2026 (T-905b), selon la méthode PIA de la CNIL.
> À relire, compléter et **valider par le responsable de traitement** (Auxioma) et, le cas échéant, son délégué à la
> protection des données (DPO), avant la mise en ligne. Les points à arbitrer sont regroupés en §6.

## 1. Pourquoi une AIPD

Le CDC (§11) la recommande pour la vérification d'identité des professionnels et la modération automatisée. Elle est
**obligatoire** ici : le traitement remplit au moins deux des neuf critères du CEPD (lignes directrices WP248) :

| Critère | Présent | Où |
|---|---|---|
| Évaluation ou notation | Oui | Score de risque des demandes, avis et messages (mise en modération) ; classement des professionnels dans la recherche |
| Décision avec effet juridique ou similaire | Oui, avec intervention humaine | Refus ou suspension d'un compte professionnel (accès au service et à une source de revenus) |
| Données hautement personnelles | Oui | Pièces d'identité, justificatifs d'entreprise et d'assurance, contenu des messages |
| Croisement d'ensembles de données | Oui | SIRET / TVA vérifiés auprès de l'INSEE (Sirene) et de la Commission européenne (VIES) |
| Grande échelle | Probable à terme | Plateforme nationale ouverte au public |
| Surveillance systématique, personnes vulnérables, technologie innovante, blocage d'un droit | Non | — |

## 2. Contexte

### 2.1 Traitement et responsabilités

- **Responsable de traitement** : Auxioma, éditeur de TrouveMoi.com (plateforme de mise en relation entre
  particuliers / entreprises et artisans vérifiés, opérateur de plateforme en ligne au sens de l'article L111-7 du
  Code de la consommation).
- **Finalités** : mise en relation (demandes de devis, devis, messagerie, rendez-vous, avis) ; vérification des
  professionnels ; abonnements des professionnels ; modération et lutte contre la fraude ; support ; sécurité ; mesure
  d'audience (avec consentement).

### 2.2 Données traitées

| Personnes | Données | Source |
|---|---|---|
| Clients (particuliers, entreprises) | Nom, prénom, e-mail, téléphone (facultatif), commune / code postal, SIRET facultatif, photo de profil facultative ; demandes (description, photos, adresse d'intervention), messages et pièces jointes, rendez-vous, avis et photos, favoris, notifications | La personne |
| Professionnels | Identité du dirigeant, e-mail, téléphone mobile, entreprise (raison sociale, SIRET, TVA, adresse), métiers, zone d'intervention, **justificatifs** (Kbis ou avis INSEE, assurances RC pro / décennale, **pièce d'identité**), logo, réalisations, disponibilités, devis, abonnement et factures | La personne ; INSEE Sirene, VIES (vérification) ; Stripe (paiement) |
| Membres du back-office | Identité, e-mail professionnel, rôle, secret TOTP (chiffré), codes de secours (hachés), actions journalisées | Invitation par un administrateur |
| Visiteurs | Adresse IP (limites de débit, journaux techniques), choix de cookies, dernier lieu de recherche (stockage local du navigateur) | Navigation |

Aucune donnée de santé, d'opinion ou de condamnation n'est demandée. Les champs libres (demandes, messages, avis)
peuvent en contenir à l'initiative des personnes.

### 2.3 Destinataires et sous-traitants

| Destinataire | Rôle | Localisation | Encadrement |
|---|---|---|---|
| Hébergeur (application, base, stockage objet, sauvegardes) | Sous-traitant | **UE, à choisir** | DPA à signer |
| Stripe | Paiement des abonnements, factures | UE / États-Unis | DPA Stripe, Data Privacy Framework + clauses types |
| Cloudflare Turnstile | Contrôle anti-robot (formulaires) | UE / États-Unis | DPA Cloudflare, Data Privacy Framework |
| Prestataire d'e-mails (Brevo proposé, P-134) | E-mails transactionnels, newsletter | France | DPA à signer |
| SMS | Non activé au MVP | — | — |
| INSEE (Sirene), Commission européenne (VIES), geo.api.gouv.fr, IGN Géoplateforme | Vérification d'entreprise, géographie | France / UE | Services publics ; seuls SIRET, TVA, commune ou adresse sont transmis |
| Have I Been Pwned (mots de passe compromis) | Vérification d'un mot de passe | Hors UE | Seuls les 5 premiers caractères de l'empreinte SHA-1 sont envoyés (k-anonymat) : aucune donnée personnelle |
| Professionnels / clients | Coordonnées échangées après acceptation d'un devis ou révélation du téléphone | — | Règles de masquage (ADR-0014) |

La mesure d'audience n'est pas encore raccordée à un outil (événements préparés, envoyés seulement après
consentement) : son choix sera ajouté à cette analyse.

### 2.4 Cycle de vie et durées (CDC §11, paramètres BO13 « RGPD »)

| Donnée | Durée appliquée | Mise en œuvre |
|---|---|---|
| Compte actif | Durée de la relation ; 3 ans d'inactivité → relance (E-09b) puis anonymisation | Tâche quotidienne (T-808b1) |
| Suppression demandée | 14 jours de grâce, puis anonymisation ; avis conservés anonymisés (RG-090) | T-705d2 |
| Demandes, devis | 3 ans après la dernière activité ; brouillons 30 jours | Purges planifiées (T-808a) |
| Messages | 3 ans | Purge planifiée |
| Justificatifs | Durée de la relation + 1 an ; **pièce d'identité supprimée dès la vérification** (proposé, à valider : §6) | T-808b2 |
| Factures | 10 ans (obligation comptable) | Archive des factures Stripe |
| Journaux de connexion et d'audit | 12 mois | Purge chaînée (ancre conservée) |
| Consentement cookies | 6 mois (traceurs 13 mois maximum) | CMP |
| Prospects newsletter | 3 ans après le dernier contact | À la mise en place de la newsletter (T-802c) |
| Sauvegardes | 30 jours, chiffrées | T-906 |

## 3. Principes fondamentaux

| Principe | Mesures |
|---|---|
| Finalités déterminées et légitimes | Listées en §2.1 ; aucune revente ni publicité ciblée |
| Bases légales | Contrat (compte, mise en relation, abonnement) ; obligation légale (factures, L111-7) ; intérêt légitime (vérification des professionnels, modération, anti-fraude, sécurité) ; consentement (cookies de mesure, newsletter) |
| Minimisation | Téléphone et SIRET facultatifs côté client ; adresse exacte limitée à la demande ; e-mail et téléphone masqués dans les messages avant acceptation ; EXIF retirés des images ; aucune pièce d'identité demandée aux clients |
| Exactitude | Rectification en libre-service (C07, profil pro), vérification Sirene / VIES |
| Durées limitées | §2.4, purges automatiques et paramétrables |
| Information | Politique de confidentialité versionnée, mentions à chaque formulaire, bandeau cookies conforme CNIL (« Tout refuser » au même niveau) |
| Droits | Accès et portabilité (archive en libre-service : JSON et PDF), rectification, effacement (avec délai de grâce), opposition à la newsletter, retrait du consentement cookies à tout moment ; demandes par le formulaire de contact |
| Sous-traitance | DPA avec chaque sous-traitant (§2.3), à signer avant la mise en ligne |
| Transferts hors UE | Limités à Stripe et Cloudflare, encadrés (§2.3) ; hébergement en UE |
| Décisions automatisées (art. 22) | Aucune décision produisant des effets juridiques n'est prise sans intervention humaine : le score de risque met un contenu **en file de modération**, un membre du back-office décide ; la validation, le refus ou la suspension d'un professionnel sont décidés par une personne (BO06), motivés et contestables |

## 4. Risques

Échelle CNIL : 1 négligeable, 2 limitée, 3 importante, 4 maximale. Gravité et vraisemblance **après** les mesures.

### 4.1 Vérification des professionnels (justificatifs, pièce d'identité)

| Événement redouté | Sources de risque | Mesures | Gravité | Vraisemblance |
|---|---|---|---|---|
| Accès illégitime aux justificatifs (usurpation d'identité, fraude) | Attaquant externe, membre du back-office indélicat, fuite du stockage ou d'une sauvegarde | Chiffrement par fichier (XChaCha20-Poly1305, clé de fichier enveloppée par une clé maître versionnée hors base, ADR-0008) ; stockage objet privé ; lecture par contrôleur avec **journalisation de chaque consultation** (qui, quoi, quand, IP) ; accès limité aux rôles de vérification (BO14) avec 2FA obligatoire ; analyse antivirus ; pièce d'identité supprimée dès la vérification (proposé) | 3 | 1 |
| Modification non désirée (faux justificatif accepté, décision altérée) | Fraudeur, erreur humaine | Vérification Sirene / VIES ; décisions de vérification en **ajout seul** (déclencheur base de données) et journal d'audit chaîné ; motifs de refus normalisés ; alertes d'expiration des assurances | 2 | 2 |
| Disparition (perte des pièces, impossibilité de prouver la vérification) | Panne, erreur d'exploitation | Sauvegardes chiffrées quotidiennes et test de restauration (T-906) ; trace de la décision conservée après destruction du fichier | 2 | 1 |

### 4.2 Modération et anti-fraude automatisées

| Événement redouté | Sources de risque | Mesures | Gravité | Vraisemblance |
|---|---|---|---|---|
| Atteinte injustifiée (contenu bloqué, professionnel pénalisé à tort) | Faux positif du score, termes interdits mal choisis | Score **sans effet définitif** : mise en file pour un humain ; seuils et poids paramétrables et journalisés (BO05.3) ; relecture humaine d'un échantillon des profils ; motif enregistré ; contestation par le support | 2 | 2 |
| Opacité du classement des professionnels | Pondérations non expliquées | Critères et pondérations publiés (« Comment sont classés les professionnels ? », L111-7), identiques à ceux de la recherche | 2 | 1 |
| Détournement de la modération pour lire des messages privés | Membre du back-office | Seuls les messages **signalés** (par un utilisateur ou par le score) sont accessibles en modération, l'original masqué n'étant descellé que pour eux ; actions journalisées ; rôles distincts | 3 | 1 |

### 4.3 Messagerie, demandes et coordonnées

| Événement redouté | Sources de risque | Mesures | Gravité | Vraisemblance |
|---|---|---|---|---|
| Accès d'un tiers à une conversation ou une demande (IDOR) | Attaquant authentifié | Contrôle d'accès par ressource (Voters), identifiants non prédictibles (UUID v7), **carte automatisée du contrôle d'accès** sur toutes les routes privées (T-905a), réponses 404 | 3 | 1 |
| Collecte de coordonnées par démarchage ou arnaque | Faux client ou faux professionnel | Coordonnées masquées avant acceptation (ADR-0014), révélation du téléphone limitée (20 / jour) et journalisée, signalement des messages, e-mails jetables refusés, contrôle anti-robot | 2 | 2 |
| Conservation excessive | Absence de purge | Purges à 3 ans, effacement du contenu des messages à la suppression du compte | 2 | 1 |

### 4.4 Comptes, sécurité générale

| Événement redouté | Sources de risque | Mesures | Gravité | Vraisemblance |
|---|---|---|---|---|
| Prise de contrôle d'un compte | Bourrage d'identifiants, hameçonnage | Argon2id, mots de passe compromis refusés, limitation des tentatives, contrôle anti-robot, révocation des appareils, 2FA obligatoire au back-office, connexion assistée tracée et limitée | 3 | 1 |
| Fuite de données par les journaux | Données personnelles écrites dans les logs | Masquage des e-mails et téléphones dans les journaux (testé sur un parcours complet, T-905a) | 2 | 1 |
| Effacement des traces d'audit | Injection SQL | Rôle de base sans droit de structure ni modification des tables en ajout seul (`ops/postgres/roles.sql`) ; **risque résiduel** sur la purge du journal (P-156), à traiter avant la mise en ligne | 2 | 2 |
| Indisponibilité ou perte | Panne, rançongiciel | Sauvegardes chiffrées 30 jours, restauration testée, supervision (T-906) | 2 | 2 |

### 4.5 Synthèse

Aucun risque ne reste en gravité 4 ni en vraisemblance supérieure à 2 si le plan d'action (§5) est réalisé.
Les risques les plus élevés portent sur les justificatifs (gravité 3) : leur traitement chiffré, journalisé et
limité dans le temps est la mesure centrale.

## 5. Plan d'action avant la mise en ligne

| Action | Porteur | Échéance | Statut |
|---|---|---|---|
| Choisir l'hébergeur UE et signer son DPA | Porteur | Avant mise en ligne | À faire |
| Signer les DPA Stripe, Cloudflare, prestataire d'e-mails | Porteur | Avant mise en ligne | À faire |
| Valider la suppression de la pièce d'identité dès la vérification | Porteur | Avant mise en ligne | À arbitrer (§6) |
| Appliquer `ops/postgres/roles.sql` et deux URL de base distinctes | Équipe dev | T-906 | Prêt |
| Traiter le risque résiduel de purge du journal d'audit (P-156) | Équipe dev | Avant mise en ligne | À faire |
| Pentest externe (périmètre incluant la vérification et la modération) | Porteur / prestataire | Avant mise en ligne | T-905c |
| Procédure de violation de données et notification CNIL sous 72 h | Porteur / équipe dev | Avant mise en ligne | T-905c |
| Sauvegardes chiffrées et test de restauration | Équipe dev | T-906 | À faire |
| Tenir à jour le registre des traitements (art. 30) à partir de §2 | Porteur | Avant mise en ligne | À faire |
| Compléter cette AIPD avec l'outil de mesure d'audience et la newsletter quand ils seront choisis | Porteur / équipe dev | À leur mise en place | À faire |
| Revoir l'AIPD | Porteur | Chaque année et à chaque évolution notable | — |

## 6. Points à arbitrer par le responsable de traitement

1. **Pièce d'identité** : suppression dès la vérification (proposé, `documents.delete_id_after_verification`) ou
   conservation avec les autres justificatifs (relation + 1 an) ?
2. **DPO** : désignation ou non (obligatoire si le traitement à grande échelle devient le cœur de l'activité) ;
   contact à indiquer dans la politique de confidentialité.
3. **Hébergeur et prestataire d'e-mails** (Brevo proposé, P-134).
4. **Outil de mesure d'audience** (exempté de consentement si configuré selon les règles CNIL, sinon soumis au bandeau).

## 7. Validation

| | Nom | Date | Avis |
|---|---|---|---|
| Responsable de traitement (Auxioma) | | | |
| DPO (si désigné) | | | |
| Avis des personnes concernées ou de leurs représentants (facultatif) | | | |

Décision : ☐ traitement mis en œuvre en l'état · ☐ mis en œuvre après le plan d'action · ☐ consultation préalable
de la CNIL (si un risque élevé résiduel demeure).
