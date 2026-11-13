<?php

declare(strict_types=1);

namespace App\Quote\Infrastructure\Pdf;

use App\Quote\Application\Pdf\QuotePdfDocument;
use App\Quote\Application\Port\QuotePdfRenderer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Gabarit Twig → PDF (dompdf, ADR-0015) : aucune ressource distante (images en data URI), police DejaVu Sans
 * embarquée (accents, €), A4 portrait.
 */
final readonly class DompdfQuotePdfRenderer implements QuotePdfRenderer
{
    public function __construct(private Environment $twig, private string $cacheDir)
    {
    }

    public function render(QuotePdfDocument $document): string
    {
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0o775, true) && !is_dir($this->cacheDir)) {
            throw new \RuntimeException('Dossier de cache PDF indisponible.');
        }
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setFontCache($this->cacheDir);
        $options->setTempDir($this->cacheDir);
        $options->setChroot([$this->cacheDir]);
        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4');
        $dompdf->loadHtml($this->twig->render('quote/pdf.html.twig', ['d' => $document, 'palette' => PdfPalette::COLORS]), 'UTF-8');
        $dompdf->addInfo('Title', null === $document->number ? 'Aperçu de devis' : 'Devis '.$document->number);
        $dompdf->addInfo('Creator', 'TrouveMoi.com');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
