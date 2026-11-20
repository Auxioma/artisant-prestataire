<?php

declare(strict_types=1);

namespace App\Tests\Integration\QuoteRequest;

use App\Administration\Application\Command\ChangeSetting;
use App\Administration\Infrastructure\Settings\DbalSettingsProvider;
use App\Identity\Application\Command\RegisterUser;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\QuoteRequest\Domain\Request\RequestRuleViolation;
use App\QuoteRequest\Domain\Request\RequestStatus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Contracts\Cache\CacheInterface;

/** P11.7 / RG-065 : plafond de demandes ouvertes, anti-doublon 72 h, `quotes.max_per_request` figé, persistance et contraintes. */
final class SubmitQuoteRequestTest extends KernelTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f0001';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a0001';

    private Connection $connection;
    private string $jobId;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.demande@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Exemple'));
        $this->jobId = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
    }

    public function testSubmissionFreezesMaxQuotesAndPersistsTheRequest(): void
    {
        $this->setting('quotes.max_per_request', 4);
        $id = $this->submit(1, '12 rue Fictive');
        $this->setting('quotes.max_per_request', 2);

        $request = $this->repository()->find(new QuoteRequestId($id));
        self::assertNotNull($request);
        self::assertSame(RequestStatus::Open, $request->status());
        self::assertSame(4, $request->maxQuotes(), '§4.2 : la valeur modifiée ne touche pas les demandes existantes');
        self::assertMatchesRegularExpression('/^DM-\d{4}-\d{6}$/', $request->number());
        self::assertSame(['logement' => 'maison'], $request->details()->answers);
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE action = 'request.submitted'")));
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM shared.outbox_message WHERE message_type LIKE '%QuoteRequestSubmitted'")));

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('quote_request_slots_ck');
        $this->connection->executeStatement('UPDATE request.quote_request SET active_quotes_count = 5, received_quotes_count = 5 WHERE id = :id', ['id' => $id]);
    }

    public function testDuplicateWithinTheWindowIsRefusedUnlessCancelled(): void
    {
        $first = $this->submit(1, '12, Rue de l’Église');
        $this->expectViolation(fn () => $this->submit(2, '12 rue de l eglise'), 'déjà envoyé une demande pour ce métier à cette adresse');
        $this->submit(3, '14 rue de l’Église');

        $this->connection->executeStatement("UPDATE request.quote_request SET submitted_at = submitted_at - interval '73 hours' WHERE id = :id", ['id' => $first]);
        $this->submit(4, '12 rue de l’Église');
        $this->connection->executeStatement("UPDATE request.quote_request SET status = 'cancelled' WHERE duplicate_key = (SELECT duplicate_key FROM request.quote_request WHERE id = :id)", ['id' => $first]);
        $this->submit(5, '12 rue de l’Église');
    }

    public function testOpenRequestsAreCappedPerClient(): void
    {
        $this->setting('requests.max_open_per_client', 2);
        $this->submit(1, '1 rue Fictive');
        $this->submit(2, '2 rue Fictive');
        $this->expectViolation(fn () => $this->submit(3, '3 rue Fictive'), 'déjà 2 demandes en cours');

        $this->connection->executeStatement("UPDATE request.quote_request SET status = 'awarded' WHERE id = :id", ['id' => $this->id(1)]);
        $this->submit(3, '3 rue Fictive');
    }

    public function testUnknownCommuneOrInactiveJobIsRefused(): void
    {
        $this->expectViolation(fn () => $this->submit(1, '1 rue Fictive', postalCode: '14000'), 'code postal incohérent');
        $this->connection->executeStatement('UPDATE catalog.job SET active = false WHERE id = :id', ['id' => $this->jobId]);
        $this->expectViolation(fn () => $this->submit(1, '1 rue Fictive'), 'métier n’est pas disponible');
    }

    private function submit(int $n, string $address, string $postalCode = '76600'): string
    {
        $this->dossierDispatch(new SubmitQuoteRequest($this->id($n), self::CLIENT, $this->jobId, '76351', $postalCode, $address, 'repair', 'Fuite sur le ballon d’eau chaude, intervention rapide souhaitée (demande fictive).', ['logement' => 'maison'], null, 'week', null));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();

        return $this->id($n);
    }

    private function id(int $n): string
    {
        return \sprintf('0190f5e4-0000-7000-8000-0000000f%04d', 100 + $n);
    }

    private function expectViolation(callable $action, string $message): void
    {
        try {
            $action();
            self::fail('Refus attendu : '.$message);
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(RequestRuleViolation::class, $previous);
            self::assertStringContainsString($message, $previous->getMessage());
        } catch (RequestRuleViolation $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    private function repository(): QuoteRequestRepository
    {
        $repository = self::getContainer()->get(QuoteRequestRepository::class);
        \assert($repository instanceof QuoteRequestRepository);

        return $repository;
    }

    private function setting(string $key, mixed $value): void
    {
        $this->dossierDispatch(new ChangeSetting($key, $value, self::ADMIN, 'Test T-401'));
        $cache = self::getContainer()->get('cache.settings');
        $settings = self::getContainer()->get(DbalSettingsProvider::class);
        \assert($cache instanceof CacheInterface && $settings instanceof DbalSettingsProvider);
        $cache->delete(DbalSettingsProvider::CACHE_KEY);
        $settings->reset();
    }
}
