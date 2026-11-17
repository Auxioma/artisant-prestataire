<?php

declare(strict_types=1);

namespace App\Billing\Application\Promo;

/**
 * §4.3 — règles d'un code promo que Stripe ne contrôle pas seul (au tunnel PR12) : forfait éligible, utilisations par
 * compte, réservé aux nouveaux abonnés ; renvoie le message de refus, ou null si le code s'applique.
 */
final readonly class PromoEligibility
{
    public function __construct(private PromoCodes $codes)
    {
    }

    public function refusal(PromoCodeView $code, string $artisanId, string $planId, bool $hadSubscription): ?string
    {
        if ('active' !== $code->state()) {
            return 'Ce code promo n’existe pas ou n’est plus valable.';
        }
        if ([] !== $code->terms->planIds && !\in_array($planId, $code->terms->planIds, true)) {
            return 'Ce code promo ne s’applique pas à cette formule.';
        }
        if ($code->terms->newSubscribersOnly && $hadSubscription) {
            return 'Ce code promo est réservé aux nouveaux abonnés.';
        }
        if (null !== $code->terms->maxPerAccount && $this->codes->redemptionsBy($code->id, $artisanId) >= $code->terms->maxPerAccount) {
            return 'Vous avez déjà utilisé ce code promo.';
        }

        return null;
    }
}
