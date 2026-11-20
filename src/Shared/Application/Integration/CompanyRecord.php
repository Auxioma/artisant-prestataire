<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

/** Établissement tel que connu du répertoire Sirene (INSEE). Les champs non diffusés valent null. */
final readonly class CompanyRecord
{
    public function __construct(
        public string $siret,
        public ?string $name,
        public bool $active,
        public bool $headOffice,
        public ?string $nafCode,
        public ?string $legalCategory,
        public ?string $street,
        public ?string $postalCode,
        public ?string $city,
        public ?string $inseeCode,
        public ?\DateTimeImmutable $createdOn,
    ) {
    }
}
