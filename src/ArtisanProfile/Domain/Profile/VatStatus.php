<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile;

/** A03.2 : assujetti à la TVA ou franchise en base (art. 293 B du CGI : pas de TVA facturée). */
enum VatStatus: string
{
    case Liable = 'assujetti';
    case Franchise = 'franchise';
}
