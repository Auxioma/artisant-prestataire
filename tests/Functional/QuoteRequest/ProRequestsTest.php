<?php

declare(strict_types=1);

namespace App\Tests\Functional\QuoteRequest;

use App\Administration\Application\Command\ChangeSetting;
use App\Administration\Infrastructure\Settings\DbalSettingsProvider;
use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Identity\Application\Command\RegisterUser;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Pro\AvailableRequests;
use App\QuoteRequest\Application\Pro\IgnoreRequest;
use App\QuoteRequest\Application\Pro\MarkRequestViewed;
use App\QuoteRequest\Application\Pro\ProRequestAccess;
use App\QuoteRequest\Application\Pro\ProRequestDetail;
use App\QuoteRequest\Application\Pro\ProRequestFilter;
use App\QuoteRequest\Application\Pro\ProRequestPage;
use App\QuoteRequest\Application\Pro\ProRequestView;
use App\QuoteRequest\Application\Pro\RequestState;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\QuoteRequest\Domain\Request\RequestRuleViolation;
use App\Shared\Application\Bus\QueryBus;
use App\Tests\Support\ArtisanDossier;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/** PR02 / PR03 (lecture) : demandes reçues, RG-111, RG-112, « Ignorer », aperçu Q15, droits du Voter. */
final class ProRequestsTest extends KernelTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000e8001';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a8001';
    private const string FIRST = '0190f5e4-0000-7000-8000-0000000e8101';
    private const string SECOND = '0190f5e4-0000-7000-8000-0000000e8102';
    private const string OUTSIDE = '0190f5e4-0000-7000-8000-0000000e8103';

    private Connection $connection;
    private string $artisan;
    private string $job;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->artisan = $this->submittedDossier(200);
        $this->connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :id", ['id' => self::DOSSIER_USER]);
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.pro@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Secret'));
        $this->job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new SubmitQuoteRequest(self::FIRST, self::CLIENT, $this->job, '76351', '76600', '1 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], '500_1000', 'urgent', null, title: 'Fuite sous l’évier'));
        $this->dossierDispatch(new SubmitQuoteRequest(self::SECOND, self::CLIENT, $this->job, '76351', '76600', '2 rue Fictive', 'installation', 'Pose d’un nouveau chauffe-eau dans le garage (fictif).', [], null, 'week', null));
        // Caen : hors de la zone de l'artisan, jamais diffusée chez lui.
        $this->dossierDispatch(new SubmitQuoteRequest(self::OUTSIDE, self::CLIENT, $this->job, '14118', '14000', '3 rue Fictive', 'repair', 'Robinet de la cuisine qui goutte en permanence (fictif).', [], null, 'week', null));
    }

    public function testPendingArtisanPreviewsCompatibleRequestsWithoutAnswering(): void
    {
        $page = $this->page();
        self::assertSame(2, $page->total, 'Q15 : demandes ouvertes de son métier dans sa zone');
        self::assertFalse($page->artisan->verified);
        self::assertSame(RequestState::Preview, $page->items[0]->state);
        self::assertFalse($page->items[0]->answerable());
        self::assertTrue($this->access()->canView(self::DOSSIER_USER, self::FIRST));
        self::assertFalse($this->access()->canView(self::DOSSIER_USER, self::OUTSIDE), 'hors zone');
        self::assertFalse($this->access()->canAnswer(self::DOSSIER_USER, self::FIRST));

        $this->dossierDispatch(new ChangeSetting('pro.preview_before_verified', false, self::ADMIN, 'Test Q15'));
        $this->resetSettings();
        self::assertNull($this->queries()->ask(new AvailableRequests(self::DOSSIER_USER)), 'aperçu désactivé');
        self::assertFalse($this->access()->canView(self::DOSSIER_USER, self::FIRST));
    }

    public function testVerifiedArtisanReadsIgnoresAndLosesUnavailableRequests(): void
    {
        $this->verify();
        foreach ([self::FIRST, self::SECOND, self::OUTSIDE] as $id) {
            $this->dossierDispatch(new DispatchQuoteRequest($id));
        }

        $page = $this->page();
        self::assertSame(2, $page->total, 'reçues seulement');
        $first = $this->find($page, self::FIRST);
        self::assertSame(['Fuite sous l’évier', 'Le Havre (76600)', 5, 'Urgent (sous 48 h)', 'Entre 500 € et 1 000 €', 0, 3, RequestState::Unread], [$first->title, $first->city, $first->distanceKm, $first->timing, $first->budget, $first->quotesSent, $first->maxQuotes, $first->state]);
        self::assertTrue($first->urgent && $first->answerable());
        self::assertSame('Installation · Plombier', $this->find($page, self::SECOND)->title, 'titre généré');
        self::assertSame(self::FIRST, $this->page(new ProRequestFilter(sort: ProRequestFilter::SORT_URGENT))->items[0]->id);
        self::assertFalse($this->access()->canView(self::DOSSIER_USER, self::OUTSIDE), 'non reçue');

        $detail = $this->queries()->ask(new ProRequestDetail(self::DOSSIER_USER, self::FIRST));
        self::assertInstanceOf(ProRequestView::class, $detail);
        self::assertSame('Camille', $detail->clientFirstName, 'RG-112 : prénom seul');
        self::assertStringNotContainsString('Secret', serialize($detail));
        self::assertStringNotContainsString('rue Fictive', serialize($detail), 'jamais l’adresse');

        $this->dossierDispatch(new MarkRequestViewed(self::DOSSIER_USER, self::FIRST));
        self::assertSame(RequestState::Viewed, $this->find($this->page(), self::FIRST)->state);
        self::assertSame([self::SECOND], $this->ids($this->page(new ProRequestFilter(state: RequestState::Unread))));

        $this->dossierDispatch(new IgnoreRequest(self::DOSSIER_USER, self::SECOND, 'too_far'));
        self::assertSame([self::FIRST], $this->ids($this->page()));
        self::assertSame([self::SECOND], $this->ids($this->page(new ProRequestFilter(state: RequestState::Ignored))));
        self::assertSame('too_far', $this->dossierValue("SELECT ignore_reason FROM request.request_dispatch WHERE request_id = '".self::SECOND."'"));
        $this->dossierDispatch(new IgnoreRequest(self::DOSSIER_USER, self::SECOND, restore: true));
        self::assertSame(2, $this->page()->total, 'rétablie');
        try {
            $this->dossierDispatch(new IgnoreRequest(self::DOSSIER_USER, self::OUTSIDE));
            self::fail('demande non reçue');
        } catch (RequestRuleViolation) {
        }

        // RG-111 : annulées — la demande consultée reste en lecture seule, l'autre disparaît.
        $this->connection->executeStatement("UPDATE request.quote_request SET status = 'cancelled', status_reason = 'client' WHERE id IN (:a, :b)", ['a' => self::FIRST, 'b' => self::SECOND]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $page = $this->page();
        self::assertSame([self::FIRST], $this->ids($page));
        self::assertSame(RequestState::Unavailable, $page->items[0]->state);
        self::assertTrue($this->access()->canView(self::DOSSIER_USER, self::FIRST));
        self::assertFalse($this->access()->canAnswer(self::DOSSIER_USER, self::FIRST), 'plus disponible');
        self::assertFalse($this->access()->canView(self::DOSSIER_USER, self::SECOND));
    }

    private function verify(): void
    {
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($this->artisan, self::ADMIN));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function page(ProRequestFilter $filter = new ProRequestFilter()): ProRequestPage
    {
        $page = $this->queries()->ask(new AvailableRequests(self::DOSSIER_USER, $filter));
        self::assertInstanceOf(ProRequestPage::class, $page);

        return $page;
    }

    private function find(ProRequestPage $page, string $id): \App\QuoteRequest\Application\Pro\ProRequestCard
    {
        foreach ($page->items as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }
        self::fail('demande absente de la liste : '.$id);
    }

    /** @return list<string> */
    private function ids(ProRequestPage $page): array
    {
        return array_map(static fn ($c): string => $c->id, $page->items);
    }

    private function queries(): QueryBus
    {
        $bus = self::getContainer()->get(QueryBus::class);
        \assert($bus instanceof QueryBus);

        return $bus;
    }

    private function access(): ProRequestAccess
    {
        $access = self::getContainer()->get(ProRequestAccess::class);
        \assert($access instanceof ProRequestAccess);

        return $access;
    }

    private function resetSettings(): void
    {
        $cache = self::getContainer()->get('cache.settings');
        $settings = self::getContainer()->get(DbalSettingsProvider::class);
        \assert($cache instanceof CacheInterface && $settings instanceof DbalSettingsProvider);
        $cache->delete(DbalSettingsProvider::CACHE_KEY);
        $settings->reset();
    }
}
