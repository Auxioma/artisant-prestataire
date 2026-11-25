<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Application\Proposal\DecideJobProposal;
use App\Catalog\Application\Proposal\ProposeJob;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Catalog\Domain\Event\JobProposalDecided;
use App\Identity\Application\Command\RegisterUser;
use App\QuoteRequest\Application\EventHandler\ReleaseRequestsOnJobProposalDecided;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\Fake\AcceptingHumanVerification;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * BO06 / P11.2 — « Je ne trouve pas ma prestation » : métier proposé invisible, demande enregistrée en attente, décision du
 * référentiel (ajout, rattachement, refus) puis publication ou refus des demandes en attente.
 */
final class JobProposalTest extends WebTestCase
{
    use AdminSession;
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f6001';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000b6001';
    private const string EMAIL = 'camille.proposition@exemple.test';
    private const string PASSWORD = 'cheval agrafe batterie';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->dossierDispatch(new RegisterUser(self::CLIENT, self::EMAIL, self::PASSWORD, 'client', 'Camille', 'Exemple'));
        $this->connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :id", ['id' => self::CLIENT]);
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testProposedJobKeepsTheRequestPendingUntilTheBackOfficeAddsIt(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/demande-de-devis/1');
        self::assertSelectorTextContains('.tm-request__propose summary', 'Je ne trouve pas ma prestation');
        $crawler = $this->client->request('POST', '/demande-de-devis/1', ['_token' => $this->token($crawler), 'action' => 'continue', 'project_type' => 'repair', 'proposal_job' => 'Pisciniste fictif', 'proposal_category' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('catégorie la plus proche', $crawler->filter('#proposal_category_error')->text());

        $this->client->request('POST', '/demande-de-devis/1', ['_token' => $this->token($crawler), 'action' => 'continue', 'project_type' => 'repair', 'proposal_job' => 'pisciniste  fictif', 'proposal_category' => 'autre', 'proposal_category_name' => 'Piscine et spa fictifs']);
        self::assertResponseRedirects('/demande-de-devis/2', 303);
        $job = $this->connection->fetchAssociative("SELECT j.id, j.name, j.active, c.active AS category_active, c.icon FROM catalog.job j JOIN catalog.category c ON c.id = j.category_id WHERE j.slug = 'pisciniste-fictif'");
        self::assertIsArray($job);
        self::assertSame(['name' => 'Pisciniste fictif', 'active' => false, 'category_active' => false, 'icon' => 'briefcase'], array_diff_key($job, ['id' => 1]), 'métier et catégorie créés inactifs');
        $jobId = DbalValue::string($job['id']);
        self::assertFalse($this->entry($jobId)->searchable, 'invisible dans la recherche, les menus et les filtres');
        $crawler = $this->client->request('GET', '/demande-de-devis/1');
        self::assertStringContainsString('Pisciniste fictif (proposée, en attente de validation)', $crawler->filter('#job_id option:selected')->text());

        $requestId = $this->finishRequest($crawler);
        $row = $this->connection->fetchAssociative('SELECT status, job_id, risk_signals::text AS signals FROM request.quote_request WHERE id = :id', ['id' => $requestId]);
        self::assertSame(['status' => 'pending_moderation', 'job_id' => $jobId, 'signals' => '["job_proposal"]'], $row, 'demande enregistrée, publiée après validation');

        $this->client->getCookieJar()->clear();
        $this->adminSession($this->client, self::ADMIN, 'content');
        $crawler = $this->client->request('GET', '/admin/referentiel?onglet=propositions');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tm-catalog__proposal', 'Pisciniste fictif');
        self::assertSelectorTextContains('.tm-catalog__proposal', '1 demande en attente');
        $this->client->submit($crawler->selectButton('Ajouter et publier les demandes')->form(), ['name' => 'Pisciniste (fictif)']);
        self::assertResponseRedirects('/admin/referentiel?onglet=propositions', 303);

        self::assertTrue($this->entry($jobId)->searchable, 'catégorie et métier activés : visibles partout');
        self::assertSame('Pisciniste (fictif)', $this->entry($jobId)->name);
        self::assertSame('approved', DbalValue::string($this->connection->fetchOne('SELECT status FROM catalog.job_proposal WHERE job_id = :job', ['job' => $jobId])));
        $this->release(new JobProposalDecided($jobId, 'Pisciniste fictif', $jobId, 'Pisciniste (fictif)', null, new \DateTimeImmutable()));
        self::assertSame('open', DbalValue::string($this->connection->fetchOne('SELECT status FROM request.quote_request WHERE id = :id', ['id' => $requestId])), 'demande publiée (E-10 envoyé par SendRequestSubmitted)');
    }

    public function testMergeReassignsAndRejectRefusesPendingRequests(): void
    {
        $this->dossierDispatch(new ProposeJob('0190f5e4-0000-7000-8000-0000000f6101', 'Plombier chauffagiste fictif', 'plomberie', null, self::CLIENT));
        $proposed = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier-chauffagiste-fictif'");
        $plumber = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new ProposeJob('0190f5e4-0000-7000-8000-0000000f6102', 'Plombier', 'plomberie', null, self::CLIENT));
        self::assertSame('merged', $this->dossierValue("SELECT status FROM catalog.job_proposal WHERE id = '0190f5e4-0000-7000-8000-0000000f6102'"), 'métier déjà actif : repris sans validation');

        $this->dossierDispatch(new DecideJobProposal('0190f5e4-0000-7000-8000-0000000f6101', self::ADMIN, DecideJobProposal::MERGE, replacementSlug: 'plombier'));
        self::assertSame(['status' => 'merged', 'final_job_id' => $plumber], $this->connection->fetchAssociative("SELECT status, final_job_id FROM catalog.job_proposal WHERE id = '0190f5e4-0000-7000-8000-0000000f6101'"));
        self::assertSame($plumber, $this->dossierValue("SELECT replaced_by FROM catalog.job WHERE id = '".$proposed."'"));

        $this->dossierDispatch(new ProposeJob('0190f5e4-0000-7000-8000-0000000f6103', 'Dresseur fictif', 'plomberie', null, null));
        try {
            $this->dossierDispatch(new DecideJobProposal('0190f5e4-0000-7000-8000-0000000f6103', self::ADMIN, DecideJobProposal::REJECT));
            self::fail('motif obligatoire');
        } catch (\Throwable $e) {
            self::assertStringContainsString('motif du refus', $e->getMessage());
        }
        $this->dossierDispatch(new DecideJobProposal('0190f5e4-0000-7000-8000-0000000f6103', self::ADMIN, DecideJobProposal::REJECT, note: 'hors périmètre'));
        self::assertSame('rejected', $this->dossierValue("SELECT status FROM catalog.job_proposal WHERE id = '0190f5e4-0000-7000-8000-0000000f6103'"));
        self::assertFalse($this->entry($this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'dresseur-fictif'"))->searchable);
    }

    private function finishRequest(Crawler $crawler): string
    {
        $token = $this->token($crawler);
        $this->client->request('POST', '/demande-de-devis/1', ['_token' => $token, 'action' => 'continue', 'job_id' => $crawler->filter('#job_id option:selected')->attr('value'), 'project_type' => 'repair']);
        self::assertResponseRedirects('/demande-de-devis/2', 303);
        $this->client->request('POST', '/demande-de-devis/2', ['_token' => $token, 'action' => 'continue', 'description' => 'Entretien du bassin et réglage de la filtration (demande fictive).']);
        $this->client->request('POST', '/demande-de-devis/3', ['_token' => $token, 'action' => 'continue']);
        $this->client->request('POST', '/demande-de-devis/4', ['_token' => $token, 'action' => 'continue', 'address' => '12 rue fictive', 'timing' => 'week', 'occupant_status' => 'owner']);
        $this->client->request('POST', '/demande-de-devis/5', ['_token' => $token, 'action' => 'continue', 'contact_preference' => 'phone_after_acceptance', 'phone' => '06 12 34 56 78']);
        self::assertResponseRedirects('/demande-de-devis/6', 303);
        $this->client->request('POST', '/demande-de-devis/6', ['_token' => $token, 'action' => 'send', 'confirm' => '1', 'cf-turnstile-response' => AcceptingHumanVerification::HUMAN]);
        self::assertResponseRedirects();
        self::assertSame(1, preg_match('~/envoyee/([0-9a-f-]{36})$~', (string) $this->client->getResponse()->headers->get('Location'), $m));

        return $m[1];
    }

    private function entry(string $jobId): JobIndexEntry
    {
        $queries = self::getContainer()->get(QueryBus::class);
        \assert($queries instanceof QueryBus);
        $entries = $queries->ask(new JobIndexEntries([$jobId]));
        \assert(\is_array($entries) && $entries[0] instanceof JobIndexEntry);

        return $entries[0];
    }

    private function release(JobProposalDecided $event): void
    {
        $handler = self::getContainer()->get(ReleaseRequestsOnJobProposalDecided::class);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($handler instanceof ReleaseRequestsOnJobProposalDecided && $em instanceof EntityManagerInterface);
        $em->wrapInTransaction(static fn () => $handler($event));
    }

    private function login(): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => self::EMAIL, '_password' => self::PASSWORD, '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
        self::assertResponseRedirects();
    }

    private function token(Crawler $crawler): string
    {
        return (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
    }
}
