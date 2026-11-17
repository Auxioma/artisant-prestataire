<?php

declare(strict_types=1);

namespace App\Identity\Application\EventHandler;

use App\Identity\Application\EmailVerification\EmailTokenPurpose;
use App\Identity\Application\EmailVerification\EmailVerificationSender;
use App\Identity\Domain\User\Event\UserRegistered;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Identity\Domain\User\UserStatus;
use App\Shared\Application\Bus\EventHandler;

/** E-01 (ou E-11 depuis le tunnel P11) après inscription (asynchrone, relayé par l'outbox : jamais envoyé si l'inscription a échoué). */
final readonly class SendEmailVerificationOnRegistration implements EventHandler
{
    /** Compte créé dans le tunnel de demande : E-11 au lieu d'E-01 (P11.6). */
    public const string REQUEST = 'request';

    public function __construct(private UserRepository $users, private EmailVerificationSender $sender)
    {
    }

    public function __invoke(UserRegistered $event): void
    {
        $user = $this->users->find(new UserId($event->userId));
        if (null !== $user && UserStatus::PendingEmail === $user->status()) {
            $this->sender->send($user, EmailTokenPurpose::Signup, fromRequest: self::REQUEST === $event->origin);
        }
    }
}
