<?php

declare(strict_types=1);

namespace App\Billing\Application\Payment;

use App\Billing\Application\Port\PaymentGateway;
use App\Billing\Domain\Plan\Plan;
use App\Billing\Domain\Plan\PlanId;
use App\Billing\Domain\Plan\PlanRepository;
use App\Billing\Domain\Plan\PlanStatus;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Time\Clock;

/**
 * T-602 — BO08.1 → Stripe : un produit par forfait (actif tant qu'il est publié), un prix récurrent HT par prix versionné.
 * Un nouveau montant crée un nouveau prix et retire l'ancien de la vente (les abonnés existants le gardent). Un brouillon
 * jamais publié n'existe pas chez Stripe. Sans clé configurée, rien n'est envoyé (resynchronisation : app:stripe:sync-plans).
 */
final readonly class SyncPlanWithStripeHandler implements CommandHandler
{
    private const string COMMAND = 'SyncPlanWithStripe';

    public function __construct(
        private PaymentGateway $gateway,
        private PlanRepository $plans,
        private StripePriceLinks $links,
        private Clock $clock,
    ) {
    }

    public function __invoke(SyncPlanWithStripe $command): void
    {
        $plan = $this->plans->find(new PlanId($command->planId));
        if (null === $plan || !$plan->everPublished() || !$this->gateway->isConfigured()) {
            return;
        }
        $this->syncProduct($plan);
        $productId = (string) $plan->stripeProductId();
        foreach ($plan->prices() as $price) {
            if (null !== $price->stripePriceId) {
                continue;
            }
            $stripeId = $this->gateway->createPrice($productId, $price->amountCents, $price->interval, [
                'plan_id' => $plan->id()->value,
                'plan_price_id' => $price->id,
            ], IdempotencyKey::for(self::COMMAND, $plan->id()->value, 'price:'.$price->id));
            $this->links->linkPrice($price->id, $stripeId);
        }
        foreach ($this->links->retired($plan->id()->value) as $priceId => $stripeId) {
            $this->gateway->deactivatePrice($stripeId, IdempotencyKey::for(self::COMMAND, $plan->id()->value, 'retire:'.$priceId));
            $this->links->markRetired($priceId, $this->clock->now());
        }
    }

    private function syncProduct(Plan $plan): void
    {
        $terms = $plan->terms();
        $active = PlanStatus::Published === $plan->status();
        $metadata = ['plan_id' => $plan->id()->value, 'slug' => $plan->slug()];
        // L'intention dépend du contenu envoyé : une nouvelle version du forfait n'est pas confondue avec une reprise.
        $intent = 'product:'.hash('sha256', json_encode([$terms->name, $terms->description, $active, $plan->stripeProductId()], \JSON_THROW_ON_ERROR));
        $productId = $this->gateway->saveProduct($plan->stripeProductId(), $terms->name, $terms->description, $active, $metadata, IdempotencyKey::for(self::COMMAND, $plan->id()->value, $intent));
        if ($productId !== $plan->stripeProductId()) {
            $plan->linkStripeProduct($productId);
            $this->plans->save($plan);
        }
    }
}
