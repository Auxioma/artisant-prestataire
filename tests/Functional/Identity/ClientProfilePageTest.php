<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\Command\RegisterUser;
use App\Notification\Application\Notification;
use App\Notification\Application\Notifier;
use App\Shared\Application\Storage\ScanQuarantinedFile;
use App\Shared\Domain\Storage\FileQuarantined;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;

/** T-705c1 — C07 « Mon profil » : affichage, enregistrement, refus motivé, préférences e-mail respectées, type de compte. */
final class ClientProfilePageTest extends WebTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f7701';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.c07@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Fictive'));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :c", ['c' => self::CLIENT]);
        $connection->executeStatement("INSERT INTO identity.client_profile (user_id, type, postal_code, created_at, updated_at) VALUES (:c, 'particulier', '76600', date_trunc('second', now()), date_trunc('second', now()))", ['c' => self::CLIENT]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => 'camille.c07@exemple.test', '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    /** @param array<string, string|bool|list<string>> $values */
    private function save(Crawler $page, array $values): Crawler
    {
        $form = $page->selectButton('Enregistrer les modifications')->form();
        foreach ($values as $name => $value) {
            if (\is_array($value)) {
                $boxes = $form->get($name);
                \assert(\is_array($boxes));
                foreach ($boxes as $box) {
                    \assert($box instanceof ChoiceFormField);
                    $box->tick();
                    if (!\in_array($box->getValue(), $value, true)) {
                        $box->untick();
                    }
                }
            } elseif (\is_bool($value)) {
                $box = $form->get($name);
                \assert($box instanceof ChoiceFormField);
                $value ? $box->tick() : $box->untick();
            } else {
                $form[$name] = $value;
            }
        }

        return $this->client->submit($form);
    }

    public function testShowSaveRejectAndRespectPreferences(): void
    {
        $page = $this->client->request('GET', '/espace-client/profil');
        self::assertResponseIsSuccessful();
        self::assertSame('Camille Fictive', trim($page->filter('.tm-account__name > span')->text()));
        self::assertStringContainsString('Compte vérifié', $page->filter('.tm-account__identity')->text());
        self::assertSame('Profil complété à 0 %', $page->filter('.tm-account__ring')->attr('aria-label'));
        self::assertCount(1, $page->filter('input[name="notifications[nouveaux_devis]"][checked]'));

        // Refus motivé : saisie conservée, rien d'enregistré.
        $page = $this->save($page, ['firstName' => 'Camille', 'phone' => '12', 'postalCode' => '76600']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Numéro de téléphone invalide.', $page->filter('#phone_error')->text());
        self::assertSame('12', $page->filter('#phone')->attr('value'));

        $this->save($page, ['firstName' => 'Camilla', 'lastName' => 'Exemple', 'phone' => '06 12 34 56 78', 'postalCode' => '76000', 'searchRadiusKm' => '25', 'interests' => ['jardin', 'evenementiel'], 'notifications[nouveaux_devis]' => false, 'newsletter' => true]);
        self::assertResponseRedirects('/espace-client/profil');
        $page = $this->client->followRedirect();
        self::assertSame('Camilla Exemple', trim($page->filter('.tm-account__name > span')->text()));
        self::assertStringContainsString('06 12 34 56 78', $page->filter('.tm-account__contact')->text());
        self::assertSame('Profil complété à 100 %', $page->filter('.tm-account__ring')->attr('aria-label'));
        self::assertSame('25', $page->filter('#searchRadiusKm')->attr('value'));
        self::assertCount(2, $page->filter('input[name="interests[]"][checked]'));
        self::assertCount(0, $page->filter('input[name="notifications[nouveaux_devis]"][checked]'));
        self::assertSame('+33612345678', DbalValue::string($this->connection->fetchOne('SELECT phone FROM identity.user_account WHERE id = :c', ['c' => self::CLIENT])));
        self::assertSame('76000|25|["evenementiel", "jardin"]|true', DbalValue::string($this->connection->fetchOne("SELECT postal_code || '|' || search_radius_km || '|' || interests::text || '|' || (newsletter_opt_in_at IS NOT NULL) FROM identity.client_profile WHERE user_id = :c", ['c' => self::CLIENT])));

        // Rayon hors limites refusé.
        $this->save($page, ['searchRadiusKm' => '900']);
        self::assertResponseStatusCodeSame(422);

        // « Nouveaux devis » coupé : E-12 n'est pas envoyé par e-mail (journalisé « ignoré »), reste dans le centre.
        $notifier = self::getContainer()->get(Notifier::class);
        \assert($notifier instanceof Notifier);
        $notifier->notify(new Notification('E-12', 'camille.c07@exemple.test', self::CLIENT, ['prenom' => 'Camilla', 'artisan' => 'Atelier Fictif', 'montant' => '100 €', 'lien' => '/espace-client/demandes']));
        self::assertSame('skipped', DbalValue::string($this->connection->fetchOne("SELECT status FROM notification.delivery WHERE code = 'E-12' AND channel = 'email'")));
    }

    public function testSwitchAccountType(): void
    {
        $page = $this->client->request('GET', '/espace-client/profil');
        $form = $page->filter('.tm-account__switch-form')->form(['companyName' => 'Société Fictive']);
        $this->client->submit($form);
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Client professionnel', $page->filter('.tm-account__type')->text());
        self::assertStringContainsString('Société Fictive', $page->filter('.tm-account__type')->text());
        $this->client->submit($page->selectButton('Passer en compte Particulier')->form());
        self::assertSame('particulier', DbalValue::string($this->connection->fetchOne('SELECT type FROM identity.client_profile WHERE user_id = :c', ['c' => self::CLIENT])));
        $this->client->request('POST', '/espace-client/profil/type', ['_token' => 'faux', 'type' => 'professionnel']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAvatarShownOnceScannedThenRemoved(): void
    {
        $page = $this->client->request('GET', '/espace-client/profil');
        $form = $page->filter('.tm-account__photo form')->form();
        $field = $form['avatar'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload(StorageSamples::file(StorageSamples::png()));
        $this->client->submit($form);
        $page = $this->client->followRedirect();
        self::assertCount(1, $page->filter('.tm-account__initials'), 'photo en analyse : initiales');
        $file = DbalValue::string($this->connection->fetchOne('SELECT avatar_file_id FROM identity.client_profile WHERE user_id = :c', ['c' => self::CLIENT]));
        $scan = self::getContainer()->get(ScanQuarantinedFile::class);
        \assert($scan instanceof ScanQuarantinedFile);
        $scan(new FileQuarantined($file, 'client_avatar', new \DateTimeImmutable()));

        $page = $this->client->request('GET', '/espace-client/profil');
        self::assertCount(1, $page->filter('.tm-account__photo img'));
        $this->client->request('GET', '/espace-client/profil/photo');
        self::assertResponseIsSuccessful();
        $this->client->submit($page->selectButton('Retirer la photo')->form());
        self::assertNull($this->connection->fetchOne('SELECT avatar_file_id FROM identity.client_profile WHERE user_id = :c', ['c' => self::CLIENT]));
        $this->client->request('GET', '/espace-client/profil/photo');
        self::assertResponseStatusCodeSame(404);
    }
}
