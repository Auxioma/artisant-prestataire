<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Vies;

use App\Billing\Application\Port\VatNumberCheck;
use App\Billing\Application\Port\VatNumberChecker;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Domain\Time\Clock;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** API REST VIES. Un format invalide est rejeté localement, sans appel ; seules les erreurs de service lèvent une exception. */
final readonly class ViesVatNumberChecker implements VatNumberChecker
{
    private const string SERVICE = 'vies';
    /** États membres + Irlande du Nord (XI) ; la Grèce utilise le préfixe EL. */
    private const array COUNTRIES = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI'];
    private const string UNKNOWN = '---';

    public function __construct(
        #[Target('vies.client')]
        private HttpClientInterface $client,
        private Clock $clock,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function check(string $vatNumber): VatNumberCheck
    {
        $normalized = strtoupper((string) preg_replace('/[\s.\-]/', '', $vatNumber));
        if (str_starts_with($normalized, 'GR')) {
            $normalized = 'EL'.substr($normalized, 2);
        }
        $country = substr($normalized, 0, 2);
        $number = substr($normalized, 2);
        if (!\in_array($country, self::COUNTRIES, true) || 1 !== preg_match('/^[0-9A-Z+*]{2,12}$/', $number)
            || ('FR' === $country && !self::hasValidFrenchKey($number))) {
            return new VatNumberCheck($normalized, false, null, null, $this->clock->now());
        }

        try {
            $data = $this->client->request('GET', 'ms/'.$country.'/vat/'.$number)->toArray();
        } catch (ExceptionInterface $e) {
            $this->logger->warning('VIES injoignable.', ['error' => $e->getMessage()]);
            throw new ExternalServiceUnavailable(self::SERVICE, $e->getMessage(), $e);
        }

        $outcome = \is_string($data['userError'] ?? null) ? $data['userError'] : '';
        if (!\in_array($outcome, ['VALID', 'INVALID', 'INVALID_INPUT'], true)) {
            // MS_UNAVAILABLE, TIMEOUT, SERVICE_UNAVAILABLE, MS_MAX_CONCURRENT_REQ… : registre national momentanément absent.
            $this->logger->notice('VIES : vérification impossible.', ['country' => $country, 'reason' => $outcome]);
            throw new ExternalServiceUnavailable(self::SERVICE, '' === $outcome ? 'réponse illisible' : $outcome);
        }

        return new VatNumberCheck(
            $normalized,
            'VALID' === $outcome && true === ($data['isValid'] ?? null),
            self::text($data['name'] ?? null),
            self::text($data['address'] ?? null),
            $this->clock->now(),
        );
    }

    /** TVA française : FR + clé (2 caractères) + SIREN ; clé numérique = (12 + 3 × (SIREN mod 97)) mod 97. */
    private static function hasValidFrenchKey(string $number): bool
    {
        if (1 !== preg_match('/^([0-9A-Z]{2})(\d{9})$/', $number, $m)) {
            return false;
        }
        if (1 !== preg_match('/^\d{2}$/', $m[1])) {
            return true; // anciennes clés alphanumériques : laissées à VIES
        }

        return (int) $m[1] === (12 + 3 * ((int) $m[2] % 97)) % 97;
    }

    private static function text(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);

        return '' === $value || self::UNKNOWN === $value ? null : $value;
    }
}
