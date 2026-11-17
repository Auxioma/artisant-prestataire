<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Privacy;

use App\Identity\Application\Privacy\DataExportPackager;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Archive « Mes données » : `donnees.json` (format structuré, portabilité art. 20) et `donnees.pdf` (lecture, art. 15).
 * PDF : dompdf sans ressource distante, police DejaVu Sans (accents).
 */
final readonly class ZipDataExportPackager implements DataExportPackager
{
    /** Couleurs du PDF (un PDF n'a pas accès aux jetons CSS du site). */
    public const array COLORS = ['text' => '#222222', 'muted' => '#666666', 'rule' => '#cccccc', 'head' => '#eeeeee'];

    public function __construct(private Environment $twig, private string $cacheDir)
    {
    }

    public function package(string $holderName, \DateTimeImmutable $generatedAt, array $sections): string
    {
        $json = json_encode([
            'titulaire' => $holderName,
            'genere_le' => $generatedAt->format(\DATE_ATOM),
            'sections' => array_combine(array_column($sections, 'key'), array_column($sections, 'rows')),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        $path = tempnam(sys_get_temp_dir(), 'tm-export-');
        if (false === $path) {
            throw new \RuntimeException('Fichier temporaire indisponible.');
        }
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Archive impossible à créer.');
        }
        $zip->addFromString('donnees.json', $json);
        $zip->addFromString('donnees.pdf', $this->pdf($holderName, $generatedAt, $sections));
        $zip->close();

        return $path;
    }

    /** @param list<array{key: string, title: string, rows: list<array<string, mixed>>}> $sections */
    private function pdf(string $holderName, \DateTimeImmutable $generatedAt, array $sections): string
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
        $dompdf->loadHtml($this->twig->render('privacy/export.html.twig', ['holder' => $holderName, 'generated_at' => $generatedAt, 'sections' => $sections, 'palette' => self::COLORS]), 'UTF-8');
        $dompdf->addInfo('Title', 'Mes données TrouveMoi');
        $dompdf->addInfo('Creator', 'TrouveMoi.com');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
