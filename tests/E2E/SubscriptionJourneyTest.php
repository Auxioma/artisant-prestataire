<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Billing\Application\Payment\SyncPlanWithStripe;
use App\Billing\Application\Plan\PlanAction;
use App\Billing\Application\Plan\SavePlan;
use App\Billing\Application\Port\SubscriptionState;
use App\Billing\Infrastructure\Payment\InMemoryPaymentGateway;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use App\Tests\Unit\Integration\StripeWebhookVerifierTest;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * T-902 — souscription Stripe en mode test, de bout en bout : l'artisan choisit un forfait (PR12), part au paiement
 * Stripe Checkout (passerelle de test), Stripe notifie le site par un webhook signé, le worker l'active, les crédits
 * du forfait sont versés et la page « Mon abonnement » (PR11) l'affiche.
 */
final class SubscriptionJourneyTest extends WebTestCase
{
    use ArtisanDossier;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a9031';
    private const string PLAN = '0190f5e4-0000-7000-8000-0000000b9031';
    private const string WEBHOOK_SECRET = 'secret-webhook-fictif';
    private const int WORK_ROUNDS = 10;

    private KernelBrowser $client;
    private Connection $connection;
    private InMemoryPaymentGateway $gateway;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        $gateway = self::getContainer()->get(InMemoryPaymentGateway::class);
        \assert($connection instanceof Connection && $gateway instanceof InMemoryPaymentGateway);
        [$this->connection, $this->gateway] = [$connection, $gateway];
        $gateway->reset();
        $this->prepareReferentials();
        $artisan = $this->submittedDossier(200);
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($artisan, self::ADMIN));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :a", ['a' => self::DOSSIER_USER]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $ledger = self::getContainer()->get(CreditLedger::class);
        \assert($ledger instanceof CreditLedger);
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000f9031', $artisan, self::DOSSIER_USER, 5, null, new \DateTimeImmutable());
        $this->dossierDispatch(new SavePlan(self::ADMIN, null, self::PLAN, ['name' => 'Formule Fictive', 'quotes_per_period' => '20', 'monthly_price' => '29,00', 'yearly_price' => '290,00']));
        $this->dossierDispatch(new PlanAction(self::ADMIN, self::PLAN, PlanAction::PUBLISH));
        $this->dossierDispatch(new SyncPlanWithStripe(self::PLAN));
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $page = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => 'dominique.verif@exemple.test', '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $page->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testSubscribePayByStripeAndGetThePlanCredits(): void
    {
        // 1. Choix du forfait et passage au paiement.
        $page = $this->client->request('GET', '/espace-pro/abonnement/souscrire?forfait='.self::PLAN.'&periode=mois');
        self::assertResponseIsSuccessful();
        $attempt = (string) $page->filter('input[name="attempt"]')->attr('value');
        $form = $page->filter('button[name="action"][value="pay"]')->form();
        foreach (['company_name' => 'Atelier Fictif SARL', 'street' => '12 rue de l’Exemple', 'postal_code' => '76600', 'city' => 'Le Havre', 'country' => 'FR'] as $field => $value) {
            $form[$field] = $value;
        }
        $terms = $form['cgv'];
        \assert($terms instanceof ChoiceFormField);
        $terms->tick();
        $this->client->submit($form);
        self::assertStringStartsWith('https://checkout.stripe.test/', (string) $this->client->getResponse()->headers->get('Location'), 'redirection vers Stripe Checkout');
        self::assertSame('incomplete', $this->connection->fetchOne('SELECT status FROM billing.subscription WHERE id = :id', ['id' => $attempt]));

        // 2. Paiement accepté chez Stripe : l'abonnement existe côté Stripe, qui envoie invoice.paid au site.
        $price = DbalValue::string($this->connection->fetchOne('SELECT stripe_price_id FROM billing.plan_price WHERE plan_id = :p AND interval = \'month\' AND active', ['p' => self::PLAN]));
        $start = new \DateTimeImmutable('-1 minute');
        $end = $start->modify('+1 month');
        $this->gateway->subscriptions['sub_E2E'] = new SubscriptionState('sub_E2E', 'active', $price, $start, $end, false, ['subscription_id' => $attempt]);
        $payload = json_encode(['id' => 'evt_E2E_paid', 'object' => 'event', 'type' => 'invoice.paid', 'livemode' => false, 'data' => ['object' => [
            'id' => 'in_E2E', 'billing_reason' => 'subscription_create', 'amount_paid' => 3480, 'hosted_invoice_url' => 'https://invoice.stripe.test/in_E2E',
            'parent' => ['subscription_details' => ['subscription' => 'sub_E2E']],
            'lines' => ['data' => [['period' => ['start' => $start->getTimestamp(), 'end' => $end->getTimestamp()]]]],
        ]]], \JSON_THROW_ON_ERROR);
        // Stripe appelle le site sans aucun cookie : la session de l'artisan est mise de côté le temps de l'appel.
        $cookies = $this->client->getCookieJar()->all();
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/webhooks/stripe', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => StripeWebhookVerifierTest::sign($payload, self::WEBHOOK_SECRET)], content: $payload);
        self::assertResponseIsSuccessful();
        foreach ($cookies as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }

        // 3. Le worker traite l'événement : abonnement actif, crédits du forfait versés, E-40 envoyé.
        $this->work();
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM billing.subscription WHERE id = :id', ['id' => $attempt]));
        self::assertSame('5|20', DbalValue::string($this->connection->fetchOne("SELECT free || '|' || plan FROM billing.credit_balance b JOIN billing.subscription s ON s.artisan_id = b.artisan_id WHERE s.id = :id", ['id' => $attempt])));
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM notification.delivery WHERE code = 'E-40' AND channel = 'email'")), 'E-40 envoyé une fois');

        // 4. « Mon abonnement » affiche le forfait en cours.
        $page = $this->client->request('GET', '/espace-pro/abonnement');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Formule Fictive', $page->filter('body')->text());
    }

    /** Relais d'outbox et worker (transports des webhooks et asynchrones), comme en production. */
    private function work(): void
    {
        $container = self::getContainer();
        $relay = $container->get(\App\Shared\Infrastructure\Messenger\OutboxRelay::class);
        \assert($relay instanceof \App\Shared\Infrastructure\Messenger\OutboxRelay);
        for ($round = 0; $round < self::WORK_ROUNDS; ++$round) {
            $handled = $relay->relayBatch(500);
            foreach (['stripe_webhooks', 'async_priority', 'async'] as $name) {
                $transport = $container->get('messenger.transport.'.$name);
                \assert($transport instanceof InMemoryTransport);
                foreach ($transport->get() as $envelope) {
                    $bus = $container->get($envelope->last(BusNameStamp::class)?->getBusName() ?? 'command.bus');
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
}
