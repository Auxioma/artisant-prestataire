<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review;

use App\Administration\Application\Command\ChangeSetting;
use App\Administration\Infrastructure\Settings\DbalSettingsProvider;
use App\ArtisanProfile\Application\Search\SearchableArtisan;
use App\ArtisanProfile\Application\Search\SearchableArtisanOf;
use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Identity\Application\Command\RegisterUser;
use App\Quote\Application\Client\AcceptQuote;
use App\Quote\Application\Editor\SaveQuoteDraft;
use App\Quote\Application\Sending\SendQuote;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Review\Application\EventHandler\NotifyReviewChange;
use App\Review\Application\EventHandler\RefreshArtisanRating;
use App\Review\Application\Review\ReviewAction;
use App\Review\Application\Review\SubmitReview;
use App\Review\Domain\Review\Event\ReviewChanged;
use App\Review\Domain\Review\ReviewRuleViolation;
use App\Search\Application\Artisan\ArtisanSearchIndex;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Moderation\ModerateReview;
use App\Shared\Application\Moderation\ModerationDecisionRejected;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * T-704a — avis certifiés : éligibilité RG-033 (devis accepté, un par demande), fenêtre RG-034, modération a priori ou
 * a posteriori et score de risque RG-035, réponse modérée RG-036, signalement, note reportée dans la recherche, E-18 /
 * E-37 / E-38.
 */
