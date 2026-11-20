<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Application\Storage\FileReader;
use App\Shared\Application\Storage\FileShredder;
use App\Shared\Application\Storage\FileUploader;
use App\Shared\Application\Storage\Port\ObjectStorage;
use App\Shared\Application\Storage\Port\StorageZone;
use App\Shared\Application\Storage\RejectedUpload;
use App\Shared\Application\Storage\ScanQuarantinedFile;
use App\Shared\Application\Storage\StoredFile;
use App\Shared\Application\Storage\StoredFileNotFound;
use App\Shared\Domain\Storage\FilePurpose;
use App\Shared\Domain\Storage\FileQuarantined;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\Fake\EicarAntivirusScanner;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** ADR-0008 de bout en bout : upload → quarantaine → antivirus → (chiffrement) → lecture journalisée → destruction. */
final class FileStoragePipelineTest extends KernelTestCase
{
    private FileUploader $uploader;
    private ScanQuarantinedFile $scan;
    private FileReader $reader;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $uploader = $c->get(FileUploader::class);
        $scan = $c->get(ScanQuarantinedFile::class);
        $reader = $c->get(FileReader::class);
        $connection = $c->get(Connection::class);
        \assert($uploader instanceof FileUploader && $scan instanceof ScanQuarantinedFile && $reader instanceof FileReader && $connection instanceof Connection);
        [$this->uploader, $this->scan, $this->reader, $this->connection] = [$uploader, $scan, $reader, $connection];
        EicarAntivirusScanner::$down = false;
        $this->setMaxFileMb(10);
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        EicarAntivirusScanner::$down = false;
        parent::tearDown();
    }

    private function setMaxFileMb(int $mb): void
    {
        $this->connection->executeStatement("INSERT INTO admin.setting (key, value, type, updated_at) VALUES ('requests.max_file_mb', :v, 'int', now()) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value", ['v' => (string) $mb]);
        $cache = self::getContainer()->get('cache.settings');
        \assert($cache instanceof \Psr\Cache\CacheItemPoolInterface);
        $cache->clear();
    }

    private function storage(StorageZone $zone): FilesystemOperator
    {
        $storage = self::getContainer()->get(StorageZone::Quarantine === $zone ? 'quarantine.storage' : 'documents.storage');
        \assert($storage instanceof FilesystemOperator);

        return $storage;
    }

    private function exists(StorageZone $zone, string $id): bool
    {
        return $this->storage($zone)->fileExists(substr($id, 0, 2).'/'.$id);
    }

    private function scan(StoredFile $file): void
    {
        ($this->scan)(new FileQuarantined($file->id, $file->purpose->value, new \DateTimeImmutable()));
    }

    private function fileStatus(string $id): string
    {
        return DbalValue::string($this->connection->fetchOne('SELECT status FROM shared.stored_file WHERE id = :id', ['id' => $id]));
    }

    /** @param array<string, string> $params */
    private function rows(string $sql, array $params = []): int
    {
        return DbalValue::int($this->connection->fetchOne($sql, $params));
    }

    public function testPhotoIsQuarantinedScannedThenServedBySignedUrl(): void
    {
        $file = $this->uploader->upload(StorageSamples::file(StorageSamples::jpegWithExif(1, 'GPS-SECRET')), "../../etc/Photo \"cuisine\"\x07<1>.jpg", FilePurpose::RequestPhoto);

        self::assertSame('quarantined', $this->fileStatus($file->id));
        self::assertSame('Photo cuisine1.jpg', $file->originalName, 'chemin, balises et caractères de contrôle retirés');
        self::assertTrue($this->exists(StorageZone::Quarantine, $file->id));
        self::assertSame(1, $this->rows('SELECT count(*) FROM shared.outbox_message WHERE message_type = :t', ['t' => FileQuarantined::class]));
        try {
            $this->reader->signedUrl($file->id);
            self::fail('Fichier en quarantaine servi');
        } catch (StoredFileNotFound) {
        }

        $this->scan($file);

        self::assertSame('available', $this->fileStatus($file->id));
        self::assertFalse($this->exists(StorageZone::Quarantine, $file->id));
        self::assertStringNotContainsString('GPS-SECRET', (string) $this->storage(StorageZone::Documents)->read(substr($file->id, 0, 2).'/'.$file->id));
        $url = $this->reader->signedUrl($file->id);
        self::assertStringContainsString('ResponseContentDisposition=attachment', $url);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertEqualsWithDelta(time() + 300, (int) $query['expires'], 5, 'documents.signed_url_ttl_sec');
    }

    public function testArtisanDocumentIsEncryptedAndEveryReadIsAudited(): void
    {
        $pdf = StorageSamples::pdf('BT (KBIS FICTIF NUMERO 123) Tj ET');
        $file = $this->uploader->upload(StorageSamples::file($pdf), 'kbis.pdf', FilePurpose::ArtisanDocument);
        $this->scan($file);

        $stored = (string) $this->storage(StorageZone::Documents)->read(substr($file->id, 0, 2).'/'.$file->id);
        self::assertStringNotContainsString('KBIS FICTIF', $stored);
        self::assertSame(1, $this->rows('SELECT count(*) FROM shared.stored_file WHERE id = :id AND wrapped_dek IS NOT NULL AND kek_id = :k', ['id' => $file->id, 'k' => 'dev-1']));

        try {
            $this->reader->signedUrl($file->id);
            self::fail('URL signée émise pour un justificatif chiffré');
        } catch (StoredFileNotFound) {
        }

        $plain = $this->reader->openForActor($file->id, 'admin', '0190f5e4-0000-7000-8000-000000000001', '203.0.113.7');
        self::assertSame($pdf, stream_get_contents($plain));
        self::assertSame(1, $this->rows("SELECT count(*) FROM admin.audit_log WHERE action = 'file.read' AND subject_id = :id AND ip = '203.0.113.7'", ['id' => $file->id]));
    }

    public function testInfectedFileIsDeletedAndReportedAsSecurityEvent(): void
    {
        $file = $this->uploader->upload(StorageSamples::file(StorageSamples::pdf('BT ('.EicarAntivirusScanner::eicar().') Tj ET')), null, FilePurpose::MessageAttachment);

        $this->scan($file);

        self::assertSame('rejected', $this->fileStatus($file->id));
        self::assertFalse($this->exists(StorageZone::Quarantine, $file->id));
        self::assertFalse($this->exists(StorageZone::Documents, $file->id));
        self::assertSame(1, $this->rows("SELECT count(*) FROM admin.audit_log WHERE action = 'security.malware_detected' AND subject_id = :id", ['id' => $file->id]));
        self::assertSame(1, $this->rows("SELECT count(*) FROM shared.outbox_message WHERE message_type LIKE '%FileRejected'"));
    }

    public function testScannerOutageKeepsTheFileQuarantinedForRetry(): void
    {
        $file = $this->uploader->upload(StorageSamples::file(StorageSamples::png()), null, FilePurpose::RequestPhoto);
        EicarAntivirusScanner::$down = true;

        try {
            $this->scan($file);
            self::fail('Panne de l’antivirus ignorée');
        } catch (ExternalServiceUnavailable) {
        }

        self::assertSame('quarantined', $this->fileStatus($file->id));
        self::assertTrue($this->exists(StorageZone::Quarantine, $file->id));

        EicarAntivirusScanner::$down = false;
        $this->scan($file);
        $scans = EicarAntivirusScanner::$scans;
        $this->scan($file); // message rejoué : sans effet
        self::assertSame('available', $this->fileStatus($file->id));
        self::assertSame($scans, EicarAntivirusScanner::$scans);
    }

    public function testShreddingDestroysKeyAndObject(): void
    {
        $file = $this->uploader->upload(StorageSamples::file(StorageSamples::jpeg()), 'piece-identite.jpg', FilePurpose::ArtisanDocument);
        $this->scan($file);
        $shredder = self::getContainer()->get(FileShredder::class);
        \assert($shredder instanceof FileShredder);

        $shredder->shred($file->id, 'q16_after_verification');

        self::assertSame('shredded', $this->fileStatus($file->id));
        self::assertSame(0, $this->rows('SELECT count(*) FROM shared.stored_file WHERE id = :id AND wrapped_dek IS NOT NULL', ['id' => $file->id]));
        self::assertFalse($this->exists(StorageZone::Documents, $file->id));
        self::assertSame(1, $this->rows("SELECT count(*) FROM admin.audit_log WHERE action = 'file.shredded' AND subject_id = :id", ['id' => $file->id]));
        $this->expectException(StoredFileNotFound::class);
        $this->reader->openForActor($file->id, 'admin', '0190f5e4-0000-7000-8000-000000000001', null);
    }

    public function testSizeLimitComesFromSettingsAndRejectedFilesLeaveNoTrace(): void
    {
        $this->setMaxFileMb(1);
        $before = $this->rows('SELECT count(*) FROM shared.stored_file');

        foreach ([[str_repeat('a', 1_048_577), RejectedUpload::TOO_LARGE], ['', RejectedUpload::EMPTY], ['<?php echo 1;', RejectedUpload::TYPE_NOT_ALLOWED]] as [$content, $reason]) {
            try {
                $this->uploader->upload(StorageSamples::file($content), 'x.jpg', FilePurpose::RequestPhoto);
                self::fail('Fichier accepté : '.$reason);
            } catch (RejectedUpload $e) {
                self::assertSame($reason, $e->reason);
            }
        }
        self::assertSame($before, $this->rows('SELECT count(*) FROM shared.stored_file'));
        self::assertSame([], $this->storage(StorageZone::Quarantine)->listContents('', true)->toArray());
    }

    public function testDatabaseRefusesAnUnencryptedAvailableArtisanDocument(): void
    {
        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);
        $this->connection->executeStatement("INSERT INTO shared.stored_file (id, purpose, status, mime, size, sha256, created_at) VALUES ('0190f5e4-0000-7000-8000-0000000000aa', 'artisan_document', 'available', 'application/pdf', 1, repeat('0', 64), now())");
    }

    public function testStorageKeysMustBeUuids(): void
    {
        $storage = self::getContainer()->get(ObjectStorage::class);
        \assert($storage instanceof ObjectStorage);

        $this->expectException(\InvalidArgumentException::class);
        $storage->read(StorageZone::Documents, '../../etc/passwd');
    }
}
