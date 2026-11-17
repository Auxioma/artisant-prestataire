<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Billing\Application\Plan\AdminPlans;
use App\Billing\Application\Plan\PlanAction;
use App\Billing\Application\Plan\PlanView;
use App\Billing\Application\Plan\PublishedPlans;
use App\Billing\Application\Plan\SavePlan;
use App\Billing\Domain\Plan\PlanRuleViolation;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * T-601 — forfaits BO08.1 : Q5 seedés en brouillon sans prix ; création, prix versionnés (l'ancien reste à l'historique),
 * publication, page Tarifs (publiés et publics), ordre, suppression d'un brouillon, journal.
 */
final class PlanHandlerTest extends KernelTestCase
{
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a6101';
    private const string PLAN = '0190f5e4-0000-7000-8000-0000000b6101';

    private CommandBus $commands;
    private QueryBus $queries;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $commands = self::getContainer()->get(CommandBus::class);
        $queries = self::getContainer()->get(QueryBus::class);
        $connection = self::getContainer()->get(Connection::class);
        \assert($commands instanceof CommandBus && $queries instanceof QueryBus && $connection instanceof Connection);
        [$this->commands, $this->queries, $this->connection] = [$commands, $queries, $connection];
    }

    public function testSeededPlansAreDraftsWithoutPrices(): void
    {
        $plans = $this->plans(new AdminPlans());
        self::assertSame(['Essentiel', 'Pro', 'Premium'], array_map(static fn (PlanView $p): string => $p->terms->name, $plans));
        self::assertSame([null, null, null], array_map(static fn (PlanView $p): ?int => $p->monthlyCents, $plans), 'Q5 : aucun prix inventé');
        self::assertSame([], $this->plans(new PublishedPlans()));
    }

    public function testCreatePricePublishReorderAndDelete(): void
    {
        $this->commands->dispatch(new SavePlan(self::ADMIN, null, self::PLAN, ['name' => 'Partenaire Fictif', 'quotes_per_period' => '15', 'monthly_price' => '19,00', 'visibility' => 'private', 'access_code' => 'fictif-76', 'features' => "Avantage A\nAvantage B"]));
        $created = $this->plan('partenaire-fictif');
        self::assertSame([1900, null, 'FICTIF-76', ['Avantage A', 'Avantage B'], 4], [$created->monthlyCents, $created->yearlyCents, $created->terms->accessCode, $created->terms->features, $created->position]);

        $this->commands->dispatch(new SavePlan(self::ADMIN, self::PLAN, '0190f5e4-0000-7000-8000-0000000b6102', ['name' => 'Partenaire Fictif', 'quotes_per_period' => '15', 'monthly_price' => '24', 'yearly_price' => '240,00', 'visibility' => 'private', 'access_code' => 'FICTIF-76']));
        self::assertSame(['1900:false', '2400:true', '24000:true'], $this->connection->fetchFirstColumn("SELECT amount_cents || ':' || active FROM billing.plan_price WHERE plan_id = :p ORDER BY amount_cents", ['p' => self::PLAN]), 'ancien prix gardé, inactif');

        $this->commands->dispatch(new PlanAction(self::ADMIN, self::PLAN, PlanAction::PUBLISH));
        self::assertSame([], $this->plans(new PublishedPlans()), 'forfait privé : absent de la page Tarifs');
        $this->commands->dispatch(new PlanAction(self::ADMIN, self::PLAN, PlanAction::UP));
        self::assertSame(3, $this->plan('partenaire-fictif')->position);

        try {
            $this->commands->dispatch(new PlanAction(self::ADMIN, self::PLAN, PlanAction::DELETE));
            self::fail('forfait publié supprimé');
        } catch (PlanRuleViolation $e) {
            self::assertStringContainsString('archivez-le', $e->getMessage());
        }
        $essentiel = $this->plan('essentiel');
        $this->commands->dispatch(new PlanAction(self::ADMIN, $essentiel->id, PlanAction::DELETE));
        self::assertNull($this->connection->fetchOne("SELECT id FROM billing.plan WHERE slug = 'essentiel'") ?: null, 'brouillon jamais publié : supprimable');
        self::assertSame(5, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE subject_type = 'plan'")));
    }

    public function testInvalidPriceIsRefused(): void
    {
        $this->expectException(PlanRuleViolation::class);
        $this->expectExceptionMessage('Prix HT mensuel invalide');
        $this->commands->dispatch(new SavePlan(self::ADMIN, null, self::PLAN, ['name' => 'Erreur', 'quotes_per_period' => '10', 'monthly_price' => '29,999']));
    }

    /** @return list<PlanView> */
    private function plans(AdminPlans|PublishedPlans $query): array
    {
        $plans = $this->queries->ask($query);
        \assert(\is_array($plans));

        return array_values(array_filter($plans, static fn (mixed $p): bool => $p instanceof PlanView));
    }

    private function plan(string $slug): PlanView
    {
        foreach ($this->plans(new AdminPlans()) as $plan) {
            if ($slug === $plan->slug) {
                return $plan;
            }
        }
        self::fail('forfait '.$slug.' absent');
    }
}
