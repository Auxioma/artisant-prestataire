<?php

declare(strict_types=1);

namespace App\Identity\Application\Registration;

use App\Identity\Application\EventHandler\SendEmailVerificationOnRegistration;
use App\Identity\Application\Password\WeakPassword;
use App\Identity\Application\Port\LegalDocuments;
use App\Identity\Domain\Client\ClientType;
use App\Identity\Domain\Legal\LegalDocumentType;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Identity\AccountCreationRejected;
use App\Shared\Application\Identity\CreateClientAccount;
use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\Time\Clock;

/** P11.6 — inscription A02 depuis le tunnel de demande (particulier ; documents en vigueur acceptés par la case du formulaire). */
final readonly class CreateClientAccountHandler implements CommandHandler
{
    public function __construct(private RegisterClientHandler $register, private LegalDocuments $legal, private Clock $clock)
    {
    }

    public function __invoke(CreateClientAccount $command): void
    {
        $documents = [];
        foreach ([LegalDocumentType::TermsOfUse, LegalDocumentType::Privacy] as $type) {
            $documents[] = ($this->legal->current($type, $this->clock->now()) ?? throw new AccountCreationRejected('terms', 'L’inscription est momentanément indisponible : réessayez dans quelques minutes.'))->id;
        }
        try {
            ($this->register)(new RegisterClient(
                userId: $command->userId,
                email: $command->email,
                password: $command->password,
                firstName: $command->firstName,
                lastName: $command->lastName,
                phone: $command->phone,
                postalCode: $command->postalCode,
                clientType: ClientType::Individual->value,
                companyName: null,
                siret: null,
                acceptedDocumentIds: $documents,
                newsletter: $command->newsletter,
                ip: $command->ip,
                ipHash: $command->ipHash,
                origin: SendEmailVerificationOnRegistration::REQUEST,
            ));
        } catch (WeakPassword $e) {
            throw new AccountCreationRejected('password', match ($e->reason) {
                WeakPassword::COMPROMISED => 'Ce mot de passe est trop courant ou a fuité, choisissez-en un autre.', WeakPassword::CONTAINS_EMAIL => 'Votre mot de passe ne doit pas contenir votre adresse e-mail.', WeakPassword::TOO_LONG => 'Votre mot de passe ne doit pas dépasser 128 caractères.', default => \sprintf('Votre mot de passe doit contenir au moins %d caractères.', $e->minLength),
            });
        } catch (RegistrationRejected $e) {
            throw new AccountCreationRejected($e->field, match ($e->reason) {
                RegistrationRejected::EMAIL_DISPOSABLE => 'Les adresses temporaires ne sont pas acceptées.', RegistrationRejected::MOBILE_REQUIRED => 'Indiquez un numéro de mobile.', RegistrationRejected::POSTAL_CODE_UNKNOWN => 'Code postal inconnu.', default => 'Nos conditions viennent d’être mises à jour : réessayez.',
            });
        } catch (InvalidValue $e) {
            throw new AccountCreationRejected('form', 'Vérifiez vos informations : '.lcfirst($e->getMessage()));
        }
    }
}
