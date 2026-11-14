<?php

declare(strict_types=1);

namespace App\Billing\Application\Promo;

use App\Billing\Application\Payment\IdempotencyKey;
use App\Billing\Application\Port\PaymentGateway;
use App\Billing\Application\Port\PromotionSpec;
use App\Billing\Domain\Plan\PlanId;
use App\Billing\Domain\Plan\PlanRepository;
use App\Billing\Domain\Promo\PromoRuleViolation;
use App\Billing\Domain\Promo\PromoTerms;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * BO08.3 — un code promo n'existe que s'il est créé chez Stripe (Coupon limité aux Products des forfaits éligibles,
 * Promotion Code avec plafond d'utilisations, échéance, « première souscription » si réservé aux nouveaux abonnés).
 * Les conditions d'un code ne changent plus ensuite (Stripe les fige) : on le désactive et on en crée un autre.
 */
final readonly class PromoCodeHandler implements CommandHandler
{
    private const int BASIS_POINTS_PER_PERCENT = 100;

    public function __construct(
        private PromoCodes $codes,
        private PlanRepository $plans,
        private PaymentGateway $gateway,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(CreatePromoCode|DisablePromoCode $command): void
    {
        $now = $this->clock->now();
        if ($command instanceof DisablePromoCode) {
            $code = $this->codes->find($command->id, $now) ?? throw new PromoRuleViolation('Code promo introuvable.');
            if ($code->disabled) {
                return;
            }
            $this->stripe(fn () => $this->gateway->deactivatePromotionCode($code->stripePromotionCodeId, IdempotencyKey::for('DisablePromoCode', $code->id, 'off')));
            $this->codes->disable($code->id, $now);
            $this->record($command->adminId, 'billing.promo_code_disabled', $code->id, $code->terms->code, $now);

            return;
        }
        $terms = PromoForm::terms($command->input, $now);
        if ($this->codes->codeExists($terms->code)) {
            throw new PromoRuleViolation('Ce code existe déjà.');
        }
        $products = [];
        foreach ($terms->planIds as $planId) {
            $plan = $this->plans->find(new PlanId($planId)) ?? throw new PromoRuleViolation('Forfait éligible introuvable.');
            $products[] = $plan->stripeProductId() ?? throw new PromoRuleViolation(\sprintf('Le forfait « %s » n’est pas encore en vente chez Stripe : publiez-le d’abord.', $plan->terms()->name));
        }
        $ids = $this->stripe(fn (): array => $this->gateway->createPromotion(new PromotionSpec(
            $terms->code,
            PromoTerms::PERCENT === $terms->discountType ? ($terms->percentBp ?? 0) / self::BASIS_POINTS_PER_PERCENT : null,
            $terms->amountCents,
            $terms->duration,
            $terms->durationMonths,
            $products,
            $terms->validUntil,
            $terms->maxRedemptions,
            $terms->newSubscribersOnly,
            ['promo_code_id' => $command->id],
            IdempotencyKey::for('CreatePromoCode', $command->id, $terms->code),
        )));
        $this->codes->add($command->id, $terms, $ids['coupon'], $ids['promotion'], $command->adminId, $now);
        $this->record($command->adminId, 'billing.promo_code_created', $command->id, $terms->code, $now);
    }

    /**
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function stripe(callable $call): mixed
    {
        try {
            return $call();
        } catch (ExternalServiceUnavailable $e) {
            throw new PromoRuleViolation('Stripe n’a pas pu enregistrer ce code ('.$e->getMessage().'). Réessayez.');
        }
    }

    private function record(string $adminId, string $action, string $id, string $code, \DateTimeImmutable $now): void
    {
        $this->audit->record(new AuditRecord($this->ids->next(), $now, 'admin', $adminId, $action, 'promo_code', $id, context: ['code' => $code]));
    }
}
