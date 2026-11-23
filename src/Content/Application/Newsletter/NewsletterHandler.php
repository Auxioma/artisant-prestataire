<?php

declare(strict_types=1);

namespace App\Content\Application\Newsletter;

use App\Content\Application\Port\NewsletterSubscribers;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\Email;

/**
 * Inscription (consentement explicite : l'adresse saisie et le bouton « S'inscrire », horodatés) et désinscription
 * par lien personnel. Réponse identique qu'une adresse soit nouvelle ou déjà inscrite (pas d'énumération).
 */
final readonly class NewsletterHandler implements CommandHandler
{
    private const int TOKEN_BYTES = 32;
    private const string SOURCE_PATTERN = '/^[a-z_]{1,30}$/';

    public function __construct(private NewsletterSubscribers $subscribers, private Clock $clock)
    {
    }

    public function __invoke(SubscribeToNewsletter|UnsubscribeFromNewsletter $command): void
    {
        if ($command instanceof UnsubscribeFromNewsletter) {
            if (1 !== preg_match('/^[0-9a-f]{64}$/', $command->token) || !$this->subscribers->unsubscribe($command->token, $this->clock->now())) {
                throw new NewsletterRejected('Ce lien de désinscription n’est pas valide.');
            }

            return;
        }
        try {
            $email = new Email($command->email);
        } catch (InvalidValue) {
            throw new NewsletterRejected('Veuillez saisir une adresse e-mail valide.');
        }
        $source = 1 === preg_match(self::SOURCE_PATTERN, $command->source) ? $command->source : 'footer';
        $this->subscribers->subscribe($command->id, $email->value, bin2hex(random_bytes(self::TOKEN_BYTES)), $source, $command->ipHash, $this->clock->now());
    }
}
