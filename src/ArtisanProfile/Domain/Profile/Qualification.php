<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile;

/** A03.5 — label ou qualification (RGE, Qualibat…) : type, numéro, date de validité. « Vérifié » après contrôle (RG-032). */
final readonly class Qualification
{
    private const int NAME_MAX = 80;
    private const int NUMBER_MAX = 60;

    public string $name;
    public string $number;

    public function __construct(string $name, string $number, public \DateTimeImmutable $validUntil)
    {
        $this->name = Text::line($name, 2, self::NAME_MAX) ?? throw new ArtisanRuleViolation('Indiquez le type de label ou de qualification.');
        $this->number = Text::line($number, 1, self::NUMBER_MAX) ?? throw new ArtisanRuleViolation(\sprintf('%s : indiquez le numéro.', $this->name));
    }

    public function isExpiredOn(\DateTimeImmutable $today): bool
    {
        return $this->validUntil->format('Y-m-d') < $today->format('Y-m-d');
    }

    /** @return array{name: string, number: string, valid_until: string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'number' => $this->number, 'valid_until' => $this->validUntil->format('Y-m-d')];
    }

    /** @param array{name: string, number: string, valid_until: string} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['number'], new \DateTimeImmutable($data['valid_until']));
    }
}
