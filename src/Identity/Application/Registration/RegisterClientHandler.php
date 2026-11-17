<?php

declare(strict_types=1);

namespace App\Identity\Application\Registration;

use App\Catalog\Application\Geo\Query\FindCommunes;
use App\Identity\Domain\Client\ClientProfile;
use App\Identity\Domain\Client\ClientProfileRepository;
use App\Identity\Domain\Client\ClientType;
use App\Identity\Domain\Legal\LegalDocumentType;
use App\Identity\Domain\User\PersonName;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserKind;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Integration\CompanyRegistry;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\Email;
use App\Shared\Domain\ValueObject\PhoneNumber;
use App\Shared\Domain\ValueObject\Siret;

/**
 * A02. Anti-énumération : si l'e-mail est déjà inscrit, aucune erreur n'est renvoyée — l'empreinte est calculée
 * quand même (temps de réponse identique) et le titulaire est prévenu par e-mail (événement → Notification).
 */
final readonly class RegisterClientHandler implements CommandHandler
{
    /** Documents à accepter à l'inscription client (CDC A02 : CGU + politique de confidentialité). */
    private const array REQUIRED_DOCUMENTS = [LegalDocumentType::TermsOfUse, LegalDocumentType::Privacy];

    public function __construct(
        private AccountRegistrar $registrar,
        private ClientProfileRepository $profiles,
        private CompanyRegistry $companies,
        private QueryBus $queries,
        private Clock $clock,
    ) {
    }

    public function __invoke(RegisterClient $command): void
    {
        $now = $this->clock->now();
        $email = new Email($command->email);
        $firstName = new PersonName($command->firstName);
        $lastName = new PersonName($command->lastName);
        $phone = null === $command->phone || '' === trim($command->phone) ? null : new PhoneNumber($command->phone);
        $type = ClientType::from($command->clientType);

        $documents = $this->registrar->check($email, $command->password, self::REQUIRED_DOCUMENTS, $command->acceptedDocumentIds);
        $this->assertPostalCodeExists($command->postalCode);
        [$siret, $siretVerified] = ClientType::Business === $type ? $this->checkSiret($command->siret) : [null, false];

        $userId = new UserId($command->userId);
        $user = $this->registrar->register($userId, $email, $command->password, UserKind::Client, $firstName, $lastName, $phone, $documents, $command->ip, $command->ipHash, [
            'client_type' => $type->value,
            'newsletter' => $command->newsletter,
        ] + (null === $command->origin ? [] : ['origin' => $command->origin]), $command->origin);
        if (null === $user) {
            return; // compte existant : même issue, titulaire prévenu (anti-énumération)
        }
        $profile = ClientType::Business === $type
            ? ClientProfile::business($userId, $command->postalCode, (string) $command->companyName, $siret, $siretVerified, $now)
            : ClientProfile::individual($userId, $command->postalCode, $now);
        if ($command->newsletter) {
            $profile->subscribeToNewsletter($now);
        }
        $this->profiles->add($profile);
    }

    private function assertPostalCodeExists(string $postalCode): void
    {
        $postalCode = trim($postalCode);
        $found = 1 === preg_match('/^\d{5}$/', $postalCode) ? $this->queries->ask(new FindCommunes($postalCode, 1)) : [];
        if (!\is_array($found) || [] === $found) {
            throw new RegistrationRejected('postalCode', RegistrationRejected::POSTAL_CODE_UNKNOWN);
        }
    }

    /** @return array{?Siret, bool} SIRET facultatif ; Sirene indisponible → accepté, non vérifié (contrôle ultérieur). */
    private function checkSiret(?string $value): array
    {
        if (null === $value || '' === trim($value)) {
            return [null, false];
        }
        $siret = new Siret($value);
        try {
            $company = $this->companies->find($siret);
        } catch (ExternalServiceUnavailable) {
            return [$siret, false];
        }
        if (null === $company) {
            throw new RegistrationRejected('siret', RegistrationRejected::SIRET_UNKNOWN);
        }
        if (!$company->active) {
            throw new RegistrationRejected('siret', RegistrationRejected::SIRET_CLOSED);
        }

        return [$siret, true];
    }
}
