<?php

declare(strict_types=1);

namespace App\QuoteRequest\Domain\Request;

use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\ValueObject\Slug;

/**
 * RG-065 — empreinte « même métier + même adresse » de l'anti-doublon. L'adresse est normalisée (casse, accents,
 * ponctuation, espaces) puis hachée avec le métier : la colonne n'expose pas l'adresse (RG-022bis).
 */
final readonly class DuplicateKey
{
    private const string ALGORITHM = 'sha256';

    private function __construct(public string $value)
    {
    }

    public static function of(string $jobId, string $address): self
    {
        try {
            $normalized = Slug::fromText($address)->value;
        } catch (InvalidValue) {
            throw new RequestRuleViolation('Adresse d’intervention invalide.');
        }

        return new self(hash(self::ALGORITHM, strtolower($jobId).'|'.$normalized));
    }

    public static function fromStored(string $value): self
    {
        return new self($value);
    }
}
