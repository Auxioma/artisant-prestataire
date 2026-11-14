<?php

declare(strict_types=1);

namespace App\Shared\Application\Moderation;

use App\Shared\Application\Bus\Command;

/**
 * BO05 « Messages signalés » — signalement classé sans suite, ou message retiré de l'affichage des deux participants
 * (texte conservé comme preuve) avec un motif normalisé. Traitée par Messaging ; lève ModerationDecisionRejected.
 */
final readonly class DecideReportedMessage implements Command
{
    public const array REASONS = [
        'abuse' => 'Propos injurieux ou menaçants',
        'spam' => 'Démarchage ou publicité',
        'fraud' => 'Tentative de fraude',
        'contact' => 'Contournement de la plateforme',
        'personal_data' => 'Données personnelles',
    ];

    public function __construct(public string $adminId, public string $messageId, public bool $remove, public ?string $reason = null)
    {
    }
}
