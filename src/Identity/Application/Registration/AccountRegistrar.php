<?php

declare(strict_types=1);

namespace App\Identity\Application\Registration;

use App\Identity\Application\Legal\LegalDocumentVersion;
use App\Identity\Application\Password\PasswordPolicy;
use App\Identity\Application\Port\DisposableEmailDetector;
use App\Identity\Application\Port\LegalAcceptanceLog;
use App\Identity\Application\Port\LegalDocuments;
use App\Identity\Application\Port\PasswordHasher;
use App\Identity\Domain\Legal\LegalDocumentType;
use App\Identity\Domain\User\Event\RegistrationAttemptedOnExistingAccount;
use App\Identity\Domain\User\PersonName;
use App\Identity\Domain\User\User;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserKind;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\Email;
use App\Shared\Domain\ValueObject\PhoneNumber;

/**
 * Création de compte commune aux inscriptions client (A02) et artisan (A03, étape 1) : e-mail jetable, documents
 * légaux en vigueur (RG-002), politique de mot de passe, anti-énumération, preuves d'acceptation, audit.
 */
final readonly class AccountRegistrar
{
    public function __construct(
        private UserRepository $users,
        private PasswordPolicy $policy,
        private PasswordHasher $hasher,
        private DisposableEmailDetector $disposable,
        private LegalDocuments $legal,
        private LegalAcceptanceLog $acceptances,
        private SettingsProvider $settings,
        private EventBus $events,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    /**
     * Contrôles préalables (à appeler avant toute vérification propre au profil) ; renvoie les documents acceptés.
     *
     * @param list<LegalDocumentType> $requiredDocuments
     * @param list<string>            $acceptedDocumentIds
     *
     * @return list<LegalDocumentVersion>
     *
     * @throws RegistrationRejected
     * @throws \App\Identity\Application\Password\WeakPassword
     */
    public function check(Email $email, #[\SensitiveParameter] string $password, array $requiredDocuments, array $acceptedDocumentIds): array
    {
        if ($this->settings->bool(SettingKey::AntifraudBlockDisposableEmails) && $this->disposable->isDisposable($email)) {
            throw new RegistrationRejected('email', RegistrationRejected::EMAIL_DISPOSABLE);
        }
        $now = $this->clock->now();
        $documents = [];
        foreach ($requiredDocuments as $type) {
            $current = $this->legal->current($type, $now);
            if (null === $current) {
                throw new \LogicException(\sprintf('Aucune version publiée de « %s » : inscription impossible.', $type->label()));
            }
            if (!\in_array($current->id, $acceptedDocumentIds, true)) {
                throw new RegistrationRejected('terms', RegistrationRejected::LEGAL_OUTDATED);
            }
            $documents[] = $current;
        }
        $this->policy->assertAcceptable($password, $email);

        return $documents;
    }

    /**
     * Crée le compte. Anti-énumération : si l'adresse est déjà inscrite, l'empreinte est calculée quand même (temps de
     * réponse identique), le titulaire est prévenu par événement et null est renvoyé — sans erreur.
     *
     * @param list<LegalDocumentVersion> $documents
     * @param array<string, scalar>      $auditContext
     */
    public function register(
        UserId $userId,
        Email $email,
        #[\SensitiveParameter]
        string $password,
        UserKind $kind,
        PersonName $firstName,
        PersonName $lastName,
        ?PhoneNumber $phone,
        array $documents,
        ?string $ip,
        ?string $ipHash,
        array $auditContext = [],
    ): ?User {
        $now = $this->clock->now();
        $hash = $this->hasher->hash($password);
        $existing = $this->users->findByEmail($email);
        if (null !== $existing) {
            $this->events->publish(new RegistrationAttemptedOnExistingAccount($existing->id()->value, $now));

            return null;
        }

        $user = User::register($userId, $email, $kind, $hash, $firstName, $lastName, $now);
        $user->changePhone($phone, $now);
        $this->users->add($user);
        foreach ($documents as $document) {
            $this->acceptances->record($this->ids->next(), $userId->value, $document, $now, $ipHash);
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
            ip: $ip,
            context: ['kind' => $kind->value, 'legal' => implode(',', array_map(static fn (LegalDocumentVersion $d): string => $d->type->value.'@'.$d->version, $documents))] + $auditContext,
        ));

        return $user;
    }
}
