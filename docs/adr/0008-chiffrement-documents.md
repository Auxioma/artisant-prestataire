# ADR-0008 — Stockage et chiffrement des documents

**Statut** : Proposé · **Date** : 2026-11-11

## Contexte
Justificatifs artisans (Kbis, assurances, pièce d'identité : Q16), photos de demande (RG-061), PDF de devis, pièces jointes de messagerie. Exigences : stockage privé, type réel vérifié, EXIF supprimé, antivirus, URLs signées ≤ 5 min, chiffrement au repos des justificatifs, journalisation de chaque consultation.

## Décision
- **Flysystem** avec deux adaptateurs : `quarantine` (bucket privé A) et `documents` (bucket privé B). Les objets sont nommés par UUID et le nom d'origine n'est conservé qu'en métadonnée échappée.
- Pipeline d'upload : limite de taille → `finfo` (magic bytes) + liste blanche extension/MIME → ré-encodage des images (Imagick/libvips → WebP/JPEG, EXIF retiré, détection des polyglottes par ré-encodage) → PDF : rejet si `/JS`, `/JavaScript`, `/Launch` ou `/EmbeddedFile` → dépôt en quarantaine → message `ScanFile` (ClamAV clamd via socket) → si sain : déplacement vers `documents`, statut `available` ; sinon : suppression + événement de sécurité.
- **Chiffrement enveloppe** (justificatifs et pièces d'identité, originaux de messages masqués) : une DEK aléatoire par fichier, chiffrée avec `sodium_crypto_secretstream_xchacha20poly1305`. La DEK est chiffrée (wrap) par une KEK `sodium_crypto_secretbox` stockée dans le coffre de secrets (versionnée : `kek_id` stocké avec l'objet, rotation sans tout rechiffrer). Le chiffrement côté serveur S3 (SSE) s'ajoute en défense en profondeur.
- **Accès** : Voter (`DocumentVoter`) → déchiffrement en streaming via un contrôleur (`StreamedResponse`, `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, CSP `sandbox`) pour les documents chiffrés. Pour les non chiffrés (photos de demande), URL S3 pré-signée de 5 min (`documents.signed_url_ttl`) générée **après** le Voter. Chaque consultation de justificatif crée une ligne `AuditLog` (qui, quoi, quand, IP).
- Pièce d'identité supprimée (crypto-shredding de la DEK + suppression de l'objet) après la décision de vérification (Q16, paramètre `documents.delete_id_after_verification = oui`).
- `pdf_hash` (SHA-256) du PDF de devis stocké à l'envoi et vérifié au téléchargement.

## Alternatives écartées
- Chiffrement SSE-S3 seul : la clé est gérée par le fournisseur et ne protège pas contre la fuite des identifiants du bucket.
- Chiffrement en base (bytea) : volumétrie et sauvegardes trop lourdes.
