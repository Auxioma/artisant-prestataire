<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Draft;

use App\Shared\Application\Bus\Command;

/**
 * P11.7 « Envoyer ma demande » : le brouillon complet du client devient une demande (RG-063 à RG-066), ses pièces et
 * son adresse scellée y sont reprises, puis le brouillon est supprimé. E-mail vérifié (RG-040) et captcha contrôlés
 * par l'appelant.
 */
final readonly class SubmitRequestDraft implements Command
{
    /** @param bool|null $broadcastToOthers choix « Recevoir aussi d'autres devis » du récapitulatif (demande directe) */
    public function __construct(public string $userId, public string $requestId, public ?string $ipHash = null, public ?bool $broadcastToOthers = null)
    {
    }
}
