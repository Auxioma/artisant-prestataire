<?php

declare(strict_types=1);

namespace App\Tests\Support;

/** Fichiers de test générés à la volée (aucun binaire dans le dépôt). */
final class StorageSamples
{
    /** @var list<string> */
    private static array $created = [];

    public static function file(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tm-test-');
        \assert(\is_string($path));
        file_put_contents($path, $content);
        self::$created[] = $path;

        return $path;
    }

    public static function jpeg(int $width = 40, int $height = 20): string
    {
        return self::image($width, $height, static fn (\GdImage $i) => imagejpeg($i));
    }

    public static function png(int $width = 40, int $height = 20): string
    {
        return self::image($width, $height, static fn (\GdImage $i) => imagepng($i));
    }

    /**
     * JPEG avec segment APP1 Exif : orientation (6 = rotation de 90°) et un marqueur simulant des données privées.
     */
    public static function jpegWithExif(int $orientation, string $marker): string
    {
        $jpeg = self::jpeg(40, 20);
        $tiff = 'MM'.pack('n', 42).pack('N', 8)
            .pack('n', 2)
            .pack('nnNnn', 0x0112, 3, 1, $orientation, 0)                  // Orientation (SHORT)
            .pack('nnNN', 0x010E, 2, \strlen($marker) + 1, 8 + 2 + 24 + 4) // ImageDescription (ASCII)
            .pack('N', 0)
            .$marker."\0";
        $app1 = "\xFF\xE1".pack('n', \strlen($tiff) + 8)."Exif\0\0".$tiff;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    public static function pdf(string $body = 'BT /F1 12 Tf 72 712 Td (Attestation fictive de test) Tj ET'): string
    {
        return "%PDF-1.7\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
            ."3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >> endobj\n"
            .'4 0 obj << /Length '.\strlen($body)." >>\nstream\n".$body."\nendstream\nendobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    public static function cleanup(): void
    {
        foreach (self::$created as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        self::$created = [];
    }

    /** @param \Closure(\GdImage): bool $encode */
    private static function image(int $width, int $height, \Closure $encode): string
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        \assert($image instanceof \GdImage);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height, (int) imagecolorallocate($image, 0, 21, 104));
        ob_start();
        $encode($image);

        return (string) ob_get_clean();
    }
}