final class ReviewTest extends KernelTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000fd601';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000ad601';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000fd602';
    private const string QUOTE = '0190f5e4-0000-7000-8000-0000000fd603';
    private const string A1 = '0190f5e4-0000-7000-8000-0000000fd6a1';
    private const string R1 = '0190f5e4-0000-7000-8000-0000000fd6b1';
    private const string R2 = '0190f5e4-0000-7000-8000-0000000fd6b2';

    private Connection $connection;
    private string $artisan;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->artisan = $this->submittedDossier(200);
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($this->artisan, self::ADMIN));
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.avis@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Fictive'));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id IN (:a, :b)", ['a' => self::DOSSIER_USER, 'b' => self::CLIENT]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new SubmitQuoteRequest(self::REQUEST, self::CLIENT, $job, '76351', '76600', '1 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], '500_1000', 'week', null, title: 'Fuite sous l’évier'));
        $this->dossierDispatch(new DispatchQuoteRequest(self::REQUEST));
        $this->dossierDispatch(new SaveQuoteDraft(self::DOSSIER_USER, self::REQUEST, self::QUOTE, ['subject' => 'Réparation de la fuite', 'lines' => [
            ['type' => 'labour', 'label' => 'Recherche de fuite', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '90', 'vat' => '1000'],
            ['type' => 'travel', 'label' => 'Déplacement', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '0', 'vat' => '2000'],
        ]]));
        $ledger = self::getContainer()->get(CreditLedger::class);
        \assert($ledger instanceof CreditLedger);
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000fd605', $this->artisan, self::DOSSIER_USER, 3, null, new \DateTimeImmutable());
        $this->dossierDispatch(new SendQuote(self::DOSSIER_USER, self::REQUEST));
        $this->dossierDispatch(new AcceptQuote(self::CLIENT, self::QUOTE));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testWindowModerationReplyAndRating(): void
    {
        // Sans rendez-vous : ouverture 7 jours après l'acceptation.
        $this->expectViolation(fn () => $this->submit(self::R1, 5, self::TEXT), 'à partir du');
        $this->connection->executeStatement("INSERT INTO quote.appointment (id, quote_id, starts_at, ends_at, status, proposed_by, confirmed_at, completed_at, created_at) VALUES (:id, :q, now() - interval '2 days', now() - interval '2 days' + interval '1 hour', 'completed', 'client', now() - interval '4 days', now() - interval '1 day', now() - interval '5 days')", ['id' => self::A1, 'q' => self::QUOTE]);
        $this->expectViolation(fn () => $this->submit(self::R1, 5, 'Trop court.'), 'au moins 50 caractères');
        $this->expectViolation(fn () => $this->submit(self::R1, 0, self::TEXT), 'note de 1 à 5');

        $this->submit(self::R1, 4, self::TEXT, ['quality' => 5, 'punctuality' => 4]);
        self::assertSame('pending', $this->field('status'), 'modération a priori par défaut');
        $this->expectViolation(fn () => $this->submit(self::R2, 5, self::TEXT), 'déjà donné votre avis');
        $this->expectViolation(fn () => $this->dossierDispatch(new SubmitReview('0190f5e4-0000-7000-8000-0000000fd6ff', self::R2, self::REQUEST, 5, [], null, self::TEXT, null, true)), 'accepté le devis');

        $this->dossierDispatch(new ModerateReview(self::ADMIN, self::R1, 'publish'));
        self::assertSame('published', $this->field('status'));
        $searchable = self::getContainer()->get(QueryBus::class);
        $index = self::getContainer()->get(ArtisanSearchIndex::class);
        \assert($searchable instanceof QueryBus && $index instanceof ArtisanSearchIndex);
        $artisan = $searchable->ask(new SearchableArtisanOf($this->artisan));
        self::assertInstanceOf(SearchableArtisan::class, $artisan);
        $index->save($artisan);
        $refresh = self::getContainer()->get(RefreshArtisanRating::class);
        \assert($refresh instanceof RefreshArtisanRating);
        $refresh(new ReviewChanged(self::R1, $this->artisan, 'published', null, new \DateTimeImmutable()));
        self::assertSame(['4.00', 1], [DbalValue::string($this->connection->fetchOne('SELECT rating_avg FROM search.artisan_search_view WHERE artisan_id = :a', ['a' => $this->artisan])), DbalValue::int($this->connection->fetchOne('SELECT reviews_count FROM search.artisan_search_view WHERE artisan_id = :a', ['a' => $this->artisan]))]);

        $notify = self::getContainer()->get(NotifyReviewChange::class);
        \assert($notify instanceof NotifyReviewChange);
        $notify(new ReviewChanged(self::R1, $this->artisan, 'published', null, new \DateTimeImmutable()));
        self::assertStringContainsString('est publié', DbalValue::string($this->connection->fetchOne("SELECT body FROM notification.in_app WHERE code = 'E-18'")));
        self::assertStringContainsString('4/5', DbalValue::string($this->connection->fetchOne("SELECT body FROM notification.in_app WHERE code = 'E-37'")));

        // Réponse de l'artisan (modérée), puis l'avis n'est plus modifiable.
        $this->dossierDispatch(new ReviewAction(self::DOSSIER_USER, self::R1, ReviewAction::ANSWER, 'Merci pour votre confiance, au plaisir (texte fictif).'));
        self::assertSame('pending', $this->field('reply_status'));
        $this->dossierDispatch(new ModerateReview(self::ADMIN, self::R1, 'reply_publish'));
        self::assertSame('published', $this->field('reply_status'));
        $this->expectViolation(fn () => $this->submit(self::R1, 5, self::TEXT), 'a répondu');

        // Signalement : l'avis reste en ligne.
        $this->dossierDispatch(new ReviewAction(self::DOSSIER_USER, self::R1, ReviewAction::REPORT, 'Ce client n’est pas le bon (fictif)'));
        self::assertSame('published', $this->field('status'));
        self::assertNotNull($this->field('reported_at'));
        $this->expectViolation(fn () => $this->dossierDispatch(new ReviewAction(self::CLIENT, self::R1, ReviewAction::ANSWER, 'Réponse usurpée')), 'introuvable');
    }

    public function testAPosterioriRiskRejectionAndDeletion(): void
    {
        $this->connection->executeStatement("INSERT INTO quote.appointment (id, quote_id, starts_at, ends_at, status, proposed_by, confirmed_at, created_at) VALUES (:id, :q, now() - interval '3 hours', now() - interval '2 hours', 'confirmed', 'client', now() - interval '2 days', now() - interval '3 days')", ['id' => self::A1, 'q' => self::QUOTE]);
        $this->setMode('a_posteriori');
        $this->submit(self::R1, 5, self::TEXT);
        self::assertSame('published', $this->field('status'), 'a posteriori : publié aussitôt');
        // Coordonnées dans le texte : score de risque au-delà du seuil → modération.
        $this->connection->executeStatement('UPDATE review.review SET created_at = now() WHERE id = :id', ['id' => self::R1]);
        $this->submit(self::R1, 5, self::TEXT.' Appelez-moi au 06 12 34 56 78 pour la suite.');
        self::assertSame('pending', $this->field('status'));
        self::assertGreaterThanOrEqual(40, DbalValue::int($this->field('risk_score')));

        $this->dossierDispatch(new ModerateReview(self::ADMIN, self::R1, 'reject', 'personal_data'));
        self::assertSame(['rejected', 'personal_data'], [$this->field('status'), $this->field('reason_code')]);
        try {
            $this->dossierDispatch(new ModerateReview(self::ADMIN, self::R1, 'hide'));
            self::fail('masquage sans motif accepté');
        } catch (ModerationDecisionRejected) {
        }

        $this->dossierDispatch(new ReviewAction(self::CLIENT, self::R1, ReviewAction::DELETE));
        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM review.review WHERE id = :id', ['id' => self::R1]));
    }

    private const string TEXT = 'Intervention rapide et soignée, fuite réparée en une heure, je recommande ce professionnel (fictif).';

    /** @param array<string, int> $sub */
    private function submit(string $id, int $rating, string $text, array $sub = []): void
    {
        $this->dossierDispatch(new SubmitReview(self::CLIENT, $id, self::REQUEST, $rating, $sub, 'Très bien (fictif)', $text, null, true));
    }

    private function field(string $column): mixed
    {
        return $this->connection->fetchOne('SELECT '.$column.' FROM review.review WHERE id = :id', ['id' => self::R1]);
    }

    private function setMode(string $mode): void
    {
        $this->dossierDispatch(new ChangeSetting('reviews.moderation_mode', $mode, self::ADMIN, 'Test RG-035 : modération a posteriori'));
        $cache = self::getContainer()->get('cache.settings');
        $settings = self::getContainer()->get(DbalSettingsProvider::class);
        \assert($cache instanceof CacheInterface && $settings instanceof DbalSettingsProvider);
        $cache->delete(DbalSettingsProvider::CACHE_KEY);
        $settings->reset();
    }

    private function expectViolation(callable $action, string $message): void
    {
        try {
            $action();
            self::fail('action acceptée à tort');
        } catch (ReviewRuleViolation $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }
}
