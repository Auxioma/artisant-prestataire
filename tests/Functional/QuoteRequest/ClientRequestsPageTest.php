<?php

declare(strict_types=1);

namespace App\Tests\Functional\QuoteRequest;

use App\Identity\Application\Command\RegisterUser;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** C02 (Figma 159:3905) : liste des demandes du client, groupes, synthèse, Clôturer (motif), Relancer, Dupliquer. */
final class ClientRequestsPageTest extends WebTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000ec001';
    private const string OTHER = '0190f5e4-0000-7000-8000-0000000ec002';
    private const string OPEN = '0190f5e4-0000-7000-8000-0000000ec101';
    private const string EXPIRED = '0190f5e4-0000-7000-8000-0000000ec102';
    private const string FOREIGN = '0190f5e4-0000-7000-8000-0000000ec103';

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
        foreach ([[self::CLIENT, 'camille.liste@exemple.test'], [self::OTHER, 'autre.client@exemple.test']] as [$id, $email]) {
            $this->dossierDispatch(new RegisterUser($id, $email, 'cheval agrafe batterie', 'client', 'Camille', 'Liste'));
            $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :id", ['id' => $id]);
        }
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new SubmitQuoteRequest(self::OPEN, self::CLIENT, $job, '76351', '76600', '1 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', ['etage' => '2'], '500_1000', 'week', null, title: 'Fuite sous l’évier'));
        $this->dossierDispatch(new SubmitQuoteRequest(self::EXPIRED, self::CLIENT, $job, '76351', '76600', '2 rue Fictive', 'installation', 'Pose d’un nouveau chauffe-eau dans le garage (fictif).', [], null, 'month', null, title: 'Chauffe-eau'));
        $this->dossierDispatch(new SubmitQuoteRequest(self::FOREIGN, self::OTHER, $job, '76351', '76600', '3 rue Fictive', 'repair', 'Robinet de la cuisine qui goutte en permanence (fictif).', [], null, 'week', null));
        $connection->executeStatement("UPDATE request.quote_request SET status = 'expired', status_reason = 'expired', expires_at = date_trunc('second', now()) - interval '1 day' WHERE id = :id", ['id' => self::EXPIRED]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => 'camille.liste@exemple.test', '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    public function testListGroupsAndSummary(): void
    {
        $crawler = $this->client->request('GET', '/espace-client/demandes');
        self::assertResponseIsSuccessful();
        self::assertSame('Mes demandes (2)', $crawler->filter('h1')->text(), 'jamais les demandes d’un autre client');
        self::assertCount(2, $crawler->filter('.tm-crequest'));
        $open = $crawler->filter('.tm-crequest')->reduce(static fn ($c): bool => str_contains($c->text(), 'Fuite sous l’évier'));
        self::assertStringContainsString('En attente de réponse', $open->text());
        self::assertStringContainsString('0/3 devis', $open->text());
        $expired = $crawler->filter('.tm-crequest')->reduce(static fn ($c): bool => str_contains($c->text(), 'Chauffe-eau'));
        self::assertStringContainsString('Expirée', $expired->text());
        self::assertCount(1, $expired->filter('form[action$="/relancer"]'), 'relance unique proposée');
        self::assertSame('1', trim($crawler->filter('.tm-cspace__menu .tm-cspace__link.is-active .tm-cspace__count')->text()), 'demandes actives');
        self::assertSame(['2', '1', '0', '0'], $crawler->filter('.tm-cfigures strong')->each(static fn ($n): string => $n->text()));

        self::assertCount(1, $this->client->request('GET', '/espace-client/demandes?statut=closes')->filter('.tm-crequest'));
        self::assertStringContainsString('Aucune demande ne correspond', $this->client->request('GET', '/espace-client/demandes?statut=terminees')->text());
        self::assertCount(1, $this->client->request('GET', '/espace-client/demandes?q=chauffe')->filter('.tm-crequest'));
    }

    public function testCloseRelaunchAndDuplicate(): void
    {
        $crawler = $this->client->request('GET', '/espace-client/demandes');
        $token = (string) $crawler->filter('.tm-crequest__close input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/espace-client/demandes/'.self::OPEN.'/cloturer', ['_token' => $token, 'reason' => '']);
        self::assertStringContainsString('Indiquez pourquoi', $this->client->followRedirect()->filter('.tm-toasts')->text());
        $this->client->request('POST', '/espace-client/demandes/'.self::OPEN.'/cloturer', ['_token' => $token, 'reason' => 'postponed']);
        self::assertStringContainsString('Demande clôturée', $this->client->followRedirect()->filter('.tm-toasts')->text());
        self::assertSame(['cancelled', 'client_postponed'], array_values((array) $this->connection->fetchAssociative('SELECT status, status_reason FROM request.quote_request WHERE id = :id', ['id' => self::OPEN])));

        $this->client->request('POST', '/espace-client/demandes/'.self::EXPIRED.'/relancer', ['_token' => $token]);
        self::assertStringContainsString('Demande relancée', $this->client->followRedirect()->filter('.tm-toasts')->text());
        self::assertSame(['open', 1], array_values((array) $this->connection->fetchAssociative('SELECT status, relaunch_count FROM request.quote_request WHERE id = :id', ['id' => self::EXPIRED])));

        $this->client->request('POST', '/espace-client/demandes/'.self::FOREIGN.'/cloturer', ['_token' => $token, 'reason' => 'postponed']);
        self::assertStringContainsString('Demande introuvable', $this->client->followRedirect()->filter('.tm-toasts')->text());
        self::assertSame('open', $this->dossierValue("SELECT status FROM request.quote_request WHERE id = '".self::FOREIGN."'"));

        $this->client->request('POST', '/espace-client/demandes/'.self::OPEN.'/dupliquer', ['_token' => $token]);
        self::assertResponseRedirects('/demande-de-devis/6');
        $draft = $this->connection->fetchAssociative("SELECT step, payload->>'title' AS title, payload->>'description' AS description FROM request.request_draft WHERE owner_user_id = :u", ['u' => self::CLIENT]);
        self::assertSame(6, DbalValue::int(($draft ?: [])['step'] ?? 0));
        self::assertSame('Fuite sous l’évier', ($draft ?: [])['title'] ?? null);
        $crawler = $this->client->request('GET', '/espace-client/demandes?statut=brouillons');
        self::assertStringContainsString('Brouillon', $crawler->filter('.tm-crequest.is-draft')->text());
    }

    public function testEditBeforeAnyQuoteThenAddendumOnly(): void
    {
        $crawler = $this->client->request('GET', '/espace-client/demandes/'.self::OPEN.'/modifier');
        self::assertResponseIsSuccessful();
        self::assertSame('Modifier ma demande', $crawler->filter('h1')->text());
        $form = $crawler->filter('input[name="form"][value="edit"]')->closest('form')?->form();
        self::assertNotNull($form);

        $this->client->submit($form, ['description' => 'Appelez-moi au 06 12 34 56 78 pour la fuite (fictif).']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('n’indiquez pas vos coordonnées', $this->client->getCrawler()->filter('[role="alert"]')->text());

        $this->client->submit($form, ['description' => 'Fuite sous l’évier et joint du siphon à changer, accès facile (fictif).', 'timing' => 'urgent', 'title' => 'Fuite et siphon']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Demande modifiée', $crawler->filter('.tm-toasts')->text());
        self::assertSame(['urgent', 'Fuite et siphon'], array_values((array) $this->connection->fetchAssociative('SELECT timing, title FROM request.quote_request WHERE id = :id', ['id' => self::OPEN])));

        // Un devis reçu : la demande ne se modifie plus, seul un complément s'ajoute (RG-066).
        $this->connection->executeStatement('UPDATE request.quote_request SET received_quotes_count = 1, active_quotes_count = 1 WHERE id = :id', ['id' => self::OPEN]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $crawler = $this->client->request('GET', '/espace-client/demandes/'.self::OPEN.'/modifier');
        self::assertSame('Compléter ma demande', $crawler->filter('h1')->text());
        self::assertCount(0, $crawler->filter('input[name="form"][value="edit"]'));
        $form = $crawler->filter('input[name="form"][value="addendum"]')->closest('form')?->form();
        self::assertNotNull($form);
        $this->client->submit($form, ['body' => 'Le robinet d’arrêt est sous l’évier, à gauche (fictif).']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Complément ajouté', $crawler->filter('.tm-toasts')->text());
        self::assertStringContainsString('Le robinet d’arrêt est sous l’évier', $crawler->filter('.tm-cedit__addenda')->text());
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE action = 'request.addendum_added'")));

        $this->client->request('GET', '/espace-client/demandes/'.self::FOREIGN.'/modifier');
        self::assertResponseStatusCodeSame(404, 'demande d’un autre client');
    }

    public function testVisitorIsSentToLogin(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/espace-client/demandes');
        self::assertResponseRedirects();
    }
}
