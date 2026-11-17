<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\PublicProfile;

use App\ArtisanProfile\Application\Port\PhoneReveals;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

final readonly class RecordPhoneRevealHandler implements CommandHandler
{
    public function __construct(private ArtisanPhoneHandler $phones, private PhoneReveals $reveals, private IdGenerator $ids, private Clock $clock)
    {
    }

    public function __invoke(RecordPhoneReveal $command): void
    {
        $profile = $this->phones->revealable($command->profileId) ?? throw new ArtisanRuleViolation('Téléphone non disponible.');
        $this->reveals->record($this->ids->next(), $profile->id()->value, $command->visitorHash, $this->clock->now());
    }
}
