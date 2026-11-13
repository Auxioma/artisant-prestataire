<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Scheduler;

use App\QuoteRequest\Application\Dispatch\SendRequestDigests;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Time\Clock;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * PR14.2 — digests des nouvelles demandes, « 2 fois par jour » : 8 h et 17 h (heure de Paris) ; la fréquence
 * « quotidien » part au passage du matin.
 */
#[AsCronTask('0 8,17 * * *', timezone: 'Europe/Paris')]
final readonly class RequestDigestTask
{
    private const int NOON = 12;

    public function __construct(private CommandBus $commands, private Clock $clock)
    {
    }

    public function __invoke(): void
    {
        $hour = (int) $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Paris'))->format('G');
        $this->commands->dispatch(new SendRequestDigests($hour < self::NOON));
    }
}
