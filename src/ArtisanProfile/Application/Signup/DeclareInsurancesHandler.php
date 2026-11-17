<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\ArtisanProfile\Domain\Profile\Insurance;
use App\ArtisanProfile\Domain\Profile\InsuranceKind;
use App\ArtisanProfile\Domain\Profile\Qualification;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Time\Clock;

final readonly class DeclareInsurancesHandler implements CommandHandler
{
    public function __construct(
        private ArtisanProfileRepository $profiles,
        private SignupRequirements $requirements,
        private EventBus $events,
        private Clock $clock,
    ) {
    }

    public function __invoke(DeclareInsurances $command): void
    {
        $profile = $this->profiles->findByUserId($command->userId) ?? throw new ArtisanRuleViolation('Déclarez d’abord votre entreprise.');
        $insurances = [];
        foreach ($command->insurances as $data) {
            $kind = InsuranceKind::tryFrom($data['kind']) ?? throw new ArtisanRuleViolation('Type d’assurance inconnu.');
            $insurances[] = new Insurance($kind, $data['insurer'], $data['policy'], self::date($data['valid_until'], $kind->label()));
        }
        $qualifications = [];
        foreach ($command->qualifications as $data) {
            $qualifications[] = new Qualification($data['name'], $data['number'], self::date($data['valid_until'], 'Label'));
        }

        $profile->declareInsurances($insurances, $qualifications, $this->requirements->insurances($profile), $this->clock->now());
        $this->events->publish(...$profile->releaseEvents());
    }

    private static function date(string $value, string $what): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new SignupRejected('valid_until', SignupRejected::DATE_INVALID, \sprintf('%s : indiquez la date de fin de validité.', $what));
        }

        return $date;
    }
}
