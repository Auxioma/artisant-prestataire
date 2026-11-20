<?php

declare(strict_types=1);

namespace App\Tests\Unit\Integration;

use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Domain\ValueObject\Siret;
use App\Shared\Infrastructure\Integration\Sirene\InseeSireneCompanyRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** A03 étape 2 / RG-041 : lecture de la fiche Sirene, état administratif, non-diffusion, indisponibilité. */
final class InseeSireneCompanyRegistryTest extends TestCase
{
    private const string SIRET = '90000000100017'; // fictif (clé de Luhn valide)

    /** @return array<mixed> */
    private static function fixture(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/external/sirene-siret.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    private static function registry(MockHttpClient $client, string $key = 'cle-de-test'): InseeSireneCompanyRegistry
    {
        return new InseeSireneCompanyRegistry($client, $key);
    }

    public function testMapsAnActiveEstablishment(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://api.insee.test/siret/'.self::SIRET, $url);

            return new MockResponse((string) json_encode(self::fixture()));
        }, 'https://api.insee.test/');

        $company = self::registry($client)->find(new Siret(self::SIRET));

        self::assertNotNull($company);
        self::assertSame('PLOMBERIE FICTIVE DE TEST', $company->name);
        self::assertTrue($company->active);
        self::assertTrue($company->headOffice);
        self::assertSame('43.22A', $company->nafCode);
        self::assertSame('12 B RUE DE L EXEMPLE', $company->street);
        self::assertSame(['14000', 'CAEN', '14118'], [$company->postalCode, $company->city, $company->inseeCode]);
        self::assertSame('2019-03-01', $company->createdOn?->format('Y-m-d'));
    }

    public function testClosedEstablishmentOrUnitIsInactive(): void
    {
        $data = self::fixture();
        \assert(\is_array($data['etablissement']) && \is_array($data['etablissement']['periodesEtablissement']) && \is_array($data['etablissement']['periodesEtablissement'][0]));
        $data['etablissement']['periodesEtablissement'][0]['etatAdministratifEtablissement'] = 'F';

        $company = self::registry(new MockHttpClient(new MockResponse((string) json_encode($data))))->find(new Siret(self::SIRET));

        self::assertNotNull($company);
        self::assertFalse($company->active);
    }

    public function testIndividualEntrepreneurNameAndUndisclosedFields(): void
    {
        $data = self::fixture();
        \assert(\is_array($data['etablissement']) && \is_array($data['etablissement']['uniteLegale']) && \is_array($data['etablissement']['adresseEtablissement']));
        $data['etablissement']['uniteLegale'] = ['etatAdministratifUniteLegale' => 'A', 'denominationUniteLegale' => null, 'prenomUsuelUniteLegale' => 'CAMILLE', 'nomUniteLegale' => 'EXEMPLE', 'nomUsageUniteLegale' => null];
        $data['etablissement']['adresseEtablissement']['libelleVoieEtablissement'] = '[ND]';
        $data['etablissement']['adresseEtablissement']['numeroVoieEtablissement'] = '[ND]';

        $company = self::registry(new MockHttpClient(new MockResponse((string) json_encode($data))))->find(new Siret(self::SIRET));

        self::assertNotNull($company);
        self::assertSame('CAMILLE EXEMPLE', $company->name);
        self::assertSame('B RUE', $company->street);
    }

    public function testUnknownSiretReturnsNull(): void
    {
        $client = new MockHttpClient(new MockResponse('{"header":{"statut":404,"message":"Aucun élément trouvé"}}', ['http_code' => 404]));

        self::assertNull(self::registry($client)->find(new Siret(self::SIRET)));
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function failures(): iterable
    {
        yield 'clé refusée' => [new MockResponse('', ['http_code' => 401])];
        yield 'non diffusible' => [new MockResponse('', ['http_code' => 403])];
        yield 'quota' => [new MockResponse('', ['http_code' => 429])];
        yield 'erreur serveur' => [new MockResponse('', ['http_code' => 503])];
        yield 'réseau' => [new MockResponse((static function (): \Generator {
            yield '';
            throw new TransportException('Connection timed out');
        })())];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testFailuresAreReportedAsUnavailable(MockResponse $response): void
    {
        $this->expectException(ExternalServiceUnavailable::class);
        self::registry(new MockHttpClient($response))->find(new Siret(self::SIRET));
    }

    public function testMissingKeyFailsWithoutCallingTheApi(): void
    {
        $client = new MockHttpClient([]);

        try {
            self::registry($client, '')->find(new Siret(self::SIRET));
            self::fail('Exception attendue');
        } catch (ExternalServiceUnavailable $e) {
            self::assertSame('sirene', $e->service);
        }
        self::assertSame(0, $client->getRequestsCount());
    }
}
