<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Identity\Application\Command\RegisterUser;
use App\Shared\Infrastructure\Messenger\OutboxRelay;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\Fake\AcceptingHumanVerification;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * T-902 — parcours critique de bout en bout, uniquement par les pages et formulaires du site : le client dépose sa
 * demande (P11), le travail de fond la diffuse (outbox → Messenger), l'artisan envoie son devis (PR04), le client
 * l'accepte (C03) puis donne son avis (C06). Seuls les préalables (artisan vérifié, crédits, compte client) et le
 * passage du temps (intervention réalisée) sont posés directement.
 */
final class CriticalJourneyTest extends WebTestCase
{
    use ArtisanDossier;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a9021';
    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f9021';
    private const string CLIENT_EMAIL = 'camille.e2e@exemple.test';
    private const string PASSWORD = 'cheval agrafe batterie';
    private const int WORK_ROUNDS = 10;
    private const int WORK_BATCH = 500;

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
        $artisan = $this->submittedDossier(200);
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($artisan, self::ADMIN));
        $ledger = self::getContainer()->get(CreditLedger::class);
        \assert($ledger instanceof CreditLedger);
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000f9022', $artisan, self::DOSSIER_USER, 3, null, new \DateTimeImmutable());
        $this->dossierDispatch(new RegisterUser(self::CLIENT, self::CLIENT_EMAIL, self::PASSWORD, 'client', 'Camille', 'Fictive'));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id IN (:a, :b)", ['a' => self::DOSSIER_USER, 'b' => self::CLIENT]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->work();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testRequestQuoteAcceptanceAndReview(): void
    {
        // 1. Le client dépose sa demande en six étapes.
        $this->login(self::CLIENT_EMAIL);
        $jobId = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $page = $this->client->request('GET', '/demande-de-devis/1');
        $this->client->request('POST', '/demande-de-devis/1', ['_token' => $this->token($page), 'action' => 'continue', 'job_id' => $jobId, 'project_type' => 'repair']);
        $this->client->request('POST', '/demande-de-devis/2', ['_token' => $this->token($page), 'action' => 'continue', 'description' => 'Fuite sous l’évier de la cuisine depuis ce matin (parcours E2E fictif).']);
        $this->client->request('POST', '/demande-de-devis/3', ['_token' => $this->token($page), 'action' => 'continue']);
        $this->client->request('POST', '/demande-de-devis/4', ['_token' => $this->token($page), 'action' => 'continue', 'address' => '12 rue fictive', 'timing' => 'week', 'occupant_status' => 'owner']);
        $this->client->request('POST', '/demande-de-devis/5', ['_token' => $this->token($page), 'action' => 'continue', 'contact_preference' => 'messaging_only', 'phone' => '06 12 34 56 78']);
        $this->client->request('POST', '/demande-de-devis/6', ['_token' => $this->token($page), 'action' => 'send', 'confirm' => '1', 'cf-turnstile-response' => AcceptingHumanVerification::HUMAN]);
        self::assertResponseRedirects();
        self::assertSame(1, preg_match('~^/demande-de-devis/envoyee/([0-9a-f-]{36})$~', (string) $this->client->getResponse()->headers->get('Location'), $m));
        $requestId = $m[1];
        self::assertSame('open', $this->dossierValue("SELECT status FROM request.quote_request WHERE id = '".$requestId."'"));

        // 2. Travail de fond : diffusion aux artisans éligibles (RG-062) et notifications.
        $this->work();
        self::assertSame(1, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM request.request_dispatch WHERE request_id = :r', ['r' => $requestId])), 'artisan du Havre notifié');

        // 3. L'artisan la trouve dans ses demandes et envoie un devis.
        $this->switchTo('dominique.verif@exemple.test');
        $page = $this->client->request('GET', '/espace-pro/demandes');
        self::assertStringContainsString('Fuite', $page->filter('body')->text());
        $page = $this->client->request('GET', '/espace-pro/devis/nouveau?demande='.$requestId);
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/espace-pro/devis/brouillon?demande='.$requestId, ['_token' => $this->token($page), 'subject' => 'Réparation de la fuite', 'validity_days' => '30', 'lines' => [
            ['type' => 'labour', 'label' => 'Recherche et réparation de fuite', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '120,00', 'vat' => '1000'],
            ['type' => 'travel', 'label' => 'Déplacement', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '0,00', 'vat' => '2000'],
        ]]);
        $page = $this->client->request('GET', '/espace-pro/devis/nouveau?demande='.$requestId);
        $this->client->submit($page->filter('#envoi-devis button[type="submit"]')->form());
        self::assertResponseRedirects('/espace-pro/devis/nouveau?demande='.$requestId);
        $quoteId = $this->dossierValue("SELECT id FROM quote.quote WHERE request_id = '".$requestId."'");
        self::assertSame('sent', $this->dossierValue("SELECT status FROM quote.quote WHERE id = '".$quoteId."'"));
        $this->work();

        // 4. Le client compare et accepte.
        $this->switchTo(self::CLIENT_EMAIL);
        $page = $this->client->request('GET', '/espace-client/demandes/'.$requestId);
        self::assertSame('Comparateur de devis', $page->filter('.tm-cspace__banner-title')->text());
        $this->client->submit($page->filter('#accepter-'.$quoteId.' button[type="submit"]')->form());
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Devis accepté', $page->filter('.tm-toasts')->text());
        self::assertSame('accepted', $this->dossierValue("SELECT status FROM quote.quote WHERE id = '".$quoteId."'"));
        $this->work();

        // 5. Après l'intervention (temps simulé), le client donne son avis.
        $this->connection->executeStatement("INSERT INTO quote.appointment (id, quote_id, starts_at, ends_at, status, proposed_by, confirmed_at, completed_at, created_at) VALUES ('0190f5e4-0000-7000-8000-0000000f9023', :q, now() - interval '2 days', now() - interval '2 days' + interval '1 hour', 'completed', 'client', now() - interval '4 days', now() - interval '1 day', now() - interval '5 days')", ['q' => $quoteId]);
        $page = $this->client->request('GET', '/espace-client/avis');
        $form = $this->client->click($page->filter('.tm-reviews__give')->selectLink('Donner mon avis')->link())->filter('form#avis')->form();
        $this->client->submit($form, ['note' => '5', 'titre' => 'Fuite réparée rapidement (fictif)', 'avis' => 'Intervention rapide, travail soigné, explications claires. Je recommande (parcours de test fictif).', 'recommande' => 'oui']);
        self::assertResponseRedirects('/espace-client/avis?onglet=publies', 303);
        self::assertContains($this->dossierValue('SELECT status FROM review.review WHERE quote_id = \''.$quoteId.'\''), ['pending', 'published'], 'avis enregistré (modération selon le score)');
        $this->work();
        self::assertGreaterThan(0, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM notification.delivery WHERE code IN ('E-30', 'E-31', 'E-11', 'E-12')")), 'notifications du parcours envoyées');
    }

    /**
     * Ce que font le relais d'outbox et le worker en production : événements relayés vers Messenger, puis chaque message
     * des transports asynchrones traité sur son bus (marqué « reçu », comme par `messenger:consume`), jusqu'à épuisement.
     */
    private function work(): void
    {
        $container = self::getContainer();
        $relay = $container->get(OutboxRelay::class);
        \assert($relay instanceof OutboxRelay);
        for ($round = 0; $round < self::WORK_ROUNDS; ++$round) {
            $handled = $relay->relayBatch(self::WORK_BATCH);
            foreach (['async_priority', 'async'] as $name) {
                $transport = $container->get('messenger.transport.'.$name);
                \assert($transport instanceof InMemoryTransport);
                foreach ($transport->get() as $envelope) {
                    $bus = $container->get($envelope->last(BusNameStamp::class)?->getBusName() ?? 'event.bus');
                    \assert($bus instanceof MessageBusInterface);
                    $transport->ack($envelope);
                    $bus->dispatch($envelope->with(new ReceivedStamp($name)));
                    ++$handled;
                }
            }
            if (0 === $handled) {
                break;
            }
        }
        $container->get(EntityManagerInterface::class)->clear();
    }

    private function switchTo(string $email): void
    {
        $this->client->getCookieJar()->clear();
        $this->login($email);
    }

    private function login(string $email): void
    {
        $page = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => $email, '_password' => self::PASSWORD, '_csrf_token' => (string) $page->filter('input[name="_csrf_token"]')->attr('value')]);
        self::assertResponseRedirects();
    }

    private function token(Crawler $page): string
    {
        return (string) $page->filter('input[name="_token"]')->first()->attr('value');
    }
}
