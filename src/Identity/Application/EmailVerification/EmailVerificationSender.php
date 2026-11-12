<?php

declare(strict_types=1);

namespace App\Identity\Application\EmailVerification;

use App\Identity\Application\Port\EmailTokens;
use App\Identity\Application\Port\IdentityMailer;
use App\Identity\Domain\User\User;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\Email;

/** Émet un jeton (les précédents sont révoqués) et envoie le lien. Délai minimal entre deux envois (CDC A02 : 60 s). */
final readonly class EmailVerificationSender
{
    public function __construct(
        private EmailTokens $tokens,
        private IdentityMailer $mailer,
        private SettingsProvider $settings,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    /** @return bool faux si un lien vient d'être envoyé (délai de renvoi non écoulé) */
    public function send(User $user, EmailTokenPurpose $purpose, ?Email $newEmail = null, bool $respectCooldown = true, bool $fromRequest = false): bool
    {
        $now = $this->clock->now();
        $last = $this->tokens->lastIssuedAt($user->id()->value, $purpose);
        $cooldown = $this->settings->int(SettingKey::AuthEmailResendCooldownSec);
        if ($respectCooldown && null !== $last && $last->modify(\sprintf('+%d seconds', $cooldown)) > $now) {
            return false;
        }

        $ttl = $this->settings->int(SettingKey::AuthEmailVerificationTtlHours);
        $token = SecretToken::generate();
        $this->tokens->issue($this->ids->next(), $user->id()->value, $purpose, $newEmail?->value, $token->hash, $now, $now->modify(\sprintf('+%d hours', $ttl)));
        $this->mailer->sendEmailVerification($newEmail ?? $user->email(), $user->firstName(), $token->plain, $ttl, EmailTokenPurpose::EmailChange === $purpose, $fromRequest);

        return true;
    }
}
