<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Dispatch;

use App\QuoteRequest\Application\Port\RequestDispatches;
use App\QuoteRequest\Application\Port\RequestMailer;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;
use App\Shared\Domain\Time\Clock;

/**
 * E-30 regroupé (CDC §6 « regroupement anti-saturation ») : un e-mail par artisan listant ses nouvelles demandes encore
 * ouvertes. Un artisan repassé en alertes immédiates reçoit aussi celles qui attendaient.
 */
final readonly class SendRequestDigestsHandler implements CommandHandler
{
    public function __construct(private RequestDispatches $dispatches, private RequestMailer $mailer, private QueryBus $queries, private Clock $clock)
    {
    }

    public function __invoke(SendRequestDigests $command): void
    {
        $now = $this->clock->now();
        foreach ($this->dispatches->pendingDigests() as $digest) {
            if (AlertFrequency::Daily === $digest->frequency && !$command->daily) {
                continue;
            }
            $contact = $this->queries->ask(new UserContact($digest->userId));
            if ($contact instanceof UserContactDetails) {
                $this->mailer->sendDigest($contact->email, $contact->firstName, $digest->userId, $digest->requests);
            }
            $this->dispatches->markNotified($digest->artisanId, array_map(static fn (DispatchSummary $s): string => $s->requestId, $digest->requests), $now);
        }
    }
}
