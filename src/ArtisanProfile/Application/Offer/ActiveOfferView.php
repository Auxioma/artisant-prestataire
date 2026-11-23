<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Offer;

final readonly class ActiveOfferView
{
    public const string PERCENT = 'percent';
    public const string AMOUNT = 'amount';

    public function __construct(public string $id, public string $title, public string $type, public ?float $value, public \DateTimeImmutable $endsOn)
    {
    }

    /** Libellé repris dans la demande : « Entretien chaudière (−10 %, jusqu'au 31/12/2026) ». */
    public function label(): string
    {
        $value = null === $this->value ? '' : rtrim(rtrim(number_format($this->value, 2, ',', ' '), '0'), ',');
        $deal = match ($this->type) {
            self::PERCENT => '−'.$value.' %',
            self::AMOUNT => '−'.$value.' €',
            default => 'offert',
        };

        return \sprintf('%s (%s, jusqu’au %s)', $this->title, $deal, $this->endsOn->format('d/m/Y'));
    }
}
