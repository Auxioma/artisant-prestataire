<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Identity\Application\Command\RegisterUser;
use App\Identity\Application\Privacy\EraseDueAccounts;
use App\Quote\Application\Editor\SaveQuoteDraft;
use App\Quote\Application\EventHandler\CloseQuotesWithRequest;
use App\Quote\Application\EventHandler\NotifyQuoteDecision;
use App\Quote\Application\Sending\SendQuote;
use App\Quote\Domain\Quote\Event\QuoteStatusChanged;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\EventHandler\CancelRequestsOfErasedAccount;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\QuoteRequest\Domain\Request\Event\QuoteRequestStatusChanged;
use App\Search\Application\EventHandler\ForgetFavoritesOfErasedAccount;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Event\AccountErased;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** T-705d2 — C07.4 « Supprimer mon compte » : demande (E-08), rétractation, effacement effectif (RG-090, E-09). */
final class AccountDeletionTest extends WebTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f7a01';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a7a01';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000f7a02';
    private const string QUOTE = '0190f5e4-0000-7000-8000-0000000f7a03';
    private const string EMAIL = 'camille.rg090@exemple.test';
    private const string PASSWORD = 'cheval agrafe batterie';

    private KernelBrowser $client;
    private Connection $connection;
    private string $artisan;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->artisan = $this->submittedDossier(200);
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($this->artisan, self::ADMIN));
        $this->dossierDispatch(new RegisterUser(self::CLIENT, self::EMAIL, self::PASSWORD, 'client', 'Camille', 'Fictive'));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id IN (:a, :b)", ['a' => self::DOSSIER_USER, 'b' => self::CLIENT]);
        $connection->executeStatement("INSERT INTO identity.client_profile (user_id, type, postal_code, interests, created_at, updated_at) VALUES (:c, 'particulier', '76600', '[\"jardin\"]', date_trunc('second', now()), date_trunc('second', now()))", ['c' => self::CLIENT]);
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
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000f7a05', $this->artisan, self::DOSSIER_USER, 3, null, new \DateTimeImmutable());
        $this->dossierDispatch(new SendQuote(self::DOSSIER_USER, self::REQUEST));
        $connection->executeStatement('INSERT INTO search.favorite (user_id, artisan_id, created_at) VALUES (:u, :a, now())', ['u' => self::CLIENT, 'a' => $this->artisan]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->login();
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    private function login(): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => self::EMAIL, '_password' => self::PASSWORD, '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    /** Même lien que l'e-mail E-08 (signé, valable jusqu'à la date d'effacement). */
    private function cancelLink(): string
    {
        $signer = self::getContainer()->get(UriSigner::class);
        $urls = self::getContainer()->get(UrlGeneratorInterface::class);
        \assert($signer instanceof UriSigner && $urls instanceof UrlGeneratorInterface);

        return $signer->sign($urls->generate('identity_deletion_cancel', ['user' => self::CLIENT], UrlGeneratorInterface::ABSOLUTE_URL), new \DateTimeImmutable('+14 days'));
    }

    public function testRequestCancelThenEraseAfterGracePeriod(): void
    {
        $page = $this->client->request('GET', '/espace-client/donnees');
        self::assertStringContainsString('Client vérifié', $page->filter('.tm-account__consequences')->text());
        $form = $page->filter('#suppression-compte form')->form();
        $this->client->submit($form, ['password' => 'mauvais mot de passe']);
        self::assertResponseRedirects('/espace-client/donnees?suppression=1');
        self::assertSame('active', $this->stateOf());

        $this->client->submit($form, ['password' => self::PASSWORD]);
        self::assertResponseRedirects('/compte/suppression');
        self::assertSame('deletion_requested', $this->stateOf());
        $this->client->request('GET', '/espace-client/donnees');
        self::assertResponseRedirects();
        self::assertSame('sent', DbalValue::string($this->connection->fetchOne("SELECT status FROM notification.delivery WHERE code = 'E-08'")));

        // Rétractation : lien signé + mot de passe.
        $link = $this->cancelLink();
        $this->client->request('GET', $link.'x');
        self::assertResponseStatusCodeSame(404);
        $page = $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        $this->client->submit($page->selectButton('Annuler la suppression')->form(), ['password' => self::PASSWORD]);
        self::assertResponseRedirects('/connexion');
        self::assertSame('active', $this->stateOf());

        // Nouvelle demande, délai écoulé : effacement.
        $this->login();
        $page = $this->client->request('GET', '/espace-client/donnees');
        $this->client->submit($page->filter('#suppression-compte form')->form(), ['password' => self::PASSWORD]);
        $commands = self::getContainer()->get(CommandBus::class);
        \assert($commands instanceof CommandBus);
        $commands->dispatch(new EraseDueAccounts());
        self::assertSame('deletion_requested', $this->stateOf(), 'délai de rétractation non écoulé');
        $this->connection->executeStatement("UPDATE identity.user_account SET deletion_requested_at = date_trunc('second', now()) - interval '15 days' WHERE id = :u", ['u' => self::CLIENT]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $commands->dispatch(new EraseDueAccounts());
        self::assertSame('anonymized', $this->stateOf());
        $row = $this->connection->fetchAssociative('SELECT email, first_name, phone FROM identity.user_account WHERE id = :u', ['u' => self::CLIENT]);
        \assert(\is_array($row));
        self::assertStringEndsWith('@anonymise.invalid', DbalValue::string($row['email']));
        self::assertSame('Utilisateur', $row['first_name']);
        self::assertSame('[]', DbalValue::string($this->connection->fetchOne('SELECT interests::text FROM identity.client_profile WHERE user_id = :u', ['u' => self::CLIENT])));
        self::assertSame(0, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM identity.session_device WHERE user_id = :u', ['u' => self::CLIENT])));
        self::assertSame('sent', DbalValue::string($this->connection->fetchOne("SELECT status FROM notification.delivery WHERE code = 'E-09'")));

        // Réactions des modules (worker) : demande annulée, artisan prévenu, favoris effacés.
        $erased = new AccountErased(self::CLIENT, new \DateTimeImmutable());
        $cancel = self::getContainer()->get(CancelRequestsOfErasedAccount::class);
        $favorites = self::getContainer()->get(ForgetFavoritesOfErasedAccount::class);
        $close = self::getContainer()->get(CloseQuotesWithRequest::class);
        $notify = self::getContainer()->get(NotifyQuoteDecision::class);
        \assert($cancel instanceof CancelRequestsOfErasedAccount && $favorites instanceof ForgetFavoritesOfErasedAccount && $close instanceof CloseQuotesWithRequest && $notify instanceof NotifyQuoteDecision);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->wrapInTransaction(static fn () => $cancel($erased));
        $favorites($erased);
        self::assertSame('cancelled', DbalValue::string($this->connection->fetchOne('SELECT status FROM request.quote_request WHERE id = :r', ['r' => self::REQUEST])));
        self::assertSame(0, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM search.favorite WHERE user_id = :u', ['u' => self::CLIENT])));
        $em->wrapInTransaction(static fn () => $close(new QuoteRequestStatusChanged(self::REQUEST, self::CLIENT, 'open', 'cancelled', CancelRequestsOfErasedAccount::REASON, new \DateTimeImmutable())));
        self::assertSame('request_cancelled', DbalValue::string($this->connection->fetchOne('SELECT status FROM quote.quote WHERE id = :q', ['q' => self::QUOTE])));
        $notify(new QuoteStatusChanged(self::QUOTE, self::REQUEST, $this->artisan, 'sent', 'request_cancelled', null, new \DateTimeImmutable()));
        self::assertSame('sent', DbalValue::string($this->connection->fetchOne("SELECT status FROM notification.delivery WHERE code = 'E-35' AND channel = 'email'")));
    }

    private function stateOf(): string
    {
        return DbalValue::string($this->connection->fetchOne('SELECT status FROM identity.user_account WHERE id = :u', ['u' => self::CLIENT]));
    }
}
