<?php

declare(strict_types=1);

namespace App\Tests\Functional\ArtisanProfile;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateJob;
use App\Identity\Application\Legal\PublishLegalDocument;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\Fake\AcceptingHumanVerification;
use App\Tests\Support\Fake\FakeCompanyRegistry;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** A03 étapes 1 à 3 : compte, entreprise (RG-041), métiers (pro.max_jobs), reprise du tunnel. */
final class ArtisanSignupTest extends WebTestCase
{
    private const string EMAIL = 'dominique@exemple.test';
    private const string PASSWORD = 'cheval agrafe batterie';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $commands = self::getContainer()->get(CommandBus::class);
        \assert($commands instanceof CommandBus);
        foreach (['cgu' => 'CGU', 'cgv_pro' => 'CGV pro', 'privacy' => 'Confidentialité'] as $i => $title) {
            $commands->dispatch(new PublishLegalDocument('0190f5e4-0000-7000-8000-0000000d00'.(10 + array_search($i, ['cgu', 'cgv_pro', 'privacy'], true)), $i, $title, '<p>'.$title.' fictives de test</p>', false));
        }
        $commands->dispatch(new CreateCategory('Plomberie', 'wrench'));
        $commands->dispatch(new CreateJob('plomberie', 'Plombier', synonyms: ['Chauffagiste sanitaire']));
        $commands->dispatch(new CreateJob('plomberie', 'Chauffe-eau'));
    }

    private function rows(string $sql): string
    {
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $value = $connection->fetchOne($sql);

        return \is_int($value) || \is_bool($value) ? var_export($value, true) : DbalValue::string($value);
    }

    /** @param array<string, string|array<string, string>|null> $override valeur null = champ absent */
    private function createAccount(array $override = []): void
    {
        $crawler = $this->client->request('GET', '/inscription/pro');
        $this->client->request('POST', '/inscription/pro', ['artisan_account' => array_filter($override + [
            'firstName' => 'Dominique', 'lastName' => 'Exemple', 'email' => self::EMAIL, 'mobile' => '06 11 22 33 44',
            'password' => ['first' => self::PASSWORD, 'second' => self::PASSWORD], 'terms' => '1',
            'legalVersions' => (string) $crawler->filter('#artisan_account_legalVersions')->attr('value'),
            '_token' => (string) $crawler->filter('#artisan_account__token')->attr('value'),
        ], static fn ($v): bool => null !== $v), 'cf-turnstile-response' => AcceptingHumanVerification::HUMAN]);
    }

    /** @param array<string, string> $fields */
    private function company(array $fields): void
    {
        $crawler = $this->client->request('GET', '/inscription/pro/entreprise');
        $this->client->request('POST', '/inscription/pro/entreprise', $fields + ['_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'), 'vat_status' => 'assujetti']);
    }

    public function testFullFirstThreeSteps(): void
    {
        $this->createAccount();
        self::assertResponseRedirects('/inscription/pro/entreprise', 303);
        self::assertSame("'artisan'|+33611223344|3", $this->rows("SELECT quote_literal(kind) || '|' || phone || '|' || (SELECT count(*) FROM identity.legal_acceptance) FROM identity.user_account"));

        $this->company(['siret' => '900 000 001 00017', 'action' => 'verify']);
        self::assertSelectorTextContains('.tm-facts', 'PLOMBERIE FICTIVE DE TEST');
        self::assertSelectorTextContains('.tm-field--success', 'Établissement actif');
        self::assertSame('FR68900000001', $this->client->getCrawler()->filter('#vat_number')->attr('value'));

        $this->company(['siret' => FakeCompanyRegistry::ACTIVE, 'trade_name' => 'Atelier Fictif', 'vat_number' => 'FR68900000001', 'action' => 'continue']);
        self::assertResponseRedirects('/inscription/pro/metiers', 303);
        self::assertSame('PLOMBERIE FICTIVE DE TEST|43.22A|14118|true', $this->rows("SELECT company_name || '|' || ape || '|' || hq_city_insee || '|' || (siret_verified_at IS NOT NULL)::text FROM artisan.artisan_profile"));

        $crawler = $this->client->request('GET', '/inscription/pro/metiers');
        self::assertSelectorTextContains('.tm-job-category', 'Plombier');
        $ids = $crawler->filter('input[name="jobs[]"]')->each(static fn ($n): string => (string) $n->attr('value'));
        $this->client->request('POST', '/inscription/pro/metiers', ['_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'), 'jobs' => $ids, 'main' => $ids[1]]);
        self::assertResponseRedirects('/inscription/pro/zone', 303);
        self::assertSame('3|2', $this->rows("SELECT completed_step || '|' || jsonb_array_length(jobs) FROM artisan.artisan_profile"));

        $this->client->request('GET', '/inscription/pro/reprendre');
        self::assertResponseRedirects('/inscription/pro/zone');
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function rejectedCompanies(): iterable
    {
        yield 'clé invalide' => [['siret' => '12345678901234'], 'Ce numéro SIRET est invalide.'];
        yield 'inconnu du répertoire' => [['siret' => FakeCompanyRegistry::UNKNOWN], 'Ce numéro SIRET est invalide.'];
        yield 'établissement fermé' => [['siret' => FakeCompanyRegistry::CLOSED], 'Cet établissement est indiqué comme fermé.'];
        yield 'répertoire indisponible sans saisie' => [['siret' => FakeCompanyRegistry::UNAVAILABLE], 'saisissez la raison sociale'];
    }

    /** @param array<string, string> $fields */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedCompanies')]
    public function testCompanyRules(array $fields, string $message): void
    {
        $this->createAccount();
        $this->company($fields + ['action' => 'continue']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(htmlspecialchars($message, \ENT_QUOTES), (string) $this->client->getResponse()->getContent());
        self::assertSame('0', $this->rows('SELECT count(*) FROM artisan.artisan_profile WHERE completed_step >= 2'));
    }

    public function testUnavailableRegistryAllowsManualEntryVerifiedLater(): void
    {
        $this->createAccount();
        $this->company(['siret' => FakeCompanyRegistry::UNAVAILABLE, 'action' => 'verify']);
        self::assertSelectorExists('#company_name');

        $this->company(['siret' => FakeCompanyRegistry::UNAVAILABLE, 'company_name' => 'Couverture Fictive', 'postal_code' => '76600', 'city' => 'Le Havre', 'vat_status' => 'franchise', 'action' => 'continue']);

        self::assertResponseRedirects('/inscription/pro/metiers', 303);
        self::assertSame('Couverture Fictive|franchise|true', $this->rows("SELECT company_name || '|' || vat_status || '|' || (siret_verified_at IS NULL)::text FROM artisan.artisan_profile"));
    }

    public function testSiretIsUniqueAcrossProfessionalAccounts(): void
    {
        $this->createAccount();
        $this->company(['siret' => FakeCompanyRegistry::ACTIVE, 'vat_number' => 'FR68900000001', 'action' => 'continue']);
        $this->client->getCookieJar()->clear();

        $this->createAccount(['email' => 'autre@exemple.test']);
        $this->company(['siret' => FakeCompanyRegistry::ACTIVE, 'action' => 'verify']);

        self::assertStringContainsString('Cette entreprise est déjà inscrite', (string) $this->client->getResponse()->getContent());
    }

    public function testJobsAreRequiredAndLimited(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $connection->executeStatement("INSERT INTO admin.setting (key, value, type, updated_at) VALUES ('pro.max_jobs', '1', 'int', now()) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value");
        $this->createAccount();
        $this->company(['siret' => FakeCompanyRegistry::ACTIVE, 'vat_number' => 'FR68900000001', 'action' => 'continue']);

        $crawler = $this->client->request('GET', '/inscription/pro/metiers');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $ids = $crawler->filter('input[name="jobs[]"]')->each(static fn ($n): string => (string) $n->attr('value'));

        $this->client->request('POST', '/inscription/pro/metiers', ['_token' => $token]);
        self::assertSelectorTextContains('.tm-form-alert', 'Choisissez au moins un métier.');

        $this->client->request('POST', '/inscription/pro/metiers', ['_token' => $token, 'jobs' => $ids, 'main' => $ids[0]]);
        self::assertSelectorTextContains('.tm-form-alert', '1 métiers au maximum');

        $this->client->request('POST', '/inscription/pro/metiers', ['_token' => $token, 'jobs' => ['0190f5e4-0000-7000-8000-00000000dead'], 'main' => '0190f5e4-0000-7000-8000-00000000dead']);
        self::assertSelectorTextContains('.tm-form-alert', 'n’est plus proposé');
    }

    public function testAccountRulesAndAccess(): void
    {
        $this->client->request('GET', '/inscription/pro/entreprise');
        self::assertResponseRedirects('/connexion');

        $this->createAccount(['mobile' => '01 23 45 67 89']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Saisissez un numéro de mobile (06 ou 07).', (string) $this->client->getResponse()->getContent());

        $this->createAccount(['terms' => null]);
        self::assertStringContainsString('Vous devez accepter les CGU et les CGV professionnelles.', (string) $this->client->getResponse()->getContent());
    }

    public function testExistingEmailIsNeverSignedIn(): void
    {
        $this->createAccount();
        $this->client->getCookieJar()->clear();

        $this->createAccount(['password' => ['first' => 'autre phrase secrète', 'second' => 'autre phrase secrète']]);

        self::assertResponseRedirects('/inscription/verifiez-vos-emails', 303);
        $this->client->request('GET', '/inscription/pro/entreprise');
        self::assertResponseRedirects('/connexion', null, 'aucune session ouverte sur le compte existant');
        self::assertSame('1', $this->rows('SELECT count(*) FROM identity.user_account'));
    }
}
