<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Quote;

/**
 * Identité de l'entreprise reprise sur le devis (PR04.1 « coordonnées de l'entreprise pré-remplies ») et éléments du
 * contrôle RG-120 : SIRET, siège, régime de TVA, assurances déclarées et exigées par ses métiers.
 */
final readonly class QuoteIssuerView
{
    /** @param list<array{kind: string, label: string, insurer: string, policy: string, validUntil: \DateTimeImmutable}> $insurances */
    public function __construct(
        public string $artisanId,
        public bool $verified,
        public string $name,
        public ?string $legalName,
        public ?string $siret,
        public ?string $address,
        public bool $vatExempt,
        public ?string $vatNumber,
        public array $insurances,
        public bool $insuranceRequired,
        public bool $decennialRequired,
        public bool $hasPublicLogo,
    ) {
    }

    /** Assurance du type demandé valable à la date donnée. */
    public function insuredOn(string $kind, \DateTimeImmutable $at): bool
    {
        foreach ($this->insurances as $insurance) {
            if ($insurance['kind'] === $kind && $insurance['validUntil'] >= $at) {
                return true;
            }
        }

        return false;
    }
}
