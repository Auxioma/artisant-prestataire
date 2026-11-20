<?php

declare(strict_types=1);

namespace App\Tests\Unit\Administration\Application;

use App\Administration\Application\Dashboard\DashboardKpis;
use App\Administration\Application\Dashboard\DashboardKpisHandler;
use App\Administration\Application\Dashboard\DashboardMetric;
use App\Administration\Application\Dashboard\KpiCard;
use App\Shared\Application\Admin\DashboardMetricSource;
use PHPUnit\Framework\TestCase;

/** T-805a — BO01.1 : période du mois et N-1, écarts selon l'unité, sens favorable, cartes sans comparaison. */
final class DashboardKpisHandlerTest extends TestCase
{
    /** @var list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> */
    private array $calls = [];

    public function testCurrentMonthStopsAtNowAndComparesTheSameSpanOneYearEarlier(): void
    {
        $view = $this->handler()(new DashboardKpis(null, new \DateTimeImmutable('2026-11-19 10:00', new \DateTimeZone('Europe/Paris'))));

        self::assertSame('2026-11', $view->month);
        self::assertSame('2026-11-01 00:00', $this->calls[0][0]->format('Y-m-d H:i'));
        self::assertSame('2026-11-19 10:00', $this->calls[0][1]->format('Y-m-d H:i'));
        self::assertSame('2025-11-01 00:00', $this->calls[1][0]->format('Y-m-d H:i'));
        self::assertSame('2025-11-19 10:00', $this->calls[1][1]->format('Y-m-d H:i'));
        self::assertSame('19', $view->lastDay()->format('j'));
    }

    public function testPastMonthCoversTheWholeMonthAndFutureMonthsAreClamped(): void
    {
        $now = new \DateTimeImmutable('2026-11-19 10:00', new \DateTimeZone('Europe/Paris'));
        $view = $this->handler()(new DashboardKpis('2026-10', $now));
        self::assertSame('2026-11-01 00:00', $view->to->format('Y-m-d H:i'));
        self::assertSame('31', $view->lastDay()->format('j'));

        self::assertSame('2026-11', $this->handler()(new DashboardKpis('2027-03', $now))->month);
        self::assertSame('2026-11', $this->handler()(new DashboardKpis('pas-un-mois', $now))->month);
    }

    public function testDeltasFollowTheUnitAndTheFavourableDirection(): void
    {
        $view = $this->handler()(new DashboardKpis('2026-10', new \DateTimeImmutable('2026-11-19')));
        $cards = [];
        foreach ($view->all() as $card) {
            $cards[$card->key()] = $card;
        }

        self::assertCount(10, $view->featured, 'les dix cartes de la maquette');
        self::assertSame(DashboardMetric::Visitors, $view->featured[0]->metric);
        $this->assertDelta($cards['requests_submitted'], 22.0, '%', 'good');
        $this->assertDelta($cards['requests_with_quote_rate'], 4.0, 'pts', 'good');
        $this->assertDelta($cards['first_quote_delay_minutes'], -25.0, 'min', 'good');
        $this->assertDelta($cards['churn_rate'], 0.3, 'pts', 'bad');
        $this->assertDelta($cards['review_average'], 0.2, '', 'good');
        self::assertNull($cards['visitors']->value, 'aucun outil d’audience');
        self::assertNull($cards['zero_credit_unsubscribed']->previous, 'état actuel : pas de N-1');
        self::assertNull($cards['quotes_sent']->delta, 'N-1 nul : pas d’écart relatif');
        self::assertSame('neutral', $cards['quotes_sent']->tone);
    }

    private function assertDelta(KpiCard $card, float $delta, string $unit, string $tone): void
    {
        self::assertNotNull($card->delta, $card->key());
        self::assertEqualsWithDelta($delta, $card->delta, 0.001, $card->key());
        self::assertSame($unit, $card->deltaUnit, $card->key());
        self::assertSame($tone, $card->tone, $card->key());
    }

    private function handler(): DashboardKpisHandler
    {
        $this->calls = [];
        $source = new class($this->calls) implements DashboardMetricSource {
            /** @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function metrics(\DateTimeImmutable $from, \DateTimeImmutable $to): array
            {
                $current = [] === $this->calls;
                $this->calls[] = [$from, $to];

                return $current
                    ? ['requests_submitted' => 1220, 'requests_with_quote_rate' => 0.81, 'first_quote_delay_minutes' => 220.0, 'churn_rate' => 0.021, 'review_average' => 4.6, 'quotes_sent' => 40, 'zero_credit_unsubscribed' => 86]
                    : ['requests_submitted' => 1000, 'requests_with_quote_rate' => 0.77, 'first_quote_delay_minutes' => 245.0, 'churn_rate' => 0.018, 'review_average' => 4.4, 'quotes_sent' => 0, 'zero_credit_unsubscribed' => 50];
            }
        };

        return new DashboardKpisHandler([$source]);
    }
}
