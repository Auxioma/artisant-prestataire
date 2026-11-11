<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

final readonly class SubmitSignupHandler implements CommandHandler
{
    public function __construct(
        private ArtisanProfileRepository $profiles,
        private EventBus $events,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(SubmitSignup $command): void
    {
        $profile = $this->profiles->findByUserId($command->userId) ?? throw new ArtisanRuleViolation('Déclarez d’abord votre entreprise.');
        $wasSubmitted = null !== $profile->submittedAt();
        $now = $this->clock->now();
        $profile->submit($command->shortDescription, $now);
        $this->events->publish(...$profile->releaseEvents());
        if (!$wasSubmitted) {
            $this->audit->record(new AuditRecord(
                id: $this->ids->next(),
                occurredAt: $now,
                actorType: 'user',
                actorId: $command->userId,
                action: 'artisan.signup_submitted',
                subjectType: 'artisan_profile',
                subjectId: $profile->id()->value,
            ));
        }
    }
}
