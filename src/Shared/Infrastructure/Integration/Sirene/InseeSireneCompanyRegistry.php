<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Integration\Sirene;

use App\Shared\Application\Integration\CompanyRecord;
use App\Shared\Application\Integration\CompanyRegistry;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Domain\ValueObject\Siret;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * API Sirene 3.11 (portail des API de l'INSEE, clé « X-INSEE-Api-Key-Integration »). Client à hôte fixe, réseau
 * privé interdit (cadrage §5.3) ; le SIRET, déjà validé (Luhn), est le seul élément variable de l'URL.
 */
final readonly class InseeSireneCompanyRegistry implements CompanyRegistry
{
    private const string SERVICE = 'sirene';
    /** Valeur renvoyée par l'INSEE pour un champ non diffusé (diffusion partielle). */
    private const string NOT_DIFFUSED = '[ND]';

    public function __construct(
        #[Target('sirene.client')]
        private HttpClientInterface $client,
        #[Autowire('%env(INSEE_SIRENE_API_KEY)%')]
        private string $apiKey,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function find(Siret $siret): ?CompanyRecord
    {
        if ('' === $this->apiKey) {
            throw new ExternalServiceUnavailable(self::SERVICE, 'clé d’API non configurée');
        }

        try {
            $response = $this->client->request('GET', 'siret/'.$siret->value);
            $status = $response->getStatusCode();
            if (404 === $status) {
                return null;
            }
            if (200 !== $status) {
                // 401/403 : clé refusée ou établissement non diffusible ; 429 : quota (30 req./min) épuisé malgré les reprises.
                $this->logger->warning('Sirene : réponse inattendue.', ['status' => $status]);
                throw new ExternalServiceUnavailable(self::SERVICE, 'HTTP '.$status);
            }
            $data = $response->toArray();
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Sirene injoignable.', ['error' => $e->getMessage()]);
            throw new ExternalServiceUnavailable(self::SERVICE, $e->getMessage(), $e);
        }

        $establishment = self::map($data['etablissement'] ?? null);
        $unit = self::map($establishment['uniteLegale'] ?? null);
        $periods = $establishment['periodesEtablissement'] ?? [];
        $current = self::map(\is_array($periods) ? ($periods[0] ?? null) : null);
        $address = self::map($establishment['adresseEtablissement'] ?? null);

        return new CompanyRecord(
            self::text($establishment['siret'] ?? null) ?? $siret->value,
            self::name($unit),
            'A' === ($current['etatAdministratifEtablissement'] ?? null) && 'A' === ($unit['etatAdministratifUniteLegale'] ?? null),
            true === ($establishment['etablissementSiege'] ?? null),
            self::text($current['activitePrincipaleEtablissement'] ?? null),
            self::text($unit['categorieJuridiqueUniteLegale'] ?? null),
            self::street($address),
            self::text($address['codePostalEtablissement'] ?? null),
            self::text($address['libelleCommuneEtablissement'] ?? null),
            self::text($address['codeCommuneEtablissement'] ?? null),
            self::date($establishment['dateCreationEtablissement'] ?? null),
        );
    }

    /**
     * Personne morale : dénomination ; entrepreneur individuel : prénom usuel et nom d'usage (ou de naissance).
     *
     * @param array<mixed> $unit
     */
    private static function name(array $unit): ?string
    {
        $denomination = self::text($unit['denominationUniteLegale'] ?? null);
        if (null !== $denomination) {
            return $denomination;
        }
        $first = self::text($unit['prenomUsuelUniteLegale'] ?? null) ?? self::text($unit['prenom1UniteLegale'] ?? null);
        $last = self::text($unit['nomUsageUniteLegale'] ?? null) ?? self::text($unit['nomUniteLegale'] ?? null);
        $name = trim(($first ?? '').' '.($last ?? ''));

        return '' === $name ? null : $name;
    }

    /** @param array<mixed> $address */
    private static function street(array $address): ?string
    {
        $parts = array_filter(array_map(self::text(...), [
            $address['numeroVoieEtablissement'] ?? null,
            $address['indiceRepetitionEtablissement'] ?? null,
            $address['typeVoieEtablissement'] ?? null,
            $address['libelleVoieEtablissement'] ?? null,
        ]), static fn (?string $p): bool => null !== $p);

        return [] === $parts ? null : implode(' ', $parts);
    }

    /** @return array<mixed> */
    private static function map(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    private static function text(mixed $value): ?string
    {
        if (\is_int($value)) {
            $value = (string) $value;
        }
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);

        return '' === $value || self::NOT_DIFFUSED === $value ? null : $value;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        $text = self::text($value);
        $date = null === $text ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        return false === $date ? null : $date;
    }
}
