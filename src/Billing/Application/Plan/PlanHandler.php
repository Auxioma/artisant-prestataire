<?php

declare(strict_types=1);

namespace App\Billing\Application\Plan;

use App\Billing\Domain\Plan\BillingInterval;
use App\Billing\Domain\Plan\Plan;
use App\Billing\Domain\Plan\PlanId;
use App\Billing\Domain\Plan\PlanRepository;
use App\Billing\Domain\Plan\PlanRuleViolation;
use App\Billing\Domain\Plan\PlanStatus;
use App\Billing\Domain\Plan\PlanTerms;
use App\Billing\Domain\Plan\PlanVisibility;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\Slug;

/**
 * BO08.1 — gestion des forfaits par l'équipe : création, modification (un nouveau prix remplace l'actif, l'ancien reste
 * à l'historique et pour les abonnés existants), publication, archivage, suppression d'un brouillon, ordre. Journalisé.
 */
final readonly class PlanHandler implements CommandHandler
{
    private const int CENTS = 100;

    public function __construct(private PlanRepository $plans, private AuditTrail $audit, private IdGenerator $ids, private Clock $clock)
    {
    }

    public function __invoke(SavePlan|PlanAction $command): void
    {
        $now = $this->clock->now();
        if ($command instanceof SavePlan) {
            $this->save($command, $now);

            return;
        }
        $plan = $this->plans->find(new PlanId($command->planId)) ?? throw new PlanRuleViolation('Forfait introuvable.');
        match ($command->action) {
            PlanAction::PUBLISH => $plan->publish($now),
            PlanAction::ARCHIVE => $plan->archive($now),
            PlanAction::DELETE => $plan->assertDeletable($this->plans->subscribers($plan->id())),
            PlanAction::UP, PlanAction::DOWN => $this->move($plan, PlanAction::UP === $command->action, $now),
            default => throw new PlanRuleViolation('Action inconnue.'),
        };
        if (PlanAction::DELETE === $command->action) {
            $this->plans->delete($plan->id());
        } elseif (!\in_array($command->action, [PlanAction::UP, PlanAction::DOWN], true)) {
            $this->plans->save($plan);
        }
        $this->record($command->adminId, 'plan.'.$command->action, $plan, $now);
    }

    private function save(SavePlan $command, \DateTimeImmutable $now): void
    {
        $terms = self::terms($command->input);
        if (null === $command->planId) {
            $slug = Slug::fromText($terms->name)->value;
            if (null !== $this->plans->findBySlug($slug)) {
                throw new PlanRuleViolation('Un forfait porte déjà ce nom.');
            }
            $plan = Plan::draft(new PlanId($command->newId), $slug, $terms, \count($this->plans->all()) + 1, $now);
        } else {
            $plan = $this->plans->find(new PlanId($command->planId)) ?? throw new PlanRuleViolation('Forfait introuvable.');
            $plan->revise($terms, $now);
        }
        foreach (BillingInterval::cases() as $interval) {
            $plan->changePrice($interval, self::cents($command->input[$interval->field()] ?? null, $interval), $this->ids->next(), $now);
        }
        $this->plans->save($plan);
        $this->record($command->adminId, null === $command->planId ? 'plan.created' : 'plan.updated', $plan, $now);
    }

    private function move(Plan $plan, bool $up, \DateTimeImmutable $now): void
    {
        $plans = array_values(array_filter($this->plans->all(), static fn (Plan $p): bool => PlanStatus::Archived !== $p->status()));
        $index = array_search($plan->id()->value, array_map(static fn (Plan $p): string => $p->id()->value, $plans), true);
        if (false === $index || !isset($plans[$up ? $index - 1 : $index + 1])) {
            return;
        }
        $target = $up ? $index - 1 : $index + 1;
        [$plans[$index], $plans[$target]] = [$plans[$target], $plans[$index]];
        foreach ($plans as $position => $other) {
            $other->moveTo($position + 1, $now);
            $this->plans->save($other);
        }
    }

    /** @param array<string, mixed> $input */
    private static function terms(array $input): PlanTerms
    {
        $text = static fn (string $k): ?string => \is_string($input[$k] ?? null) && '' !== trim($input[$k]) ? trim($input[$k]) : null;
        $int = static function (string $k) use ($input): ?int {
            $v = $input[$k] ?? null;
            if (null === $v || '' === $v) {
                return null;
            }
            if (!\is_string($v) || 1 !== preg_match('/^\d{1,6}$/', trim($v))) {
                throw new PlanRuleViolation('Les nombres doivent être des entiers positifs.');
            }

            return (int) trim($v);
        };
        $unlimited = '1' === ($input['unlimited'] ?? null);
        $features = \is_string($input['features'] ?? null) ? array_values(array_filter(array_map('trim', explode("\n", $input['features'])), static fn (string $f): bool => '' !== $f)) : [];
        $visibility = PlanVisibility::tryFrom(\is_string($input['visibility'] ?? null) ? $input['visibility'] : '') ?? PlanVisibility::Public;
        $code = $text('access_code');

        return new PlanTerms(
            $text('name') ?? '',
            $text('description'),
            $unlimited ? null : $int('quotes_per_period'),
            $unlimited,
            $unlimited ? $int('fair_use_cap') : null,
            $features,
            $int('max_offers'),
            '1' === ($input['featured'] ?? null),
            '1' === ($input['advanced_stats'] ?? null),
            $text('badge'),
            $visibility,
            PlanVisibility::Private === $visibility ? (null === $code ? null : strtoupper($code)) : null,
        );
    }

    /** « 29 », « 29,00 », « 29.5 » → centimes ; vide → aucun prix. */
    private static function cents(mixed $value, BillingInterval $interval): ?int
    {
        if (null === $value || '' === trim((string) (\is_scalar($value) ? $value : ''))) {
            return null;
        }
        $text = str_replace([' ', "\u{a0}", '€'], '', (string) (\is_scalar($value) ? $value : ''));
        if (1 !== preg_match('/^(\d{1,6})(?:[.,](\d{1,2}))?$/', $text, $m)) {
            throw new PlanRuleViolation(\sprintf('%s invalide (ex. : 29,00).', $interval->label()));
        }

        return (int) $m[1] * self::CENTS + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    private function record(string $adminId, string $action, Plan $plan, \DateTimeImmutable $now): void
    {
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'admin',
            actorId: $adminId,
            action: $action,
            subjectType: 'plan',
            subjectId: $plan->id()->value,
            context: [
                'slug' => $plan->slug(),
                'status' => $plan->status()->value,
                'monthly_cents' => $plan->price(BillingInterval::Month)?->amountCents,
                'yearly_cents' => $plan->price(BillingInterval::Year)?->amountCents,
            ],
        ));
    }
}
