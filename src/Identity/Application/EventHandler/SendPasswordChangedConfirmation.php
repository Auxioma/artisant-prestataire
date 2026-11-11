<?php

declare(strict_types=1);

namespace App\Identity\Application\EventHandler;

use App\Identity\Application\Port\IdentityMailer;
use App\Identity\Domain\User\Event\UserPasswordChanged;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Identity\Domain\User\UserStatus;
use App\Shared\Application\Bus\EventHandler;

/** E-04 : confirmation de changement de mot de passe (réinitialisation ou espace « Mon compte »). */
final readonly class SendPasswordChangedConfirmation implements EventHandler
{
    public function __construct(private UserRepository $users, private IdentityMailer $mailer)
    {
    }

    public function __invoke(UserPasswordChanged $event): void
    {
        $user = $this->users->find(new UserId($event->userId));
        if (null !== $user && UserStatus::Anonymized !== $user->status()) {
            $this->mailer->sendPasswordChanged($user->email(), $user->firstName(), $event->at);
        }
    }
}
