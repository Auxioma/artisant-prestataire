# ADR-0015 — PDF des devis : gabarit Twig rendu par dompdf

**Statut** : Proposé · **Date** : 2026-11-13

## Contexte
PR04.4 demande un aperçu fidèle du PDF (logo de l'artisan, couleurs neutres, pied « Devis transmis via TrouveMoi.com »),
une génération serveur et un PDF **figé et horodaté à l'envoi**, avec son empreinte SHA-256 conservée (ADR-0008). Le
projet n'avait aucun moteur PDF.

## Décision
- **dompdf 3** (bibliothèque PHP, LGPL) rend le gabarit Twig `templates/quote/pdf.html.twig` : aucun service ni binaire
  supplémentaire, rendu déterministe, tableaux et sauts de page gérés.
- Sécurité : ressources distantes, PHP et JavaScript désactivés ; `chroot` limité au cache ; logo intégré en data URI
  (fichier public déjà analysé par l'antivirus).
- Police **DejaVu Sans** embarquée (accents, €) : Poppins n'est pas utilisée dans le PDF.
- Couleurs neutres dans `PdfPalette` (côté PHP, comme `EmailPalette`), le PDF n'ayant pas accès aux jetons CSS.
- Version envoyée : PDF rendu, stocké en zone privée sous une clé UUID, **empreinte SHA-256** enregistrée avec la
  version figée (`quote_version.pdf_key`, `pdf_sha256`) ; chaque téléchargement recalcule l'empreinte et refuse un
  fichier altéré. Brouillon : aperçu non stocké, filigrane « Aperçu — brouillon ».

## Alternatives écartées
- **Gotenberg / Chromium headless** : rendu CSS moderne, mais un conteneur de plus à exploiter et sécuriser.
- **wkhtmltopdf** : projet archivé.
- **TCPDF / FPDF** : mise en page en PHP, sans gabarit, coûteuse à maintenir.

## Conséquences
- (+) Pas de dépendance d'exploitation ; tests sans service externe.
- (−) CSS 2.1 seulement (pas de flex/grid) : gabarit en tableaux.
