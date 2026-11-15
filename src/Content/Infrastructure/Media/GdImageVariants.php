<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Media;

use App\Content\Application\Media\ImageVariants;

/** GD : redimensionnement proportionnel (rééchantillonnage), export WebP (qualité 80) et AVIF (qualité 55). */
final readonly class GdImageVariants implements ImageVariants
{
    private const array QUALITY = ['webp' => 80, 'avif' => 55];
    private const int AVIF_SPEED = 6;

    public function generate($source, array $widths, array $formats): array
    {
        $bytes = stream_get_contents($source);
        $image = false === $bytes ? false : @imagecreatefromstring($bytes);
        if (false === $image) {
            throw new \RuntimeException('Image illisible.');
        }
        $width = imagesx($image);
        $height = imagesy($image);
        sort($widths);
        $targets = array_values(array_unique(array_filter($widths, static fn (int $w): bool => $w <= $width) ?: [min($width, $widths[0] ?? $width)]));
        $files = [];
        try {
            foreach ($targets as $target) {
                $scaled = $target === $width ? $image : imagescale($image, $target, max(1, (int) round($height * $target / $width)), \IMG_BICUBIC);
                if (false === $scaled) {
                    throw new \RuntimeException('Redimensionnement impossible.');
                }
                imagepalettetotruecolor($scaled);
                imagealphablending($scaled, false);
                imagesavealpha($scaled, true);
                foreach ($formats as $format) {
                    $path = tempnam(sys_get_temp_dir(), 'tm-media-');
                    $ok = false !== $path && match ($format) {
                        'webp' => imagewebp($scaled, $path, self::QUALITY['webp']),
                        'avif' => imageavif($scaled, $path, self::QUALITY['avif'], self::AVIF_SPEED),
                        default => false,
                    };
                    if (!$ok || false === $path || 0 === filesize($path)) {
                        throw new \RuntimeException('Conversion '.$format.' impossible.');
                    }
                    $files[] = ['format' => $format, 'width' => $target, 'path' => $path];
                }
            }
        } catch (\RuntimeException $e) {
            foreach ($files as $file) {
                is_file($file['path']) && unlink($file['path']);
            }
            throw $e;
        }

        return ['width' => $width, 'height' => $height, 'files' => $files];
    }
}
