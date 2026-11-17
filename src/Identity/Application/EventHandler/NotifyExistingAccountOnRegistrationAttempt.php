<?php

declare(strict_types=1);

namespace App\Identity\Application\EventHandler;

use App\Identity\Application\Port\IdentityMailer;
use App\Identity\Domain\User\Event\RegistrationAttemptedOnExistingAccount;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Identity\Domain\User\UserStatus;
use App\Shared\Application\Bus\EventHandler;

/** A02 : le titulaire apprend qu'une inscription a été tentée avec son adresse (lien de connexion / mot de passe oublié). */
final readonly class NotifyExistingAccountOnRegistrationAttempt implements EventHandler
{
    public function __construct(private UserRepository $users, private IdentityMailer $mailer)
    {
    }

    public function __invoke(RegistrationAttemptedOnExistingAccount $event): void
    {
        $user = $this->users->find(new UserId($event->userId));
        if (null !== $user && UserStatus::Anonymized !== $user->status()) {
            $this->mailer->sendExistingAccountNotice($user->email(), $user->firstName());
        }
    }
}
