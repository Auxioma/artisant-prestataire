<?php

declare(strict_types=1);

namespace App\Billing\UI\Twig;

use App\Billing\Application\Credit\CreditGaugeOf;
use App\Billing\Domain\Credit\CreditGauge;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** `tm_artisan_credits()` : crédits de l'artisan connecté pour la pastille de l'en-tête (null : pas encore de crédits). */
final class CreditExtension extends AbstractExtension
{
    public function __construct(private readonly QueryBus $queries, private readonly CurrentUser $currentUser)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('tm_artisan_credits', $this->credits(...))];
    }

    /** @return array{remaining: int, state: string}|null */
    public function credits(): ?array
    {
        $userId = $this->currentUser->id();
        if (null === $userId || !$this->currentUser->isArtisan()) {
            return null;
        }
        $gauge = $this->queries->ask(new CreditGaugeOf($userId));

        return $gauge instanceof CreditGauge ? ['remaining' => $gauge->remaining, 'state' => $gauge->state] : null;
    }
}
