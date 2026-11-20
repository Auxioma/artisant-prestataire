<?php

declare(strict_types=1);

namespace App\Tests\Integration\ArtisanProfile;

use App\ArtisanProfile\Application\Maintenance\PurgeEndedRelationDocuments;
use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\EventHandler\CancelSubscriptionOfErasedAccount;
use App\Billing\Infrastructure\Payment\InMemoryPaymentGateway;
use App\Shared\Domain\Event\AccountErased;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** T-808b2 — §11 : justificatifs détruits un an après la fin de la relation ; effacement d'un artisan abonné → résiliation Stripe. */
final class EndedRelationRetentionTest extends KernelTestCase
{
    use ArtisanDossier;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a8091';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
    }

    public function testDocumentsAreShreddedOneYearAfterTheAccountWasErased(): void
    {
        $artisan = $this->submittedDossier(200);
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($artisan, self::ADMIN));
        $kept = $this->documentsLeft($artisan);
        self::assertGreaterThan(0, $kept);

        // Effacé il y a 2 mois : pièces conservées (relation + 1 an).
        $this->erase('2 months');
        $this->dossierDispatch(new PurgeEndedRelationDocuments());
        self::assertSame($kept, $this->documentsLeft($artisan));

        // Effacé il y a 13 mois : pièces et fichiers détruits.
        $this->erase('13 months');
        $this->dossierDispatch(new PurgeEndedRelationDocuments());
        self::assertSame(0, $this->documentsLeft($artisan));
        self::assertSame($kept, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM artisan.document WHERE artisan_id = :a AND reason = 'retention'", ['a' => $artisan])));
        self::assertSame(0, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM shared.stored_file f JOIN artisan.document d ON d.file_id = f.id WHERE d.artisan_id = :a AND f.status <> 'shredded'", ['a' => $artisan])));
    }

    public function testErasingASubscribedArtisanCancelsTheStripeSubscription(): void
    {
        $artisan = $this->submittedDossier(200);
        $gateway = self::getContainer()->get(InMemoryPaymentGateway::class);
        \assert($gateway instanceof InMemoryPaymentGateway);
        $gateway->reset();
        $plan = '0190f5e4-0000-7000-8000-0000000b8091';
        $this->connection->executeStatement("INSERT INTO billing.plan (id, slug, name, visibility, status, position, created_at, updated_at) VALUES (:p, 'formule-808-fictive', 'Formule 808 Fictive', 'public', 'published', 9, now(), now())", ['p' => $plan]);
        $this->connection->executeStatement("INSERT INTO billing.plan_price (id, plan_id, interval, amount_cents, currency, active, created_at) VALUES ('0190f5e4-0000-7000-8000-0000000b8092', :p, 'month', 2900, 'EUR', true, now())", ['p' => $plan]);
        $this->connection->executeStatement('INSERT INTO billing.credit_account (artisan_id, user_id, created_at) VALUES (:a, :u, now()) ON CONFLICT (artisan_id) DO NOTHING', ['a' => $artisan, 'u' => self::DOSSIER_USER]);
        $this->connection->executeStatement(
            "INSERT INTO billing.subscription (id, artisan_id, plan_id, plan_price_id, interval, status, stripe_subscription_id, current_period_start, current_period_end, created_at, updated_at)
             VALUES ('0190f5e4-0000-7000-8000-0000000e8091', :a, :p, '0190f5e4-0000-7000-8000-0000000b8092', 'month', 'active', 'sub_808', now() - interval '3 days', now() + interval '27 days', now(), now())",
            ['a' => $artisan, 'p' => $plan],
        );

        $handler = self::getContainer()->get(CancelSubscriptionOfErasedAccount::class);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($handler instanceof CancelSubscriptionOfErasedAccount && $em instanceof EntityManagerInterface);
        $em->wrapInTransaction(static fn () => $handler(new AccountErased(self::DOSSIER_USER, new \DateTimeImmutable())));

        self::assertContains('subscription_cancel', array_column($gateway->calls, 0), 'résiliation demandée à Stripe');
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE action = 'billing.subscription_cancelled_erasure'")));
    }

    private function erase(string $ago): void
    {
        $this->connection->executeStatement(
            "UPDATE identity.user_account SET status = 'anonymized', anonymized_at = date_trunc('second', now() - CAST(:ago AS interval)) WHERE id = :u",
            ['ago' => $ago, 'u' => self::DOSSIER_USER],
        );
    }

    private function documentsLeft(string $artisan): int
    {
        return DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM artisan.document WHERE artisan_id = :a AND status <> 'shredded'", ['a' => $artisan]));
    }
}
