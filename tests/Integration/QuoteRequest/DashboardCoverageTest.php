<?php

declare(strict_types=1);

namespace App\Tests\Integration\QuoteRequest;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Identity\Application\Command\RegisterUser;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Shared\Application\Admin\DashboardCoverage;
use App\Shared\Application\Admin\DashboardCoverageView;
use App\Shared\Application\Bus\QueryBus;
use App\Tests\Support\ArtisanDossier;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** T-805c — BO01.3 : part sans devis par département, top métiers, couples métier × département non couverts. */
final class DashboardCoverageTest extends KernelTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f8051';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a8053';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000f8052';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
    }

    public function testUncoveredRequestsAreGroupedByDepartmentAndJob(): void
    {
        $this->prepareReferentials();
        $artisan = $this->submittedDossier(200);
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($artisan, self::ADMIN));
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.805@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Fictive'));
        $this->connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :c", ['c' => self::CLIENT]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new SubmitQuoteRequest(self::REQUEST, self::CLIENT, $job, '76351', '76600', '1 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], '500_1000', 'week', null, title: 'Fuite sous l’évier'));

        $bus = self::getContainer()->get(QueryBus::class);
        \assert($bus instanceof QueryBus);
        $view = $bus->ask(new DashboardCoverage(new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable('+1 hour')));
        \assert($view instanceof DashboardCoverageView);

        self::assertCount(1, $view->departments);
        self::assertSame('76', $view->departments[0]['code']);
        self::assertSame(1, $view->departments[0]['uncovered']);
        self::assertSame('alert', $view->departments[0]['severity'], 'aucun devis : 100 %');
        self::assertSame([['name' => 'Plombier', 'requests' => 1]], $view->jobs);
        self::assertCount(1, $view->gaps);
        self::assertSame($job, $view->gaps[0]['job_id']);
        self::assertSame('76', $view->gaps[0]['department_code']);
        self::assertSame(DashboardCoverageView::severity(0.05), 'ok');
        self::assertSame(DashboardCoverageView::severity(0.14), 'warn');
    }
}
