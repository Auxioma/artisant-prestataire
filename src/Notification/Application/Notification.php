<?php

declare(strict_types=1);

namespace App\Notification\Application;

/**
 * Demande d'envoi d'une notification de l'annexe F. Le module émetteur fournit le destinataire et les variables du
 * modèle (chaînes, nombres, booléens, dates) ; le contenu, les canaux et l'activation relèvent du back-office (BO11).
 */
final readonly class Notification
{
    /**
     * @param array<string, scalar|\DateTimeInterface|null> $variables
     */
    public function __construct(
        public string $code,
        public ?string $email,
        public ?string $userId,
        public array $variables,
        public ?string $inAppLink = null,
    ) {
    }
}
