<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Catalog\Application\Geo\GeoReferentialImporter;
use App\Catalog\Application\Geo\Port\GeoReferentialSourceFactory;
use App\Identity\Application\Legal\PublishLegalDocument;
use App\Identity\Domain\User\Event\RegistrationAttemptedOnExistingAccount;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\Fake\AcceptingHumanVerification;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** A01 / A02 de bout en bout : affichage, validation serveur, anti-énumération, captcha, preuves légales. */
final class ClientRegistrationTest extends WebTestCase
{
    private const string URL = '/inscription/client';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        AcceptingHumanVerification::$down = false;
        $this->client = self::createClient();
        $this->client->disableReboot(); // une seule transaction DAMA pour la préparation et les requêtes
    }

    protected function tearDown(): void
    {
        AcceptingHumanVerification::$down = false;
        parent::tearDown();
    }

    private function prepare(bool $legal = true): void
    {
        $c = self::getContainer();
        $importer = $c->get(GeoReferentialImporter::class);
        $sources = $c->get(GeoReferentialSourceFactory::class);
        \assert($importer instanceof GeoReferentialImporter && $sources instanceof GeoReferentialSourceFactory);
        $importer->import($sources->file(__DIR__.'/../../fixtures/geo/normandie.json'), false);
        if ($legal) {
            $commands = $c->get(CommandBus::class);
            \assert($commands instanceof CommandBus);
            $commands->dispatch(new PublishLegalDocument('0190f5e4-0000-7000-8000-000000000601', 'cgu', 'CGU', '<p>CGU fictives de test</p>', false));
            $commands->dispatch(new PublishLegalDocument('0190f5e4-0000-7000-8000-000000000602', 'privacy', 'Confidentialité', '<p>Politique fictive de test</p>', false));
        }
    }

    /**
     * @param array<string, mixed> $override
     */
    private function submit(array $override = [], string $captcha = AcceptingHumanVerification::HUMAN, ?\Closure $beforePost = null): void
    {
        $crawler = $this->client->request('GET', self::URL);
        if (null !== $beforePost) {
            $beforePost();
        }
        $fields = [
            'clientType' => 'particulier',
            'lastName' => 'Exemple',
            'firstName' => 'Camille',
            'email' => 'camille.exemple@exemple.test',
            'phone' => '06 12 34 56 78',
            'postalCode' => '76600',
            'password' => ['first' => 'cheval agrafe batterie', 'second' => 'cheval agrafe batterie'],
            'terms' => '1',
            'newsletter' => '1',
            'legalVersions' => $crawler->filter('#client_registration_legalVersions')->attr('value'),
            '_token' => $crawler->filter('#client_registration__token')->attr('value'),
        ];
        $this->client->request('POST', self::URL, ['client_registration' => array_filter($override + $fields, static fn ($v): bool => null !== $v), 'cf-turnstile-response' => $captcha]);
    }

    private function rows(string $sql): int
    {
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return DbalValue::int($connection->fetchOne($sql));
    }

    public function testAccountChoicePage(): void
    {
        $crawler = $this->client->request('GET', '/inscription');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tm-choice-card', 'Je cherche un professionnel');
        self::assertSame('/inscription/client', $crawler->selectLink('Continuer comme client')->attr('href'));
        self::assertSame('/inscription/pro', $crawler->selectLink('Créer mon compte pro')->attr('href'));
    }

    public function testRegistrationIsUnavailableWithoutPublishedTerms(): void
    {
        $this->prepare(legal: false);
        $this->client->request('GET', self::URL);

        self::assertResponseStatusCodeSame(503);
    }

    public function testSuccessfulRegistrationStoresAccountProfileAndLegalProof(): void
    {
        $this->prepare();
        $this->submit();

        self::assertResponseRedirects('/inscription/verifiez-vos-emails', 303);
        self::assertSame(1, $this->rows("SELECT count(*) FROM identity.user_account WHERE status = 'pending_email' AND kind = 'client' AND phone = '+33612345678'"));
        self::assertSame(1, $this->rows("SELECT count(*) FROM identity.client_profile WHERE type = 'particulier' AND postal_code = '76600' AND newsletter_opt_in_at IS NOT NULL"));
        self::assertSame(2, $this->rows('SELECT count(*) FROM identity.legal_acceptance a JOIN identity.legal_document d ON d.id = a.legal_document_id WHERE a.content_hash = d.content_hash AND a.ip_hash IS NOT NULL'));

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Vérifiez votre boîte mail');
        self::assertSelectorTextContains('.tm-auth-message', 'camille.exemple@exemple.test');
    }

    public function testExistingEmailGetsTheSameAnswerWithoutRevealingIt(): void
    {
        $this->prepare();
        $this->submit();
        $this->submit(['email' => 'CAMILLE.EXEMPLE@exemple.test', 'firstName' => 'Autre']);

        self::assertResponseRedirects('/inscription/verifiez-vos-emails', 303);
        self::assertSame(1, $this->rows('SELECT count(*) FROM identity.user_account'));
        self::assertSame(1, $this->rows("SELECT count(*) FROM shared.outbox_message WHERE message_type LIKE '%\\".new \ReflectionClass(RegistrationAttemptedOnExistingAccount::class)->getShortName()."'"));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidSubmissions(): iterable
    {
        yield 'e-mail jetable' => [['email' => 'camille@yopmail.com'], 'Les adresses temporaires ne sont pas acceptées.'];
        yield 'e-mail invalide' => [['email' => 'camille@'], 'Adresse email invalide.'];
        yield 'code postal inconnu' => [['postalCode' => '99999'], 'Code postal inconnu.'];
        yield 'prénom' => [['firstName' => 'C'], 'Veuillez saisir votre prénom.'];
        yield 'téléphone' => [['phone' => '12'], 'Numéro de téléphone invalide.'];
        yield 'mot de passe court' => [['password' => ['first' => 'trop-court', 'second' => 'trop-court']], 'Votre mot de passe doit contenir au moins 12 caractères.'];
        yield 'confirmation différente' => [['password' => ['first' => 'cheval agrafe batterie', 'second' => 'cheval agrafe batteri']], 'Les deux mots de passe ne correspondent pas.'];
        yield 'CGU non acceptées' => [['terms' => null], 'Vous devez accepter les conditions générales d’utilisation.'];
        yield 'entreprise sans raison sociale' => [['clientType' => 'professionnel'], 'Veuillez saisir la raison sociale.'];
        yield 'SIRET invalide' => [['clientType' => 'professionnel', 'companyName' => 'Exemple SARL', 'siret' => '12345678901234'], 'Numéro SIRET invalide (14 chiffres).'];
        yield 'conditions mises à jour entre-temps' => [['legalVersions' => '0190f5e4-0000-7000-8000-000000000999'], 'Nos conditions viennent d’être mises à jour'];
        yield 'jeton CSRF' => [['_token' => 'falsifie'], 'CSRF'];
    }

    /** @param array<string, mixed> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSubmissions')]
    public function testInvalidSubmissionsAreRejectedWithTheirMessage(array $override, string $message): void
    {
        $this->prepare();
        $this->submit($override);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(htmlspecialchars($message, \ENT_QUOTES), (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->rows('SELECT count(*) FROM identity.user_account'));
    }

    public function testBusinessClientWithSiretIsAcceptedUnverifiedWhenSireneIsUnavailable(): void
    {
        $this->prepare();
        $this->submit(['clientType' => 'professionnel', 'companyName' => 'Plomberie Fictive', 'siret' => \App\Tests\Support\Fake\FakeCompanyRegistry::UNAVAILABLE]);

        self::assertResponseRedirects();
        self::assertSame(1, $this->rows("SELECT count(*) FROM identity.client_profile WHERE type = 'professionnel' AND company_name = 'Plomberie Fictive' AND siret = '90000003500015' AND NOT siret_verified"));
    }

    public function testCaptchaFailureAndOutageBlockRegistration(): void
    {
        $this->prepare();
        $this->submit(captcha: 'jeton-robot');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.tm-signup__fields', 'La vérification anti-robot a échoué');

        AcceptingHumanVerification::$down = true;
        $this->submit();
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.tm-signup__fields', 'momentanément indisponible');
        self::assertSame(0, $this->rows('SELECT count(*) FROM identity.user_account'));
    }

    public function testSignupIsRateLimitedPerIp(): void
    {
        $this->prepare();
        // Pool du limiteur en mémoire, vidé à chaque requête : quota épuisé entre l'affichage et l'envoi.
        $this->submit(beforePost: static function (): void {
            $limiter = self::getContainer()->get('limiter.signup');
            \assert($limiter instanceof RateLimiterFactoryInterface);
            self::assertTrue($limiter->create('127.0.0.1')->consume(5)->isAccepted());
        });

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.tm-signup__fields', 'Trop de tentatives');
        self::assertSame(0, $this->rows('SELECT count(*) FROM identity.user_account'));
    }

    public function testLegalPagesShowTheCurrentVersion(): void
    {
        $this->prepare();
        $this->client->request('GET', '/cgu');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tm-legal', 'Version 1');
        self::assertSelectorTextContains('.tm-legal__content', 'CGU fictives de test');

        $this->client->request('GET', '/cgv');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCheckEmailPageRequiresASubmission(): void
    {
        $this->client->request('GET', '/inscription/verifiez-vos-emails');

        self::assertResponseRedirects('/inscription');
    }
}
