<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use App\Shared\Application\Storage\Port\FileSanitizer;
use App\Shared\Application\Storage\Port\SanitizedFile;
use App\Shared\Application\Storage\RejectedUpload;
use App\Shared\Domain\Storage\FileType;

/**
 * ADR-0008 / cadrage §5.5 (finfo + GD) :
 *  - type réel lu dans le contenu (magic bytes) et confronté à la liste blanche de l'usage ;
 *  - images décodées puis ré-encodées depuis les pixels : métadonnées (EXIF, GPS, XMP, ICC) et toute charge cachée
 *    (polyglotte JPEG/HTML/ZIP, données après la fin d'image) disparaissent ; orientation EXIF appliquée avant ;
 *    dimensions bornées AVANT décodage (bombe de décompression) ;
 *  - PDF : refus des actions et contenus actifs, y compris dans les flux compressés (FlateDecode, borné) et
 *    sous noms obscurcis (#xx) ; PDF chiffré refusé (contenu non inspectable). ClamAV complète ensuite.
 */
final readonly class NativeFileSanitizer implements FileSanitizer
{
    private const int MAX_PIXELS = 40_000_000;
    private const int MAX_SIDE = 12_000;
    /** Côté maximal conservé (photos d'appareil récents ramenées à une taille d'affichage raisonnable). */
    private const int OUTPUT_MAX_SIDE = 4096;
    private const int JPEG_QUALITY = 85;
    private const int WEBP_QUALITY = 85;
    private const int MAX_INFLATED_BYTES = 50_000_000;
    /** Noms PDF déclenchant une action ou embarquant du contenu actif. */
    private const string PDF_ACTIVE_NAMES = '~/(JS|JavaScript|Launch|EmbeddedFiles?|RichMedia|AA|XFA|SubmitForm|ImportData|GoToE)(?![A-Za-z])~';

    public function sanitize(string $path, array $allowed): SanitizedFile
    {
        $type = FileType::tryFrom((string) new \finfo(\FILEINFO_MIME_TYPE)->file($path));
        if (null === $type || !\in_array($type, $allowed, true)) {
            throw new RejectedUpload(RejectedUpload::TYPE_NOT_ALLOWED);
        }

        if (FileType::Pdf === $type) {
            $this->inspectPdf($path);

            return new SanitizedFile($path, $type);
        }

        return new SanitizedFile($this->reencode($path, $type), $type);
    }

    private function reencode(string $path, FileType $type): string
    {
        $info = @getimagesize($path);
        if (false === $info) {
            throw new RejectedUpload(RejectedUpload::UNREADABLE);
        }
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            throw new RejectedUpload(RejectedUpload::IMAGE_TOO_LARGE);
        }

        $image = match ($type) {
            FileType::Jpeg => @imagecreatefromjpeg($path),
            FileType::Png => @imagecreatefrompng($path),
            FileType::Webp => @imagecreatefromwebp($path),
            FileType::Pdf, FileType::Zip, FileType::Avif, FileType::Csv => false,
        };
        if (false === $image) {
            throw new RejectedUpload(RejectedUpload::UNREADABLE);
        }

        try {
            if (FileType::Jpeg === $type) {
                $image = $this->applyExifOrientation($image, $path);
            }
            $image = $this->downscale($image);

            $output = tempnam(sys_get_temp_dir(), 'tm-img-');
            if (false === $output) {
                throw new \RuntimeException('Fichier temporaire indisponible.');
            }
            $written = match ($type) {
                FileType::Jpeg => imagejpeg($image, $output, self::JPEG_QUALITY),
                FileType::Png => imagesavealpha($image, true) && imagepng($image, $output, 6),
                FileType::Webp => imagesavealpha($image, true) && imagewebp($image, $output, self::WEBP_QUALITY),
            };
            if (!$written) {
                @unlink($output);
                throw new RejectedUpload(RejectedUpload::UNREADABLE);
            }

            return $output;
        } finally {
            imagedestroy($image);
        }
    }

    private function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        $exif = \function_exists('exif_read_data') ? @exif_read_data($path) : false;
        $orientation = \is_array($exif) && \is_int($exif['Orientation'] ?? null) ? $exif['Orientation'] : 1;
        $rotated = match ($orientation) {
            3, 4 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, -90, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };
        if (false === $rotated) {
            return $image;
        }
        if (\in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($rotated, \IMG_FLIP_HORIZONTAL);
        }
        if ($rotated !== $image) {
            imagedestroy($image);
        }

        return $rotated;
    }

    private function downscale(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);
        if ($longest <= self::OUTPUT_MAX_SIDE) {
            return $image;
        }
        $ratio = self::OUTPUT_MAX_SIDE / $longest;
        $scaled = imagescale($image, max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)), \IMG_BICUBIC);
        if (false === $scaled) {
            return $image;
        }
        imagedestroy($image);

        return $scaled;
    }

    private function inspectPdf(string $path): void
    {
        $content = file_get_contents($path);
        if (false === $content || !str_starts_with(ltrim(substr($content, 0, 1024)), '%PDF-')) {
            throw new RejectedUpload(RejectedUpload::UNREADABLE);
        }
        if (1 === preg_match('~/Encrypt(?![A-Za-z])~', self::decodeNames($content))) {
            throw new RejectedUpload(RejectedUpload::PDF_ENCRYPTED);
        }

        $inflated = 0;
        foreach ($this->segments($content, $inflated) as $segment) {
            if (1 === preg_match(self::PDF_ACTIVE_NAMES, self::decodeNames($segment), $m)) {
                throw new RejectedUpload(RejectedUpload::PDF_ACTIVE_CONTENT, '/'.$m[1]);
            }
        }
    }

    /**
     * Le document brut puis chaque flux décompressable (les dictionnaires peuvent être cachés dans des /ObjStm).
     *
     * @return iterable<string>
     */
    private function segments(string $content, int &$inflated): iterable
    {
        yield $content;
        if (false === preg_match_all('~stream\r?\n(.*?)\r?\n?endstream~s', $content, $streams)) {
            return;
        }
        foreach ($streams[1] as $raw) {
            $decoded = @gzuncompress($raw, self::MAX_INFLATED_BYTES);
            if (false === $decoded) {
                continue; // flux non Flate (images DCT…) ou volontairement corrompu : rien de plus à lire
            }
            $inflated += \strlen($decoded);
            if ($inflated > self::MAX_INFLATED_BYTES) {
                throw new RejectedUpload(RejectedUpload::UNREADABLE, 'flux décompressés trop volumineux');
            }
            yield $decoded;
        }
    }

    /** « /J#61vaScript » → « /JavaScript » (obscurcissement par échappement hexadécimal des noms). */
    private static function decodeNames(string $content): string
    {
        return (string) preg_replace_callback('~/[^\s/<>\[\]()%{}]+~', static fn (array $m): string => (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn (array $h): string => \chr((int) hexdec($h[1])), $m[0]), $content);
    }
}
