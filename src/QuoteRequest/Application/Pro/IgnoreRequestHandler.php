<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Pro;

use App\QuoteRequest\Application\Port\ProRequestReader;
use App\QuoteRequest\Domain\Request\RequestRuleViolation;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Time\Clock;

/** PR02.2 « Ignorer » : la demande quitte la liste (filtre « Ignorée » pour la retrouver et la rétablir). */
final readonly class IgnoreRequestHandler implements CommandHandler
{
    public function __construct(private ProRequestReader $reader, private Clock $clock)
    {
    }

    public function __invoke(IgnoreRequest $command): void
    {
        $artisan = $this->reader->artisanOf($command->userId);
        if (null === $artisan || !$artisan->verified || !$this->reader->ignore($artisan->artisanId, $command->requestId, IgnoreReason::tryFrom($command->reason ?? ''), $command->restore, $this->clock->now())) {
            throw new RequestRuleViolation('Cette demande ne figure pas parmi celles que vous avez reçues.');
        }
    }
}
