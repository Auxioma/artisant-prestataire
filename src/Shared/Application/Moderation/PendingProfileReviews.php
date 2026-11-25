<?php

declare(strict_types=1);

namespace App\Shared\Application\Moderation;

use App\Shared\Application\Bus\Query;

/** BO05 « Contenus de profils » — échantillon RG-031 à relire, plus anciens d'abord. Traitée par ArtisanProfile ; réponse : list<ProfileReviewItem>. */
final readonly class PendingProfileReviews implements Query
{
}
