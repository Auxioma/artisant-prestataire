<?php

declare(strict_types=1);

namespace App\Identity\UI\Form;

use App\Identity\Domain\Client\ClientType;
use App\Identity\Domain\User\IdentityRuleViolation;
use App\Identity\Domain\User\PersonName;
use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\ValueObject\Email;
use App\Shared\Domain\ValueObject\PhoneNumber;
use App\Shared\Domain\ValueObject\Siret;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A02 — saisie du formulaire d'inscription client. Messages repris du CDC (A02, tableau des validations).
 * Les règles de format réutilisent les objets valeur du domaine ; les règles métier (e-mail jetable, code postal
 * existant, SIRET actif, mot de passe compromis) sont vérifiées par le cas d'usage.
 */
final class ClientRegistrationData
{
    public string $clientType = ClientType::Individual->value;
    public ?string $companyName = null;
    public ?string $siret = null;
    public ?string $lastName = null;
    public ?string $firstName = null;
    public ?string $email = null;
    public ?string $phone = null;
    public ?string $postalCode = null;
    public ?string $password = null;

    #[Assert\IsTrue(message: 'Vous devez accepter les conditions générales d’utilisation.')]
    public bool $terms = false;
    public bool $newsletter = false;
    /** Identifiants des versions de CGU et de politique de confidentialité affichées (RG-002). */
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
        if (null !== $this->phone && '' !== trim($this->phone)) {
            try {
                new PhoneNumber($this->phone);
            } catch (InvalidValue) {
                $context->buildViolation('Numéro de téléphone invalide.')->atPath('phone')->addViolation();
            }
        }
        if (1 !== preg_match('/^\d{5}$/', trim((string) $this->postalCode))) {
            $context->buildViolation('Code postal inconnu.')->atPath('postalCode')->addViolation();
        }
        if (null === ClientType::tryFrom($this->clientType)) {
            $context->buildViolation('Choisissez un type de compte.')->atPath('clientType')->addViolation();
        }
        if (ClientType::Business->value === $this->clientType) {
            $length = mb_strlen(trim((string) $this->companyName));
            if ($length < 2 || $length > 120) {
                $context->buildViolation('Veuillez saisir la raison sociale.')->atPath('companyName')->addViolation();
            }
            if (null !== $this->siret && '' !== trim($this->siret)) {
                try {
                    new Siret($this->siret);
                } catch (InvalidValue) {
                    $context->buildViolation('Numéro SIRET invalide (14 chiffres).')->atPath('siret')->addViolation();
                }
            }
        }
    }

    /** @return list<string> */
    public function legalVersionIds(): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $this->legalVersions)), static fn (string $id): bool => 1 === preg_match('/^[0-9a-f-]{36}$/', $id)));
    }
}
