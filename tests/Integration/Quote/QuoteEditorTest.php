<?php

declare(strict_types=1);

namespace App\Tests\Integration\Quote;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Identity\Application\Command\RegisterUser;
use App\Quote\Application\Editor\QuoteEditor;
use App\Quote\Application\Editor\QuoteEditorView;
use App\Quote\Application\Editor\SaveQuoteDraft;
use App\Quote\Domain\Quote\QuoteRuleViolation;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** T-502a — brouillon de devis (création, mise à jour, droits) et vue de l'éditeur (RG-120). */
final class QuoteEditorTest extends KernelTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f1001';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a1001';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000f1101';
    private const string OUTSIDE = '0190f5e4-0000-7000-8000-0000000f1102';
    private const string QUOTE = '0190f5e4-0000-7000-8000-0000000f1201';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $artisan = $this->submittedDossier(200);
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($artisan, self::ADMIN));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :id", ['id' => self::DOSSIER_USER]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.editeur@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Éditeur'));
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new SubmitQuoteRequest(self::REQUEST, self::CLIENT, $job, '76351', '76600', '1 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], null, 'week', null, title: 'Fuite sous l’évier'));
        $this->dossierDispatch(new SubmitQuoteRequest(self::OUTSIDE, self::CLIENT, $job, '14118', '14000', '2 rue Fictive', 'repair', 'Robinet de la cuisine qui goutte en permanence (fictif).', [], null, 'week', null));
        $this->dossierDispatch(new DispatchQuoteRequest(self::REQUEST));
    }

    public function testDraftIsCreatedThenUpdatedAndMentionsAreChecked(): void
    {
        $input = ['subject' => 'Réparation de la fuite', 'lines' => [['type' => 'labour', 'label' => 'Recherche de fuite', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '90', 'vat' => '1000']]];
        $this->dossierDispatch(new SaveQuoteDraft(self::DOSSIER_USER, self::REQUEST, self::QUOTE, $input));
        $input['lines'][] = ['type' => 'travel', 'label' => 'Déplacement', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '0', 'vat' => '2000'];
        $this->dossierDispatch(new SaveQuoteDraft(self::DOSSIER_USER, self::REQUEST, '0190f5e4-0000-7000-8000-0000000f1299', $input));
        self::assertSame(['1', 'draft'], [$this->dossierValue('SELECT count(*)::text FROM quote.quote'), $this->dossierValue('SELECT status FROM quote.quote')], 'un seul brouillon, mis à jour');
        self::assertSame(2, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM quote.quote_line')));

        $view = $this->editor(self::REQUEST);
        self::assertInstanceOf(QuoteEditorView::class, $view);
        self::assertSame([self::QUOTE, 'Fuite sous l’évier', 9900], [$view->quoteId, $view->request->title, $view->content?->totals->ttc->amount]);
        self::assertSame(['Atelier Fictif', 'PLOMBERIE FICTIVE DE TEST'], [$view->issuer->name, $view->issuer->legalName]);
        $failed = array_values(array_map(static fn ($c): string => $c->mention->value, array_filter($view->mentions, static fn ($c): bool => !$c->ok)));
        self::assertNotContains('travel_costs', $failed, 'ligne « Déplacement » présente');
        self::assertNotContains('company_identity', $failed);
    }

    public function testOnlyRequestsTheArtisanCanAnswer(): void
    {
        self::assertNull($this->editor(self::OUTSIDE), 'demande non reçue');
        $this->expectException(QuoteRuleViolation::class);
        $this->dossierDispatch(new SaveQuoteDraft(self::DOSSIER_USER, self::OUTSIDE, self::QUOTE, ['subject' => 'X', 'lines' => []]));
    }

    private function editor(string $requestId): ?QuoteEditorView
    {
        $queries = self::getContainer()->get(QueryBus::class);
        \assert($queries instanceof QueryBus);
        $view = $queries->ask(new QuoteEditor(self::DOSSIER_USER, $requestId));

        return $view instanceof QuoteEditorView ? $view : null;
    }
}
