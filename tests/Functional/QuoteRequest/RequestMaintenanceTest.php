<?php

declare(strict_types=1);

namespace App\Tests\Functional\QuoteRequest;

use App\Identity\Application\Command\RegisterUser;
use App\QuoteRequest\Application\Maintenance\RunRequestMaintenance;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

/** T-407 : expiration RG-064 (E-19), prévenance avant échéance (E-19), relance de brouillon E-19c, purge RG-060. */
final class RequestMaintenanceTest extends KernelTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000e9001';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000e9101';
    private const string DRAFT = '0190f5e4-0000-7000-8000-0000000e9201';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.echeance@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Échéance'));
        $this->connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :id", ['id' => self::CLIENT]);
    }

    public function testRequestIsWarnedOnceThenExpires(): void
    {
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new SubmitQuoteRequest(self::REQUEST, self::CLIENT, $job, '76351', '76600', '1 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], null, 'week', null));

        $this->task('warn');
        self::assertSame(0, $this->deliveries('E-19'), 'échéance lointaine (30 j)');

        $this->connection->executeStatement("UPDATE request.quote_request SET expires_at = date_trunc('second', now()) + interval '2 days' WHERE id = :id", ['id' => self::REQUEST]);
        $this->task('warn');
        $this->task('warn');
        self::assertSame(1, $this->deliveries('E-19'), 'une seule prévenance par échéance');
        self::assertStringStartsWith('Votre demande DM-', (string) $this->lastEmail()->getSubject());
        self::assertStringContainsString('Vous n’avez pas encore reçu de devis', (string) $this->lastEmail()->getTextBody());

        $this->connection->executeStatement("UPDATE request.quote_request SET expires_at = date_trunc('second', now()) - interval '1 hour' WHERE id = :id", ['id' => self::REQUEST]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->task('expire');
        self::assertSame('expired', $this->dossierValue("SELECT status FROM request.quote_request WHERE id = '".self::REQUEST."'"));
        self::assertSame(2, $this->deliveries('E-19'));
        self::assertStringEndsWith('a expiré', (string) $this->lastEmail()->getSubject());
        self::assertStringContainsString('relancer une fois', (string) $this->lastEmail()->getTextBody());
        self::assertSame('1', $this->dossierValue("SELECT count(*)::text FROM admin.audit_log WHERE action = 'request.expired'"));

        $this->task('expire');
        self::assertSame(2, $this->deliveries('E-19'), 'déjà expirée');
    }

    public function testIdleDraftIsRemindedOnceAndExpiredDraftIsPurged(): void
    {
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $insert = 'INSERT INTO request.request_draft (id, owner_user_id, step, payload, created_at, updated_at, expires_at) VALUES (:id, :u, 2, :payload, now(), :updated, :expires)';
        $this->connection->executeStatement($insert, ['id' => self::DRAFT, 'u' => self::CLIENT, 'payload' => json_encode(['job_id' => $job]), 'updated' => new \DateTimeImmutable('-2 hours')->format(\DATE_ATOM), 'expires' => new \DateTimeImmutable('+28 days')->format(\DATE_ATOM)]);

        $this->task('remind');
        self::assertSame(0, $this->deliveries('E-19c'), 'inactif depuis moins de 24 h');

        $this->connection->executeStatement("UPDATE request.request_draft SET updated_at = now() - interval '2 days' WHERE id = :id", ['id' => self::DRAFT]);
        $this->task('remind');
        $this->task('remind');
        self::assertSame(1, $this->deliveries('E-19c'), 'une seule relance');
        $text = (string) $this->lastEmail()->getTextBody();
        self::assertStringContainsString('(Plombier)', $text);
        self::assertStringContainsString('au bout de 30 jours', $text);

        $this->connection->executeStatement("UPDATE request.request_draft SET owner_user_id = NULL, owner_token_hash = :t, expires_at = now() - interval '1 minute' WHERE id = :id", ['t' => str_repeat('a', 64), 'id' => self::DRAFT]);
        $this->task('purge');
        self::assertSame('0', $this->dossierValue('SELECT count(*)::text FROM request.request_draft'), 'brouillon expiré supprimé');
    }

    private function task(string $task): void
    {
        $this->dossierDispatch(new RunRequestMaintenance($task));
    }

    private function deliveries(string $code): int
    {
        return DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM notification.delivery WHERE code = :c AND channel = 'email'", ['c' => $code]));
    }

    private function lastEmail(): Email
    {
        $mails = array_values(array_filter(self::getMailerMessages(), static fn (object $m): bool => $m instanceof Email));
        $mail = end($mails);
        self::assertInstanceOf(Email::class, $mail);

        return $mail;
    }
}
