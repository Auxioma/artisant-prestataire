<?php

declare(strict_types=1);

namespace App\Shared\Domain\Storage;

/** quarantined → available | rejected ; available → shredded (crypto-shredding / suppression RGPD). */
enum FileStatus: string
{
    case Quarantined = 'quarantined';
    case Available = 'available';
    case Rejected = 'rejected';
    case Shredded = 'shredded';
}
