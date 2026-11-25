<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Logging;

use App\Shared\Infrastructure\Logging\PiiMaskingProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/** Cadrage §5.8 / ASVS 7.1.1 : aucune donnée personnelle ni secret en clair dans les journaux. */
final class PiiMaskingProcessorTest extends TestCase
{
    /**
     * @param array<string, mixed> $context
     */
    private function process(string $message, array $context = []): LogRecord
    {
        return (new PiiMaskingProcessor())(new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, $message, $context));
    }

    public function testMasksEmailsAndPhonesInMessage(): void
    {
        $record = $this->process('Contact jean.dupont@exemple.fr au 06 12 34 56 78 ou +33612345678');

        self::assertSame('Contact j***@e*** au [phone] ou [phone]', $record->message);
    }

    public function testMasksSecretsAndIban(): void
    {
        $record = $this->process('Authorization: Bearer abc.def-123 clé sk_live_51HxYzAbCdEf iban FR76 3000 6000 0112 3456 7890 189');

        self::assertStringNotContainsString('abc.def-123', $record->message);
        self::assertStringNotContainsString('sk_live_51HxYzAbCdEf', $record->message);
        self::assertStringNotContainsString('3000 6000', $record->message);
    }

    public function testRedactsSensitiveContextKeysRecursively(): void
    {
        $record = $this->process('login', [
            'password' => 'hunter2hunter2',
            'user' => ['email' => 'a.b@c.fr', 'reset_token' => 'xyz', 'id' => '0190f5d2-6c1e-7a3b-9c4d-000000000001'],
        ]);

        self::assertSame('[redacted]', $record->context['password']);
        self::assertIsArray($record->context['user']);
        self::assertSame('a***@c***', $record->context['user']['email']);
        self::assertSame('[redacted]', $record->context['user']['reset_token']);
        self::assertSame('0190f5d2-6c1e-7a3b-9c4d-000000000001', $record->context['user']['id']);
    }

    public function testKeepsNonPersonalNumbersIntact(): void
    {
        $record = $this->process('Devis DEV-2026-000123 envoyé, 3 places, montant 1250,00 €, SIRET 73282932000074');

        self::assertSame('Devis DEV-2026-000123 envoyé, 3 places, montant 1250,00 €, SIRET 73282932000074', $record->message);
    }
}
