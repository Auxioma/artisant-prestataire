<?php

declare(strict_types=1);

namespace App\Identity\Domain\Consent;

/** Point de recueil du consentement (traçabilité de la preuve). */
enum ConsentSource: string
{
    case AcceptAll = 'accept_all';
    case RefuseAll = 'refuse_all';
    case Custom = 'custom';
}
