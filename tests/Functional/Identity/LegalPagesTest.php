<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\Command\RegisterUser;
use App\Identity\Application\EventHandler\NotifyLegalUpdate;
use App\Identity\Application\Legal\PendingLegalAcceptances;
use App\Identity\Application\Legal\PublishLegalDocument;
use App\Identity\Domain\Legal\Event\LegalDocumentPublished;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/** P17 : adresses, sommaire, versions, registre des traceurs ; RG-002 : ré-acceptation bloquante ; E-95. */
final class LegalPagesTest extends WebTestCase
{
    private const string USER = '0190f5e4-0000-7000-8000-0000000c0001';
    private const string HTML = '<p>Texte fictif de test.</p><h2>Objet</h2><p>Objet fictif.</p><h2>Objet</h2><p>Second titre identique.</p>';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    public function testPagesTocVersionsAndCookieRegistry(): void
    {
        $this->publish('privacy', false);
        $this->publish('privacy', false);
        $this->publish('cookies', false);

        $this->client->request('GET', '/politique-de-confidentialite');
        self::assertResponseRedirects('/confidentialite', 301);

        $crawler = $this->client->request('GET', '/confidentialite');
        self::assertResponseIsSuccessful();
        self::assertSame(['#objet', '#objet-2'], $crawler->filter('.tm-legal__toc a')->each(static fn ($a): string => (string) $a->attr('href')), 'identifiants uniques');
        self::assertCount(1, $crawler->filter('h2#objet-2'));
        self::assertStringContainsString('Version 2 en vigueur', $crawler->filter('.tm-caption')->first()->text());

        $crawler = $this->client->request('GET', '/confidentialite/versions');
        self::assertCount(2, $crawler->filter('.tm-legal__versions li'));
        $this->client->request('GET', '/confidentialite/versions/1');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, follow');
        self::assertSelectorTextContains('.tm-legal__archived', 'Version archivée');
        $this->client->request('GET', '/confidentialite/versions/9');
        self::assertResponseStatusCodeSame(404);

        $crawler = $this->client->request('GET', '/cookies');
        self::assertStringContainsString('tm_sess', $crawler->filter('.tm-legal__table')->text());
        self::assertCount(1, $crawler->filter('.tm-legal__content button[data-cookie-consent-open]'));

        $this->client->request('GET', '/mentions-legales');
        self::assertResponseStatusCodeSame(404, 'aucune version publiée');
    }

    public function testSubstantialTermsMustBeAcceptedAgainAndAreAnnounced(): void
    {
        $bus = self::getContainer()->get(CommandBus::class);
        \assert($bus instanceof CommandBus);
        $bus->dispatch(new RegisterUser(self::USER, 'dominique@exemple.test', 'cheval agrafe batterie', 'client', 'Dominique', 'Exemple'));
        $this->sql("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = '".self::USER."'");
        $this->publish('cgu', false);
        $queries = self::getContainer()->get(QueryBus::class);
        \assert($queries instanceof QueryBus);
        self::assertSame([], $queries->ask(new PendingLegalAcceptances(self::USER)), 'version non substantielle : aucune ré-acceptation');

        $id = $this->publish('cgu', true);
        // Connexion par le formulaire (appareil enregistré) : la session survit aux requêtes suivantes.
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => 'dominique@exemple.test', '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
        self::assertResponseRedirects('/espace-client', 303);
        $this->client->request('GET', '/recherche');
        self::assertResponseRedirects('/conditions/mise-a-jour');
        $this->client->request('GET', '/cgu');
        self::assertResponseIsSuccessful('les pages légales restent consultables');
        $crawler = $this->client->request('GET', '/conditions/mise-a-jour');
        self::assertSelectorTextContains('.tm-legal__versions', 'version 2');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/conditions/mise-a-jour', ['_token' => $token]);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/conditions/mise-a-jour', ['_token' => $token, 'accept' => '1']);
        self::assertResponseRedirects('/recherche', 303);
        $this->client->request('GET', '/recherche');
        self::assertResponseIsSuccessful();
        self::assertSame(1, DbalValue::int($this->value("SELECT count(*) FROM identity.legal_acceptance WHERE user_id = '".self::USER."'")));

        $notify = self::getContainer()->get(NotifyLegalUpdate::class);
        \assert($notify instanceof NotifyLegalUpdate);
        $notify(new LegalDocumentPublished($id, 'cgu', 2, true, new \DateTimeImmutable()));
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('E-95', $mail->getHeaders()->getHeaderBody('X-TrouveMoi-Template'));
        self::assertStringContainsString('votre acceptation vous sera demandée', (string) $mail->getTextBody());
    }

    private function publish(string $type, bool $substantial): string
    {
        $bus = self::getContainer()->get(CommandBus::class);
        \assert($bus instanceof CommandBus);
        $id = \sprintf('0190f5e4-0000-7000-8000-%012d', random_int(1, 999_999_999));
        $bus->dispatch(new PublishLegalDocument($id, $type, 'Document fictif '.$type, self::HTML, $substantial));

        return $id;
    }

    private function sql(string $statement): void
    {
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $connection->executeStatement($statement);
    }

    private function value(string $query): mixed
    {
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return $connection->fetchOne($query);
    }
}
