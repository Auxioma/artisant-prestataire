<?php

declare(strict_types=1);

namespace App\Tests\Functional\Billing;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Billing\Application\Payment\SyncPlanWithStripe;
use App\Billing\Application\Plan\PlanAction;
use App\Billing\Application\Plan\SavePlan;
use App\Billing\Application\Port\PromotionCode;
use App\Billing\Infrastructure\Payment\InMemoryPaymentGateway;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * PR12 (Figma 17:2) : récapitulatif, facturation pré-remplie, VIES, code promo, CGV obligatoires, session Stripe
 * Checkout (double en mémoire) ; abonnement créé « en attente », jamais activé par le retour navigateur.
 */
final class ProSubscribeTest extends WebTestCase
{
    use ArtisanDossier;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000aa604';
    private const string PLAN = '0190f5e4-0000-7000-8000-0000000b6604';

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
        $this->gateway->reset();
        $this->prepareReferentials();
        $artisan = $this->submittedDossier(200);
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($artisan, self::ADMIN));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :a", ['a' => self::DOSSIER_USER]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $ledger = self::getContainer()->get(CreditLedger::class);
        \assert($ledger instanceof CreditLedger);
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000fa604', $artisan, self::DOSSIER_USER, 5, null, new \DateTimeImmutable());
        $this->dossierDispatch(new SavePlan(self::ADMIN, null, self::PLAN, ['name' => 'Formule Fictive', 'quotes_per_period' => '20', 'monthly_price' => '29,00', 'yearly_price' => '290,00']));
        $this->dossierDispatch(new PlanAction(self::ADMIN, self::PLAN, PlanAction::PUBLISH));
        $this->dossierDispatch(new SyncPlanWithStripe(self::PLAN));
        $this->gateway->calls = [];

        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => 'dominique.verif@exemple.test', '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    private function page(string $period = 'mois'): Crawler
    {
        return $this->client->request('GET', '/espace-pro/abonnement/souscrire?forfait='.self::PLAN.'&periode='.$period);
    }

    /** @param array<string, string> $values */
    private function submit(Crawler $page, string $action, array $values = []): Crawler
    {
        $form = $page->filter(\sprintf('button[name="action"][value="%s"]', $action))->form();
        foreach ($values as $field => $value) {
            if ('cgv' === $field) {
                $box = $form['cgv'];
                \assert($box instanceof ChoiceFormField);
                '1' === $value ? $box->tick() : $box->untick();
                continue;
            }
            $form[$field] = $value;
        }

        return $this->client->submit($form);
    }

    private const array BILLING = ['company_name' => 'Atelier Fictif SARL', 'street' => '12 rue de l’Exemple', 'postal_code' => '76600', 'city' => 'Le Havre', 'country' => 'FR'];

    public function testRecapAndPrefilledBilling(): void
    {
        $page = $this->page();
        self::assertResponseIsSuccessful();
        self::assertSame('Souscrire un forfait', $page->filter('h1')->text());
        $recap = $page->filter('.tm-subscribe__recap')->text();
        self::assertStringContainsString('Formule Fictive', $recap);
        self::assertStringContainsString('Mensuelle', $recap);
        self::assertMatchesRegularExpression('/29,00\s€ \/ mois/u', $recap);
        self::assertStringContainsString('20 devis par période', $recap);
        self::assertNotSame('', (string) $page->filter('#company_name')->attr('value'), 'raison sociale reprise du dossier');
        self::assertMatchesRegularExpression('/290,00\s€ \/ an/u', $this->page('an')->filter('.tm-subscribe__recap')->text());
    }

    public function testTermsAndVatAreChecked(): void
    {
        $this->submit($this->page(), 'pay', self::BILLING);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Acceptez les conditions générales', (string) $this->client->getResponse()->getContent());

        $this->submit($this->page(), 'pay', self::BILLING + ['vat_number' => 'FR99999999999', 'cgv' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('pas reconnu par le service européen VIES', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM billing.subscription')));
        self::assertSame([], $this->gateway->calls);
    }

    public function testPromotionCodeIsAppliedWithoutJavascript(): void
    {
        $page = $this->submit($this->page(), 'apply', ['promo' => 'inconnu']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('n’existe pas', $page->filter('.tm-subscribe__terms')->text());

        $this->gateway->promotionCodes['BIENVENUE'] = new PromotionCode('promo_FICTIF', 'BIENVENUE', '−20 % pendant 3 mois');
        $page = $this->submit($this->page(), 'apply', ['promo' => 'bienvenue']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('−20 % pendant 3 mois', $page->filter('.tm-subscribe__recap')->text());
    }

    public function testCheckoutStartsPendingSubscription(): void
    {
        $this->gateway->promotionCodes['BIENVENUE'] = new PromotionCode('promo_FICTIF', 'BIENVENUE', '−20 % pendant 3 mois');
        $page = $this->page();
        $attempt = (string) $page->filter('input[name="attempt"]')->attr('value');
        $this->submit($page, 'pay', self::BILLING + ['vat_number' => 'FR12345678901', 'promo' => 'BIENVENUE', 'cgv' => '1']);

        self::assertResponseStatusCodeSame(303);
        self::assertStringStartsWith('https://checkout.stripe.test/', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame('incomplete', $this->connection->fetchOne('SELECT status FROM billing.subscription WHERE id = :id', ['id' => $attempt]));
        self::assertSame(['Atelier Fictif SARL', 'FR12345678901', true], [
            $this->connection->fetchOne('SELECT company_name FROM billing.billing_profile'),
            $this->connection->fetchOne('SELECT vat_number FROM billing.billing_profile'),
            null !== $this->connection->fetchOne('SELECT vat_verified_at FROM billing.billing_profile'),
        ]);
        $kinds = array_column($this->gateway->calls, 0);
        self::assertSame(['cus', 'customer_update', 'checkout'], $kinds);
        $checkout = $this->gateway->calls[2][1];
        $metadata = $checkout['metadata'] ?? [];
        \assert(\is_array($metadata));
        self::assertSame([$attempt, 'promo_FICTIF', true, $attempt], [$checkout['reference'] ?? null, $checkout['promotion'] ?? null, $checkout['tax'] ?? null, $metadata['subscription_id'] ?? null]);
        self::assertStringEndsWith('/espace-pro/abonnement/confirmation/'.$attempt, \is_string($checkout['success'] ?? null) ? $checkout['success'] : '');
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE action = 'billing.checkout_started'")));

        $confirmation = $this->client->request('GET', '/espace-pro/abonnement/confirmation/'.$attempt);
        self::assertResponseIsSuccessful();
        self::assertSame('Paiement en cours de confirmation', $confirmation->filter('h1')->text());
        self::assertSame('incomplete', $this->connection->fetchOne('SELECT status FROM billing.subscription WHERE id = :id', ['id' => $attempt]), 'le retour navigateur n’active rien');
        $this->client->request('GET', '/espace-pro/abonnement/confirmation/0190f5e4-0000-7000-8000-0000000e9999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnavailablePlanAndClosedPayment(): void
    {
        $this->client->request('GET', '/espace-pro/abonnement/souscrire?forfait=0190f5e4-0000-7000-8000-0000000b9999');
        self::assertResponseRedirects('/espace-pro/abonnement');

        $this->gateway->configured = false;
        $page = $this->page();
        self::assertStringContainsString('pas encore ouvert', $page->filter('.tm-subscribe__notice')->text());
        self::assertNotNull($page->filter('button[value="pay"]')->attr('disabled'));
    }
}
