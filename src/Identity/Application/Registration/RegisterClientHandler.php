<?php

declare(strict_types=1);

namespace App\Identity\Application\Registration;

use App\Catalog\Application\Geo\Query\FindCommunes;
use App\Identity\Application\Password\PasswordPolicy;
use App\Identity\Application\Port\DisposableEmailDetector;
use App\Identity\Application\Port\LegalAcceptanceLog;
use App\Identity\Application\Port\LegalDocuments;
use App\Identity\Application\Port\PasswordHasher;
use App\Identity\Domain\Client\ClientProfile;
use App\Identity\Domain\Client\ClientProfileRepository;
use App\Identity\Domain\Client\ClientType;
use App\Identity\Domain\Legal\LegalDocumentType;
use App\Identity\Domain\User\Event\RegistrationAttemptedOnExistingAccount;
use App\Identity\Domain\User\PersonName;
use App\Identity\Domain\User\User;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserKind;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Integration\CompanyRegistry;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
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
        private UserRepository $users,
        private ClientProfileRepository $profiles,
        private PasswordPolicy $policy,
        private PasswordHasher $hasher,
        private DisposableEmailDetector $disposable,
        private LegalDocuments $legal,
        private LegalAcceptanceLog $acceptances,
        private CompanyRegistry $companies,
        private QueryBus $queries,
        private SettingsProvider $settings,
        private EventBus $events,
        private AuditTrail $audit,
        private IdGenerator $ids,
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

        if ($this->settings->bool(SettingKey::AntifraudBlockDisposableEmails) && $this->disposable->isDisposable($email)) {
            throw new RegistrationRejected('email', RegistrationRejected::EMAIL_DISPOSABLE);
        }
        $documents = $this->requiredDocuments($command->acceptedDocumentIds, $now);
        $this->policy->assertAcceptable($command->password, $email);
        $this->assertPostalCodeExists($command->postalCode);
        [$siret, $siretVerified] = ClientType::Business === $type ? $this->checkSiret($command->siret) : [null, false];

        $hash = $this->hasher->hash($command->password); // toujours calculé : pas d'écart de temps révélant un compte existant
        $existing = $this->users->findByEmail($email);
        if (null !== $existing) {
            $this->events->publish(new RegistrationAttemptedOnExistingAccount($existing->id()->value, $now));

            return;
        }

        $userId = new UserId($command->userId);
        $user = User::register($userId, $email, UserKind::Client, $hash, $firstName, $lastName, $now);
        $user->changePhone($phone, $now);
        $profile = ClientType::Business === $type
            ? ClientProfile::business($userId, $command->postalCode, (string) $command->companyName, $siret, $siretVerified, $now)
            : ClientProfile::individual($userId, $command->postalCode, $now);
        if ($command->newsletter) {
            $profile->subscribeToNewsletter($now);
        }
        $this->users->add($user);
        $this->profiles->add($profile);
        foreach ($documents as $document) {
            $this->acceptances->record($this->ids->next(), $userId->value, $document, $now, $command->ipHash);
        }
        $this->events->publish(...$user->releaseEvents());
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'user',
            actorId: $userId->value,
            action: 'identity.user_registered',
            subjectType: 'user',
            subjectId: $userId->value,
            ip: $command->ip,
            context: ['kind' => 'client', 'client_type' => $type->value, 'newsletter' => $command->newsletter, 'legal' => implode(',', array_map(static fn ($d): string => $d->type->value.'@'.$d->version, $documents))],
        ));
    }

    /**
     * @param list<string> $acceptedIds
     *
     * @return list<\App\Identity\Application\Legal\LegalDocumentVersion>
     */
    private function requiredDocuments(array $acceptedIds, \DateTimeImmutable $now): array
    {
        $documents = [];
        foreach (self::REQUIRED_DOCUMENTS as $type) {
            $current = $this->legal->current($type, $now);
            if (null === $current) {
                throw new \LogicException(\sprintf('Aucune version publiée de « %s » : inscription impossible.', $type->label()));
            }
            if (!\in_array($current->id, $acceptedIds, true)) {
                throw new RegistrationRejected('terms', RegistrationRejected::LEGAL_OUTDATED);
            }
            $documents[] = $current;
        }

        return $documents;
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
