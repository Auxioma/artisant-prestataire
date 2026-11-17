<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile;

/**
 * Zone d'intervention (A03.4, RG-042). Le point de départ est une commune (son centroïde, RG-022) : l'adresse exacte de
 * l'artisan n'est ni demandée ni conservée. L'existence des communes et départements est vérifiée par le cas d'usage ;
 * la matérialisation des communes couvertes (`service_area_commune`) relève de T-208.
 */
final readonly class ServiceArea
{
    /** Bornes techniques du mode liste (101 départements ; au-delà, le mode rayon est plus adapté). */
    public const int MAX_DEPARTMENTS = 101;
    public const int MAX_COMMUNES = 500;
    private const string INSEE = '/^(\d{2}|2A|2B)\d{3}$/';
    private const string DEPARTMENT = '/^(\d{2}|2A|2B|97\d)$/';

    /**
     * @param list<string> $departments
     * @param list<string> $communes
     */
    private function __construct(
        public ServiceAreaMode $mode,
        public ?string $centerInsee,
        public ?int $radiusKm,
        public array $departments,
        public array $communes,
    ) {
    }

    /** Rayon entre `pro.radius_min_km` et `pro.radius_max_km` (bornes fournies par le cas d'usage). */
    public static function radius(string $centerInsee, int $radiusKm, int $minKm, int $maxKm): self
    {
        if (1 !== preg_match(self::INSEE, $centerInsee)) {
            throw new ArtisanRuleViolation('Définissez votre zone d’intervention.');
        }
        if ($radiusKm < $minKm || $radiusKm > $maxKm) {
            throw new ArtisanRuleViolation(\sprintf('Le rayon d’intervention doit être compris entre %d et %d km.', $minKm, $maxKm));
        }

        return new self(ServiceAreaMode::Radius, $centerInsee, $radiusKm, [], []);
    }

    /**
     * @param list<string> $departments
     * @param list<string> $communes
     */
    public static function list(array $departments, array $communes): self
    {
        $departments = array_values(array_unique($departments));
        $communes = array_values(array_unique($communes));
        if ([] === $departments && [] === $communes) {
            throw new ArtisanRuleViolation('Définissez votre zone d’intervention.');
        }
        if (\count($departments) > self::MAX_DEPARTMENTS || \count($communes) > self::MAX_COMMUNES) {
            throw new ArtisanRuleViolation(\sprintf('Zone trop étendue : %d communes au maximum en mode liste (utilisez le mode rayon).', self::MAX_COMMUNES));
        }
        foreach ($departments as $code) {
            if (1 !== preg_match(self::DEPARTMENT, $code)) {
                throw new ArtisanRuleViolation('Département inconnu.');
            }
        }
        foreach ($communes as $code) {
            if (1 !== preg_match(self::INSEE, $code)) {
                throw new ArtisanRuleViolation('Commune inconnue.');
            }
        }
        sort($departments);

        return new self(ServiceAreaMode::List, null, null, $departments, $communes);
    }

    /**
     * Reconstitution depuis la persistance (données déjà validées).
     *
     * @param list<string> $departments
     * @param list<string> $communes
     */
    public static function restore(ServiceAreaMode $mode, ?string $centerInsee, ?int $radiusKm, array $departments, array $communes): self
    {
        return new self($mode, $centerInsee, $radiusKm, $departments, $communes);
    }
}
