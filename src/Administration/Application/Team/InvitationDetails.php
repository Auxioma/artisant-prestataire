<?php

declare(strict_types=1);

namespace App\Administration\Application\Team;

use App\Shared\Application\Bus\Query;

/** Invitation encore valide pour ce jeton ? Réponse : AdminMemberView, ou null (lien inconnu, expiré ou déjà utilisé). */
final readonly class InvitationDetails implements Query
{
    public function __construct(#[\SensitiveParameter] public string $token)
    {
    }
}
