<?php

declare(strict_types=1);

namespace App\Identity\UI\Form;

use App\Identity\Application\Registration\RegisterArtisanAccountHandler;
use App\Identity\Domain\User\IdentityRuleViolation;
use App\Identity\Domain\User\PersonName;
use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\ValueObject\Email;
use App\Shared\Domain\ValueObject\PhoneNumber;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** A03.1 — compte du dirigeant. */
final class ArtisanAccountData
{
    public ?string $firstName = null;
    public ?string $lastName = null;
    public ?string $email = null;
    public ?string $mobile = null;
    public ?string $password = null;

    #[Assert\IsTrue(message: 'Vous devez accepter les CGU et les CGV professionnelles.')]
    public bool $terms = false;
    public string $legalVersions = '';

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        foreach (['lastName' => 'Veuillez saisir votre nom.', 'firstName' => 'Veuillez saisir votre prénom.'] as $field => $message) {
            try {
                new PersonName((string) $this->{$field});
            } catch (IdentityRuleViolation) {
                $context->buildViolation($message)->atPath($field)->addViolation();
            }
        }
        try {
            new Email((string) $this->email);
        } catch (InvalidValue) {
            $context->buildViolation('Adresse email invalide.')->atPath('email')->addViolation();
        }
        try {
            if (!RegisterArtisanAccountHandler::isMobile(new PhoneNumber((string) $this->mobile))) {
                $context->buildViolation('Saisissez un numéro de mobile (06 ou 07).')->atPath('mobile')->addViolation();
            }
        } catch (InvalidValue) {
            $context->buildViolation('Numéro de téléphone invalide.')->atPath('mobile')->addViolation();
        }
    }

    /** @return list<string> */
    public function legalVersionIds(): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $this->legalVersions)), static fn (string $id): bool => 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)));
    }
}
