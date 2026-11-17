<?php

declare(strict_types=1);

namespace App\Identity\Application\Client;

use App\Identity\Domain\Client\ClientInterest;
use App\Identity\Domain\Client\ClientType;

final readonly class ClientProfileView
{
    /** @param list<ClientInterest> $interests */
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $email,
        public bool $emailVerified,
        public ?string $phone,
        public ClientType $type,
        public ?string $companyName,
        public ?string $siret,
        public string $postalCode,
        public ?string $city,
        public ?int $searchRadiusKm,
        public array $interests,
        public bool $newsletter,
        public ?string $avatarFileId,
    ) {
    }

    public function initials(): string
    {
        return mb_strtoupper(mb_substr($this->firstName, 0, 1).mb_substr($this->lastName, 0, 1));
    }

    public function hasInterest(ClientInterest $interest): bool
    {
        return \in_array($interest, $this->interests, true);
    }

    /** « 06 12 34 56 78 » pour un numéro français, forme internationale sinon. */
    public function phoneDisplay(): ?string
    {
        if (null === $this->phone) {
            return null;
        }
        if (1 === preg_match('/^\+33(\d{9})$/', $this->phone, $m)) {
            return trim(chunk_split('0'.$m[1], 2, ' '));
        }

        return $this->phone;
    }

    public function place(): string
    {
        return null === $this->city ? $this->postalCode : $this->city.' ('.$this->postalCode.')';
    }
}
