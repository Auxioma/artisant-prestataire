<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\ArtisanProfile\Domain\Profile\ArtisanProfile;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileId;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\CompanyDetails;
use App\ArtisanProfile\Domain\Profile\VatStatus;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\Siret;

final readonly class DeclareCompanyHandler implements CommandHandler
{
    public function __construct(
        private ArtisanProfileRepository $profiles,
        private CompanyChecker $checker,
        private EventBus $events,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(DeclareCompany $command): void
    {
        $now = $this->clock->now();
        $profile = $this->profiles->findByUserId($command->userId);
        if (null === $profile) {
            $profile = ArtisanProfile::start(new ArtisanProfileId($this->ids->next()), $command->userId, $now);
            $this->profiles->add($profile);
        }

        $lookup = $this->checker->lookup($command->siret, $profile->id());
        $vatStatus = VatStatus::from($command->vatStatus);
        $vatNumber = VatStatus::Franchise === $vatStatus ? null : self::vat($command->vatNumber, $lookup->suggestedVatNumber);
        $company = match ($lookup->status) {
            CompanyLookupResult::INVALID => throw new SignupRejected('siret', SignupRejected::SIRET_INVALID),
            CompanyLookupResult::TAKEN => throw new SignupRejected('siret', SignupRejected::SIRET_TAKEN),
            CompanyLookupResult::CLOSED => throw new SignupRejected('siret', SignupRejected::SIRET_CLOSED),
            CompanyLookupResult::FOUND => new CompanyDetails(
                new Siret($command->siret),
                $lookup->company->name ?? (string) $command->manualCompanyName,
                $command->tradeName,
                $lookup->company?->legalCategory,
                $lookup->company?->nafCode,
                $vatNumber,
                $vatStatus,
                $lookup->company?->street,
                $lookup->company?->postalCode,
                $lookup->company?->city,
                $lookup->company?->inseeCode,
                $lookup->company?->createdOn,
                true,
            ),
            default => $this->manual($command, $vatNumber, $vatStatus),
        };

        $profile->declareCompany($company, $now);
        $this->events->publish(...$profile->releaseEvents());
    }

    /** Sirene indisponible : saisie manuelle, vérification différée (CDC A03.2). */
    private function manual(DeclareCompany $command, ?string $vatNumber, VatStatus $vatStatus): CompanyDetails
    {
        if (null === $command->manualCompanyName || '' === trim($command->manualCompanyName) || 1 !== preg_match('/^\d{5}$/', (string) $command->manualPostalCode)) {
            throw new SignupRejected('manual', SignupRejected::MANUAL_REQUIRED);
        }

        return new CompanyDetails(new Siret($command->siret), $command->manualCompanyName, $command->tradeName, null, null, $vatNumber, $vatStatus, null, $command->manualPostalCode, $command->manualCity, null, null, false);
    }

    private static function vat(?string $entered, ?string $suggested): ?string
    {
        $vat = strtoupper((string) preg_replace('/[\s.\-]/', '', (string) $entered));

        return 1 === preg_match('/^[A-Z]{2}[0-9A-Z]{2,12}$/', $vat) ? $vat : $suggested;
    }
}
