<?php

declare(strict_types=1);

namespace App\Tests\Functional\Administration;

use App\Identity\Application\Command\RegisterUser;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * T-807 — connexion assistée (ADR-0013) : super-admin seulement, motif, ticket à usage unique, bandeau, aucune trace de
 * connexion ordinaire, actions sensibles bloquées, fin journalisée et retour au back-office toujours connecté.
 */
final class ImpersonationTest extends WebTestCase
{
    use AdminSession;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f8071';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        $bus = self::getContainer()->get(CommandBus::class);
        \assert($connection instanceof Connection && $bus instanceof CommandBus);
        $this->connection = $connection;
        $bus->dispatch(new RegisterUser(self::CLIENT, 'camille.807@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Fictive'));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :id", ['id' => self::CLIENT]);
    }

    public function testSuperAdminActsAsTheClientWithinLimits(): void
    {
        $this->adminSession($this->client, '0190f5e4-0000-7000-8000-0000000a8071', 'super_admin');
        $page = $this->client->request('GET', '/admin/clients?client='.self::CLIENT);
        $form = $page->filter('form.tm-impersonate')->form();

        $form['motif'] = 'ok';
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/clients?client='.self::CLIENT, 303, 'motif trop court');

        $page = $this->client->request('GET', '/admin/clients?client='.self::CLIENT);
        $form = $page->filter('form.tm-impersonate')->form();
        $form['motif'] = 'Reproduire le problème signalé (fictif)';
        $this->client->submit($form);
        $enter = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/connexion-assistee/[A-Za-z0-9_-]{43}$#', $enter);
        $this->client->followRedirect();
        self::assertResponseRedirects('/espace-client');
        $page = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Connexion assistée', $page->filter('.tm-impersonation')->text());
        self::assertSame(0, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM identity.session_device WHERE user_id = :id', ['id' => self::CLIENT])), 'aucun appareil enregistré');
        self::assertNull($this->connection->fetchOne('SELECT last_login_at FROM identity.user_account WHERE id = :id', ['id' => self::CLIENT]), 'dernière connexion inchangée');
        $started = $this->connection->fetchAssociative("SELECT subject_id, context FROM admin.audit_log WHERE action = 'admin.impersonation_started'");
        self::assertIsArray($started);
        self::assertSame(self::CLIENT, $started['subject_id']);

        // Actions sensibles bloquées.
        $this->client->request('POST', '/espace-client/securite/mot-de-passe', [], [], ['HTTP_REFERER' => 'http://localhost/espace-client']);
        self::assertResponseRedirects('http://localhost/espace-client');
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Action bloquée pendant une connexion assistée', $page->filter('.tm-toasts')->text());

        // Le ticket ne sert qu'une fois.
        $this->client->request('GET', $enter);
        self::assertFalse($this->client->getResponse()->isSuccessful() && str_contains((string) $this->client->getResponse()->getContent(), 'Connexion assistée : '), 'ticket consommé');

        // Fin : session du site fermée, retour au back-office toujours connecté.
        $page = $this->client->request('GET', '/espace-client');
        $this->client->submit($page->filter('.tm-impersonation form')->form());
        self::assertResponseRedirects('http://localhost/admin/clients?client='.self::CLIENT);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/espace-client');
        self::assertResponseRedirects();
        self::assertStringContainsString('/connexion', (string) $this->client->getResponse()->headers->get('Location'), 'plus connecté côté site');
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE action = 'admin.impersonation_ended' AND subject_id = :id", ['id' => self::CLIENT])));
    }

    public function testOnlyTheSuperAdminCanStartIt(): void
    {
        $this->adminSession($this->client, '0190f5e4-0000-7000-8000-0000000a8072', 'support');
        $page = $this->client->request('GET', '/admin/clients?client='.self::CLIENT);
        self::assertCount(0, $page->filter('form.tm-impersonate'));
        $this->client->request('POST', '/admin/connexion-assistee/'.self::CLIENT, ['motif' => 'Tentative (fictif)']);
        self::assertResponseStatusCodeSame(403);
    }
}
