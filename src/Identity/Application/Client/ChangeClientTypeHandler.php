<?php

declare(strict_types=1);

namespace App\Identity\Application\Client;

use App\Identity\Domain\Client\ClientProfileRepository;
use App\Identity\Domain\User\IdentityRuleViolation;
use App\Identity\Domain\User\UserId;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Integration\CompanyRegistry;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\Siret;

/** Même contrôle du SIRET qu'à l'inscription (A02) : facultatif, vérifié via Sirene, accepté non vérifié si Sirene est indisponible. */
final readonly class ChangeClientTypeHandler implements CommandHandler
{
    public function __construct(private ClientProfileRepository $profiles, private CompanyRegistry $companies, private Clock $clock)
    {
    }

    public function __invoke(ChangeClientType $command): void
    {
        $profile = $this->profiles->find(new UserId($command->userId)) ?? throw new ClientProfileRejected('companyName', 'Profil client introuvable.');
        $now = $this->clock->now();
        if (!$command->business) {
            $profile->becomeIndividual($now);

            return;
        }
        [$siret, $verified] = $this->checkSiret($command->siret);
        try {
            $profile->becomeBusiness((string) $command->companyName, $siret, $verified, $now);
        } catch (IdentityRuleViolation) {
            throw new ClientProfileRejected('companyName', 'Raison sociale invalide (2 à 120 caractères).');
        }
    }

    /** @return array{?Siret, bool} */
    private function checkSiret(?string $value): array
    {
        if (null === $value || '' === trim($value)) {
            return [null, false];
        }
        try {
            $siret = new Siret($value);
        } catch (InvalidValue) {
            throw new ClientProfileRejected('siret', 'SIRET invalide (14 chiffres).');
        }
        try {
            $company = $this->companies->find($siret);
        } catch (ExternalServiceUnavailable) {
            return [$siret, false];
        }
        if (null === $company) {
            throw new ClientProfileRejected('siret', 'SIRET inconnu de la base Sirene.');
        }
        if (!$company->active) {
            throw new ClientProfileRejected('siret', 'Cet établissement est fermé.');
        }

        return [$siret, true];
    }
}
