<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile;

/** PR08.5 — prestation et tarif indicatif (« à partir de 80 € », « sur devis »…). */
final readonly class ServiceOffer
{
    public const int MAX = 20;
    public const int TITLE_MAX = 80;
    public const int PRICE_MAX = 40;

    public function __construct(public string $title, public ?string $price)
    {
        if ('' === trim($title) || mb_strlen($title) > self::TITLE_MAX || (null !== $price && mb_strlen($price) > self::PRICE_MAX)) {
            throw new ArtisanRuleViolation(\sprintf('Prestation : intitulé obligatoire (%d caractères max), tarif %d caractères max.', self::TITLE_MAX, self::PRICE_MAX));
        }
    }
}
