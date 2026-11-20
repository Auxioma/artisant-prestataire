<?php

declare(strict_types=1);

namespace App\Tests\Functional\Billing;

use App\Billing\Application\Payment\SyncPlanWithStripe;
use App\Billing\Application\Plan\PlanAction;
use App\Billing\Application\Plan\SavePlan;
use App\Billing\Application\Promo\PromoCodes;
use App\Billing\Application\Promo\PromoEligibility;
use App\Billing\Infrastructure\Payment\InMemoryPaymentGateway;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * BO08.3 (Figma 186:3467) : création d'un code synchronisé avec Stripe (Coupon limité aux forfaits éligibles + Promotion
 * Code), liste, export CSV, désactivation ; règles au tunnel (forfait, par compte, nouveaux abonnés) ; droits.
 */
final class PromoCodesAdminTest extends WebTestCase
{
    use AdminSession;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000ab806';
    private const string PLAN = '0190f5e4-0000-7000-8000-0000000b6607';
    private const string OTHER = '0190f5e4-0000-7000-8000-0000000b6608';

    private KernelBrowser $client;
    private Connection $connection;
    private InMemoryPaymentGateway $gateway;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        $gateway = self::getContainer()->get(InMemoryPaymentGateway::class);
        $commands = self::getContainer()->get(CommandBus::class);
        \assert($connection instanceof Connection && $gateway instanceof InMemoryPaymentGateway && $commands instanceof CommandBus);
        [$this->connection, $this->gateway] = [$connection, $gateway];
        $gateway->reset();
        foreach ([self::PLAN => 'Formule Fictive', self::OTHER => 'Autre Formule Fictive'] as $id => $name) {
            $commands->dispatch(new SavePlan(self::ADMIN, null, $id, ['name' => $name, 'quotes_per_period' => '20', 'monthly_price' => '29,00']));
            $commands->dispatch(new PlanAction(self::ADMIN, $id, PlanAction::PUBLISH));
            $commands->dispatch(new SyncPlanWithStripe($id));
        }
        $gateway->calls = [];
    }

    public function testCreateListExportAndDisable(): void
    {
        $this->adminSession($this->client, self::ADMIN, 'finance');
        $form = $this->client->request('GET', '/admin/forfaits/codes-promo/nouveau')->selectButton('Créer le code promo')->form();
        $form['code'] = 'bienvenue';
        $form['value'] = '20';
        $form['duration'] = 'repeating';
        $form['duration_months'] = '3';
        $form['max_redemptions'] = '500';
        $form['max_per_account'] = '1';
        self::tick($form, 'plans', 'new_subscribers_only');
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/forfaits#promo');

        self::assertSame(['coupon', 'promo'], array_column($this->gateway->calls, 0));
        $coupon = $this->gateway->calls[0][1];
        self::assertSame([20.0, 'repeating', 3], [$coupon['percent'] ?? null, $coupon['duration'] ?? null, $coupon['months'] ?? null]);
        self::assertCount(1, \is_array($coupon['products'] ?? null) ? $coupon['products'] : [], 'limité au forfait éligible');
        self::assertTrue($this->gateway->calls[1][1]['first_time'] ?? null);

        $page = $this->client->request('GET', '/admin/forfaits');
        $row = $page->filter('#promo tbody tr')->text();
        self::assertStringContainsString('BIENVENUE', $row);
        self::assertStringContainsString('−20 %', $row);
        self::assertStringContainsString('3 mois', $row);
        self::assertStringContainsString('0 / 500', $row);
        self::assertStringContainsString('Actif', $row);

        $this->client->request('GET', '/admin/forfaits/codes-promo/export');
        $csv = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringContainsString('BIENVENUE;"−20 %";"3 mois";0;500;1;oui;"Formule Fictive"', $csv);

        $this->client->submit($page->selectButton('Désactiver BIENVENUE')->form());
        self::assertSame('promo_off', $this->gateway->calls[2][0]);
        self::assertStringContainsString('Désactivé', $this->client->request('GET', '/admin/forfaits')->filter('#promo tbody tr')->text());
        self::assertSame(2, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE subject_type = 'promo_code'")));
    }

    public function testDuplicateCodeIsRefused(): void
    {
        $this->adminSession($this->client, self::ADMIN, 'finance');
        foreach ([1, 2] as $_) {
            $form = $this->client->request('GET', '/admin/forfaits/codes-promo/nouveau')->selectButton('Créer le code promo')->form();
            $this->client->submit($form, ['code' => 'ETE2026', 'value' => '30']);
        }
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Ce code existe déjà', (string) $this->client->getResponse()->getContent());
    }

    public function testSupportCannotCreate(): void
    {
        $this->adminSession($this->client, '0190f5e4-0000-7000-8000-0000000ab807', 'support');
        $page = $this->client->request('GET', '/admin/forfaits');
        self::assertCount(0, $page->filter('a[href$="/codes-promo/nouveau"]'), 'support : lecture et désactivation, pas de création');
        $this->client->request('GET', '/admin/forfaits/codes-promo/nouveau');
        self::assertResponseStatusCodeSame(403);
    }

    public function testEligibilityRules(): void
    {
        $this->adminSession($this->client, self::ADMIN, 'finance');
        $form = $this->client->request('GET', '/admin/forfaits/codes-promo/nouveau')->selectButton('Créer le code promo')->form();
        self::tick($form, 'plans', 'new_subscribers_only');
        $this->client->submit($form, ['code' => 'FICTIF', 'value' => '10', 'max_per_account' => '1']);
        $codes = self::getContainer()->get(PromoCodes::class);
        $rules = self::getContainer()->get(PromoEligibility::class);
        \assert($codes instanceof PromoCodes && $rules instanceof PromoEligibility);
        $code = $codes->findByCode('fictif', new \DateTimeImmutable()) ?? self::fail('code absent');
        $artisan = '0190f5e4-0000-7000-8000-0000000c6607';

        self::assertNull($rules->refusal($code, $artisan, self::PLAN, false));
        self::assertSame('Ce code promo ne s’applique pas à cette formule.', $rules->refusal($code, $artisan, self::OTHER, false));
        self::assertSame('Ce code promo est réservé aux nouveaux abonnés.', $rules->refusal($code, $artisan, self::PLAN, true));

        $this->connection->executeStatement("INSERT INTO billing.credit_account (artisan_id, user_id, created_at) VALUES (:a, '0190f5e4-0000-7000-8000-0000000d6607', now())", ['a' => $artisan]);
        $price = $this->connection->fetchOne('SELECT id FROM billing.plan_price WHERE plan_id = :p AND active', ['p' => self::PLAN]);
        $this->connection->executeStatement("INSERT INTO billing.subscription (id, artisan_id, plan_id, plan_price_id, interval, status, created_at, updated_at) VALUES ('0190f5e4-0000-7000-8000-0000000e6607', :a, :p, :price, 'month', 'cancelled', now(), now())", ['a' => $artisan, 'p' => self::PLAN, 'price' => $price]);
        $codes->recordRedemption($code->id, '0190f5e4-0000-7000-8000-0000000e6607', $artisan, new \DateTimeImmutable());
        $code = $codes->findByCode('FICTIF', new \DateTimeImmutable()) ?? self::fail('code absent');
        self::assertSame(1, $code->redemptions);
        self::assertSame('Vous avez déjà utilisé ce code promo.', $rules->refusal($code, $artisan, self::PLAN, false));
    }

    /** Coche la première case de chaque champ (`plans[]` : premier forfait). */
    private static function tick(Form $form, string ...$names): void
    {
        foreach ($names as $name) {
            $field = $form[$name];
            $field = \is_array($field) ? ($field[0] ?? null) : $field;
            \assert($field instanceof ChoiceFormField);
            $field->tick();
        }
    }
}
