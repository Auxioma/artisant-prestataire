<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\Legal\PublishLegalDocument;
use App\Identity\Application\Port\LegalDocuments;
use App\Identity\Domain\Legal\LegalDocumentType;
use App\Shared\Application\Bus\CommandBus;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** RG-002 : versions successives, HTML assaini, version figée après publication. */
final class LegalDocumentsTest extends KernelTestCase
{
    public function testPublishingVersionsSanitizesAndFreezesContent(): void
    {
        self::bootKernel();
        $commands = self::getContainer()->get(CommandBus::class);
        $documents = self::getContainer()->get(LegalDocuments::class);
        $connection = self::getContainer()->get(Connection::class);
        \assert($commands instanceof CommandBus && $documents instanceof LegalDocuments && $connection instanceof Connection);

        $commands->dispatch(new PublishLegalDocument('0190f5e4-0000-7000-8000-000000000501', 'cgu', 'CGU', '<h2>Objet</h2><p onclick="x()">Texte <script>alert(1)</script><a href="javascript:alert(1)">lien</a></p>', false));
        $commands->dispatch(new PublishLegalDocument('0190f5e4-0000-7000-8000-000000000502', 'cgu', 'CGU', '<p>Version 2</p>', true));

        $current = $documents->current(LegalDocumentType::TermsOfUse, new \DateTimeImmutable('+1 second'));
        self::assertNotNull($current);
        self::assertSame([2, true, '<p>Version 2</p>'], [$current->version, $current->substantial, $current->html]);

        $first = $documents->get('0190f5e4-0000-7000-8000-000000000501');
        self::assertNotNull($first);
        self::assertStringNotContainsString('script', $first->html);
        self::assertStringNotContainsString('onclick', $first->html);
        self::assertStringNotContainsString('javascript:', $first->html);
        self::assertSame(hash('sha256', $first->html), $first->contentHash);

        $this->expectException(\Doctrine\DBAL\Exception::class);
        $connection->executeStatement("UPDATE identity.legal_document SET content_html = 'modifié' WHERE version = 1");
    }
}
