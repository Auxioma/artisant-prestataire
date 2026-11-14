<?php

declare(strict_types=1);

namespace App\Billing\Application\Checkout;

/**
 * PR12.2 — informations de facturation saisies (ou pré-remplies) : raison sociale, adresse, pays de l'Union, n° de TVA
 * intracommunautaire facultatif. Normalisées et validées à la construction.
 */
final readonly class BillingDetails
{
    /** États membres de l'Union (autoliquidation de la TVA hors France avec un n° valide, ADR-0009). */
    public const array COUNTRIES = [
        'FR' => 'France', 'AT' => 'Autriche', 'BE' => 'Belgique', 'BG' => 'Bulgarie', 'CY' => 'Chypre', 'CZ' => 'Tchéquie',
        'DE' => 'Allemagne', 'DK' => 'Danemark', 'EE' => 'Estonie', 'ES' => 'Espagne', 'FI' => 'Finlande', 'GR' => 'Grèce',
        'HR' => 'Croatie', 'HU' => 'Hongrie', 'IE' => 'Irlande', 'IT' => 'Italie', 'LT' => 'Lituanie', 'LU' => 'Luxembourg',
        'LV' => 'Lettonie', 'MT' => 'Malte', 'NL' => 'Pays-Bas', 'PL' => 'Pologne', 'PT' => 'Portugal', 'RO' => 'Roumanie',
        'SE' => 'Suède', 'SI' => 'Slovénie', 'SK' => 'Slovaquie',
    ];
    /** Préfixe TVA de la Grèce : EL (et non GR). */
    private const array VAT_PREFIX = ['GR' => 'EL'];

    public function __construct(
        public string $companyName,
        public string $street,
        public string $postalCode,
        public string $city,
        public string $country,
        public ?string $vatNumber,
    ) {
        foreach (['raison sociale' => $companyName, 'adresse' => $street, 'code postal' => $postalCode, 'ville' => $city] as $label => $value) {
            if ('' === $value) {
                throw new CheckoutRejected(\sprintf('Indiquez la %s.', $label), 'billing');
            }
        }
        if (mb_strlen($companyName) > 120 || mb_strlen($street) > 200 || mb_strlen($city) > 100 || mb_strlen($postalCode) > 10) {
            throw new CheckoutRejected('Une information de facturation est trop longue.', 'billing');
        }
        if (!isset(self::COUNTRIES[$country])) {
            throw new CheckoutRejected('Choisissez un pays de l’Union européenne.', 'billing');
        }
        if ('FR' === $country && 1 !== preg_match('/^\d{5}$/', $postalCode)) {
            throw new CheckoutRejected('Le code postal doit comporter 5 chiffres.', 'billing');
        }
        if (null !== $vatNumber && 1 !== preg_match('/^[A-Z]{2}[0-9A-Z]{2,13}$/', $vatNumber)) {
            throw new CheckoutRejected('Numéro de TVA intracommunautaire invalide (ex. : FR12345678901).', 'vat');
        }
        if (null !== $vatNumber && !str_starts_with($vatNumber, self::VAT_PREFIX[$country] ?? $country)) {
            throw new CheckoutRejected('Le numéro de TVA ne correspond pas au pays de facturation.', 'vat');
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $text = static fn (string $k): string => \is_string($input[$k] ?? null) ? trim(preg_replace('/\s+/u', ' ', $input[$k]) ?? '') : '';
        $vat = strtoupper(str_replace([' ', '.', '-'], '', $text('vat_number')));

        return new self($text('company_name'), $text('street'), strtoupper($text('postal_code')), $text('city'), strtoupper($text('country')) ?: 'FR', '' === $vat ? null : $vat);
    }

    /** Facturation hors de France : la TVA est autoliquidée par le client si son numéro est valide. */
    public function isForeignEu(): bool
    {
        return 'FR' !== $this->country;
    }
}
