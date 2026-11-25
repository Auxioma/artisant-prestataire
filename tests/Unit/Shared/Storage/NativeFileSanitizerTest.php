<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Storage;

use App\Shared\Application\Storage\RejectedUpload;
use App\Shared\Domain\Storage\FileType;
use App\Shared\Infrastructure\Storage\NativeFileSanitizer;
use App\Tests\Support\StorageSamples;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ADR-0008, cadrage §5.5 : type réel, ré-encodage des images, PDF actifs refusés. */
final class NativeFileSanitizerTest extends TestCase
{
    private const array IMAGES = [FileType::Jpeg, FileType::Png, FileType::Webp];
    private const array ALL = [FileType::Pdf, FileType::Jpeg, FileType::Png, FileType::Webp];

    private NativeFileSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new NativeFileSanitizer();
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
    }

    /** @param list<FileType> $allowed */
    private function rejection(string $content, array $allowed = self::ALL): string
    {
        try {
            $this->sanitizer->sanitize(StorageSamples::file($content), $allowed);
        } catch (RejectedUpload $e) {
            return $e->reason;
        }
        self::fail('Fichier accepté à tort');
    }

    public function testJpegIsReencodedWithoutExifAndOriented(): void
    {
        $result = $this->sanitizer->sanitize(StorageSamples::file(StorageSamples::jpegWithExif(6, 'GPS-SECRET-48.85N')), self::IMAGES);
        $output = (string) file_get_contents($result->path);
        unlink($result->path);

        self::assertSame(FileType::Jpeg, $result->type);
        self::assertStringNotContainsString('Exif', $output);
        self::assertStringNotContainsString('GPS-SECRET', $output);
        $size = getimagesizefromstring($output);
        self::assertIsArray($size);
        self::assertSame([20, 40], [$size[0], $size[1]], 'orientation 6 appliquée (rotation de 90°)');
    }

    public function testPolyglotPayloadIsDropped(): void
    {
        $result = $this->sanitizer->sanitize(StorageSamples::file(StorageSamples::jpeg()."<script>alert(1)</script>PK\x03\x04charge.php"), self::IMAGES);
        $output = (string) file_get_contents($result->path);
        unlink($result->path);

        self::assertStringNotContainsString('<script>', $output);
        self::assertStringNotContainsString('charge.php', $output);
    }

    public function testPngTextChunksAreRemovedAndLargeImagesDownscaled(): void
    {
        $png = StorageSamples::png(5000, 100);
        $chunk = 'tEXt'."Comment\0SECRET";
        $withText = substr($png, 0, 33).pack('N', \strlen($chunk) - 4).$chunk.pack('N', crc32($chunk)).substr($png, 33);
        $result = $this->sanitizer->sanitize(StorageSamples::file($withText), self::IMAGES);
        $output = (string) file_get_contents($result->path);
        unlink($result->path);

        self::assertStringNotContainsString('SECRET', $output);
        self::assertSame(4096, getimagesizefromstring($output)[0] ?? null);
    }

    /** @return iterable<string, array{string, list<FileType>, string}> */
    public static function rejected(): iterable
    {
        yield 'script PHP renommé' => ["<?php system(\$_GET['c']);", self::ALL, RejectedUpload::TYPE_NOT_ALLOWED];
        yield 'page HTML' => ['<!doctype html><html><script>alert(1)</script></html>', self::ALL, RejectedUpload::TYPE_NOT_ALLOWED];
        yield 'SVG (scriptable)' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', self::ALL, RejectedUpload::TYPE_NOT_ALLOWED];
        yield 'PDF pour une photo' => [StorageSamples::pdf(), self::IMAGES, RejectedUpload::TYPE_NOT_ALLOWED];
        yield 'JPEG tronqué' => ["\xFF\xD8\xFF\xE0\x00\x10JFIF\x00", self::IMAGES, RejectedUpload::UNREADABLE];
        yield 'bombe de décompression' => ["\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 20000, 20000)."\x08\x02\x00\x00\x00".pack('N', 0), self::IMAGES, RejectedUpload::IMAGE_TOO_LARGE];
        yield 'PDF JavaScript' => [StorageSamples::pdf().'5 0 obj << /S /JavaScript /JS (app.alert(1)) >> endobj', self::ALL, RejectedUpload::PDF_ACTIVE_CONTENT];
        yield 'PDF nom obscurci' => [StorageSamples::pdf().'5 0 obj << /S /J#61vaScript >> endobj', self::ALL, RejectedUpload::PDF_ACTIVE_CONTENT];
        yield 'PDF lancement' => [StorageSamples::pdf().'5 0 obj << /S /Launch /F (cmd.exe) >> endobj', self::ALL, RejectedUpload::PDF_ACTIVE_CONTENT];
        yield 'PDF fichier joint' => [StorageSamples::pdf().'5 0 obj << /EmbeddedFiles 6 0 R >> endobj', self::ALL, RejectedUpload::PDF_ACTIVE_CONTENT];
        yield 'PDF action compressée' => [StorageSamples::pdf().'5 0 obj << /Type /ObjStm /Filter /FlateDecode >> stream'."\n".gzcompress('<< /OpenAction << /S /JavaScript /JS (x) >> >>')."\nendstream", self::ALL, RejectedUpload::PDF_ACTIVE_CONTENT];
        yield 'PDF chiffré' => [StorageSamples::pdf().'trailer << /Encrypt 9 0 R >>', self::ALL, RejectedUpload::PDF_ENCRYPTED];
    }

    /** @param list<FileType> $allowed */
    #[DataProvider('rejected')]
    public function testDangerousOrDisguisedFilesAreRejected(string $content, array $allowed, string $reason): void
    {
        self::assertSame($reason, $this->rejection($content, $allowed));
    }

    public function testCleanPdfIsKeptAsIs(): void
    {
        $path = StorageSamples::file(StorageSamples::pdf('BT (Mention /JSON-like texte et /AAbc) Tj ET'));

        $result = $this->sanitizer->sanitize($path, self::ALL);

        self::assertSame(FileType::Pdf, $result->type);
        self::assertSame($path, $result->path);
    }
}
