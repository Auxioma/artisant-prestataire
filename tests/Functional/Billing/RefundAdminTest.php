<?php

declare(strict_types=1);

namespace App\Tests\Functional\Billing;

use App\Billing\Application\Webhook\HandleStripeEvent;
use App\Billing\Application\Webhook\HandleStripeEventHandler;
use App\Billing\Application\Webhook\ProcessedEvents;
use App\Billing\Application\Webhook\StripeEvent;
use App\Billing\Infrastructure\Payment\InMemoryPaymentGateway;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * T-606b : facturation d'un artisan au back-office, remboursement partiel puis du solde (avoirs Stripe, E-48 une fois
 * par avoir, journal), montant excessif refusé, avoir émis depuis Stripe répliqué sans double e-mail, droits.
 */
final class RefundAdminTest extends WebTestCase
{
    use AdminSession;
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000ab816';
    private const string ARTISAN = '0190f5e4-0000-7000-8000-0000000c6616';
    private const string USER = '0190f5e4-0000-7000-8000-0000000d6616';
    private const string INVOICE = '0190f5e4-0000-7000-8000-00000001c616';

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
        $connection->executeStatement(
            "INSERT INTO identity.user_account (id, email, email_canonical, password_hash, passwordless, kind, status, first_name, last_name, email_verified_at, password_changed_at, created_at, updated_at) VALUES (:id, 'artisan.rembourse@exemple.test', 'artisan.rembourse@exemple.test', :hash, false, 'artisan', 'active', 'Artisan', 'Fictif', :at, :at, :at, :at)",
            ['id' => self::USER, 'hash' => '$argon2id$fictif', 'at' => '2026-11-14 10:00:00+00'],
        );
        $connection->executeStatement("INSERT INTO billing.credit_account (artisan_id, user_id, stripe_customer_id, created_at) VALUES (:a, :u, 'cus_REMB', now())", ['a' => self::ARTISAN, 'u' => self::USER]);
        $connection->executeStatement(
            "INSERT INTO billing.invoice (id, artisan_id, stripe_invoice_id, number, status, currency, amount_ht_cents, vat_cents, amount_ttc_cents, amount_paid_cents, issued_at, paid_at, updated_at) VALUES (:id, :a, 'in_REMB', 'TM-FICTIF-0100', 'paid', 'EUR', 2900, 580, 3480, 3480, now(), now(), now())",
            ['id' => self::INVOICE, 'a' => self::ARTISAN],
        );
    }

    private function rows(string $sql): int
    {
        return DbalValue::int($this->connection->fetchOne($sql));
    }

    public function testPartialThenRemainingRefund(): void
    {
        $this->adminSession($this->client, self::ADMIN, 'finance');
        $page = $this->client->request('GET', '/admin/facturation/artisans/'.self::ARTISAN);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('TM-FICTIF-0100', $page->filter('table')->text());

        $form = $this->client->click($page->selectLink('Rembourser la facture TM-FICTIF-0100')->link())->selectButton('Rembourser')->form();
        $this->client->submit($form, ['scope' => 'partial', 'amount' => '50,00', 'reason' => 'product_unsatisfactory', 'comment' => 'Geste fictif']);
        self::assertResponseStatusCodeSame(422, 'plus que le solde : refusé');

        $this->client->submit($form, ['scope' => 'partial', 'amount' => '10,00', 'reason' => 'product_unsatisfactory', 'comment' => 'Geste commercial fictif']);
        self::assertResponseRedirects('/admin/facturation/artisans/'.self::ARTISAN);
        self::assertSame([1000, false], [$this->gateway->calls[0][1]['amount'] ?? null, $this->gateway->calls[0][1]['full'] ?? null]);
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Remboursée en partie', $page->filter('table')->first()->text());

        $form = $this->client->click($page->selectLink('Rembourser la facture TM-FICTIF-0100')->link())->selectButton('Rembourser')->form();
        $this->client->submit($form, ['scope' => 'full', 'reason' => 'order_change', 'comment' => 'Solde fictif']);
        self::assertSame(2480, $this->gateway->calls[1][1]['amount'] ?? null);
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Remboursée', $page->filter('table')->first()->text());
        self::assertCount(0, $page->selectLink('Rembourser la facture TM-FICTIF-0100'), 'plus rien à rembourser');

        self::assertSame(2, $this->rows("SELECT count(*) FROM billing.credit_note WHERE invoice_id = '".self::INVOICE."'"));
        self::assertSame(2, $this->rows("SELECT count(*) FROM admin.audit_log WHERE action = 'billing.refunded'"));
        self::assertSame(2, $this->rows("SELECT count(*) FROM notification.delivery WHERE code = 'E-48' AND channel = 'email'"));

        // Le webhook du même avoir n'envoie pas un second e-mail ; un avoir créé dans Stripe est répliqué et annoncé.
        $events = self::getContainer()->get(ProcessedEvents::class);
        $handler = self::getContainer()->get(HandleStripeEventHandler::class);
        \assert($events instanceof ProcessedEvents && $handler instanceof HandleStripeEventHandler);
        $known = DbalValue::string($this->connection->fetchOne('SELECT stripe_credit_note_id FROM billing.credit_note ORDER BY created_at LIMIT 1'));
        foreach (['evt_cn1' => $known, 'evt_cn2' => 'cn_DASHBOARD'] as $event => $note) {
            $events->record(new StripeEvent($event, 'credit_note.created', false, ['id' => $note, 'invoice' => 'in_REMB', 'total' => 500, 'refund_amount' => 500, 'number' => 'AV-FICTIF']), 'x', new \DateTimeImmutable());
            $handler(new HandleStripeEvent($event));
        }
        self::assertSame(3, $this->rows("SELECT count(*) FROM billing.credit_note WHERE invoice_id = '".self::INVOICE."'"));
        self::assertSame(3, $this->rows("SELECT count(*) FROM notification.delivery WHERE code = 'E-48' AND channel = 'email'"));
    }

    public function testSupportCannotRefund(): void
    {
        $this->adminSession($this->client, self::ADMIN, 'support');
        $page = $this->client->request('GET', '/admin/facturation/artisans/'.self::ARTISAN);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->selectLink('Rembourser la facture TM-FICTIF-0100'));
        $this->client->request('GET', '/admin/facturation/factures/'.self::INVOICE.'/rembourser');
        self::assertResponseStatusCodeSame(403);
    }
}
