<?php

declare(strict_types=1);

namespace App\Tests\Functional\ArtisanProfile;

use App\Administration\Application\Command\ChangeSetting;
use App\Administration\Infrastructure\Settings\DbalSettingsProvider;
use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanVerified;
use App\Search\Application\EventHandler\ProjectArtisanForSearch;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/** PR08 : édition du profil public, modération automatique RG-031, complétude, affichage sur la fiche P10, option téléphone. */
final class ProProfileEditTest extends WebTestCase
{
    use ArtisanDossier;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a0001';

    private KernelBrowser $client;
    private Connection $connection;
    private string $profileId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->profileId = $this->submittedDossier();
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($this->profileId, self::ADMIN));
        $project = self::getContainer()->get(ProjectArtisanForSearch::class);
        \assert($project instanceof ProjectArtisanForSearch);
        $project(new ArtisanVerified($this->profileId, self::DOSSIER_USER, self::ADMIN, new \DateTimeImmutable()));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = '".self::DOSSIER_USER."'");
        $this->setting('moderation.profile_sample_rate', 100);

        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => 'dominique.verif@exemple.test', '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testPresentationIsModeratedThenPublished(): void
    {
        $before = $this->completion();
        $crawler = $this->client->request('GET', '/espace-pro/profil');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Lister vos prestations', $crawler->filter('.tm-pro-profile__gauge')->text());

        $form = ['_token' => $this->token($crawler, 'presentation'), 'description' => "Plombier depuis 2009.\nAppelez le 06 12 34 56 78 !", 'strengths' => ['Devis sous 48 h'], 'languages' => ['fr', 'en'], 'years' => '15', 'clients' => ['particuliers']];
        $crawler = $this->client->request('POST', '/espace-pro/profil/presentation', $form);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('ne peut pas contenir un numéro de téléphone', $crawler->filter('#presentation .tm-field__error')->text());
        self::assertStringContainsString('Appelez le 06 12 34 56 78', (string) $crawler->filter('#description')->text(), 'saisie réaffichée');

        $form['description'] = "Plombier depuis **2009**.\n\n- Dépannage\n- Salles de bains";
        $this->client->request('POST', '/espace-pro/profil/presentation', $form);
        self::assertResponseRedirects('/espace-pro/profil#presentation', 303);
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM artisan.profile_review WHERE section = 'presentation'")), 'échantillon de relecture humaine (RG-031)');

        $crawler = $this->client->request('GET', '/pro/atelier-fictif-caen-'.$this->profileId);
        self::assertSame('<p>Plombier depuis <strong>2009</strong>.</p>'."\n".'<ul><li>Dépannage</li><li>Salles de bains</li></ul>', trim($crawler->filter('.tm-profile__rich')->html()), 'mise en forme limitée, aucune balise saisie interprétée');
        self::assertStringContainsString('Anglais', $crawler->filter('.tm-profile__facts-list')->text());
        self::assertStringContainsString('Devis sous 48 h', $crawler->filter('.tm-profile__strengths')->text());
        self::assertSame($before, $this->completion(), 'description déjà comptée (description courte de l’inscription)');
    }

    public function testServicesHoursAndPhoneOption(): void
    {
        $before = $this->completion();
        $crawler = $this->client->request('GET', '/espace-pro/profil');
        $this->client->request('POST', '/espace-pro/profil/prestations', ['_token' => $this->token($crawler, 'prestations'), 'services' => [['title' => 'Remplacement de chauffe-eau', 'price' => 'à partir de 450 € TTC'], ['title' => '', 'price' => '']]]);
        self::assertResponseRedirects();
        $crawler = $this->client->request('GET', '/espace-pro/profil');
        $this->client->request('POST', '/espace-pro/profil/horaires', ['_token' => $this->token($crawler, 'horaires'), 'days' => ['lundi' => ['08:00-12:00', '14:00-18:00'], 'samedi' => ['09:00-12:00']], 'emergencies' => '1', 'emergency_note' => '7 j / 7 pour les fuites', 'phone_public' => '1']);
        self::assertResponseRedirects();
        self::assertSame($before + 25, $this->completion(), 'prestations (15 %) et horaires (10 %)');

        $crawler = $this->client->request('GET', '/pro/atelier-fictif-caen-'.$this->profileId);
        self::assertStringContainsString('à partir de 450 € TTC', $crawler->filter('.tm-profile__services')->text());
        self::assertStringContainsString('08:00 – 12:00, 14:00 – 18:00', $crawler->filter('.tm-profile__hours')->text());
        self::assertStringContainsString('Fermé', $crawler->filter('.tm-profile__hours')->text());
        self::assertCount(1, $crawler->filter('form[action$="/telephone"]'), 'option téléphone activée (RG-030)');

        $crawler = $this->client->request('GET', '/espace-pro/profil');
        $crawler = $this->client->request('POST', '/espace-pro/profil/horaires', ['_token' => $this->token($crawler, 'horaires'), 'days' => ['lundi' => ['18:00-08:00']]]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('plage « 18:00-08:00 » invalide', $crawler->filter('#horaires .tm-field__error')->text());
    }

    private function token(\Symfony\Component\DomCrawler\Crawler $crawler, string $section): string
    {
        return (string) $crawler->filter('form[action="/espace-pro/profil/'.$section.'"] input[name="_token"]')->attr('value');
    }

    /** Jauge PR08.1 calculée en direct (la colonne completion_rate suit l'événement, traité en asynchrone). */
    private function completion(): int
    {
        $text = $this->client->request('GET', '/espace-pro/profil')->filter('#gauge-title')->text();
        self::assertSame(1, preg_match('/(\d+) %/', $text, $m));

        return (int) $m[1];
    }

    private function setting(string $key, mixed $value): void
    {
        $this->dossierDispatch(new ChangeSetting($key, $value, self::ADMIN, 'Test PR08'));
        $cache = self::getContainer()->get('cache.settings');
        $settings = self::getContainer()->get(DbalSettingsProvider::class);
        \assert($cache instanceof CacheInterface && $settings instanceof DbalSettingsProvider);
        $cache->delete(DbalSettingsProvider::CACHE_KEY);
        $settings->reset();
    }
}
