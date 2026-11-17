<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile;

/** RG-043 : incomplet → en attente de vérification → vérifié | refusé ; suspendu (machine complète : T-208). */
enum ArtisanStatus: string
{
    case Incomplete = 'incomplete';
    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
}
