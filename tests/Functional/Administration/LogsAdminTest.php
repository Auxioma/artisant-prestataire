<?php

declare(strict_types=1);

namespace App\Tests\Functional\Administration;

use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** T-806a — BO15.1 : journal d'audit filtré, IP masquée, connexions, webhooks Stripe, export CSV journalisé, droits. */
final class LogsAdminTest extends WebTestCase
{
    use AdminSession;

    private const string FINANCE = '0190f5e4-0000-7000-8000-0000000a8061';

    public function testFinanceAdminReadsFiltersAndExportsTheJournal(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->adminSession($client, self::FINANCE, 'finance');

        $page = $client->request('GET', '/admin/logs');
        self::assertResponseIsSuccessful();
        self::assertSame('Journal d’audit', $page->filter('.tm-admin-tab.is-active')->text());
        $table = $page->filter('.tm-bo15-table')->text();
        self::assertStringContainsString('Connexion au back-office', $table, 'sa propre connexion est journalisée');
        self::assertStringContainsString('•••', $table, 'IP masquée');
        self::assertStringNotContainsString('127.0.0.1', $table);
        self::assertStringContainsString('Bientôt', $page->filter('.tm-admin-tabs')->text(), 'finance et exports à venir');

        $page = $client->request('GET', '/admin/logs?module=billing&periode=7');
        self::assertCount(0, $page->filter('.tm-bo15-table'), 'aucune action billing de cet admin');
        $page = $client->request('GET', '/admin/logs?onglet=connexions&admin=pas-un-id');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Connexions', $page->filter('#bo15-log')->text());

        $connection->executeStatement("INSERT INTO billing.processed_stripe_event (event_id, type, livemode, payload, payload_sha256, status, attempts, received_at, processed_at) VALUES ('evt_bo15_fictif', 'invoice.paid', false, '{}', repeat('a', 64), 'processed', 1, now(), now())");
        $page = $client->request('GET', '/admin/logs?onglet=webhooks');
        self::assertStringContainsString('invoice.paid', $page->filter('.tm-bo15-card')->text());
        self::assertStringContainsString('Traité', $page->filter('.tm-bo15-card')->text());
        $page = $client->request('GET', '/admin/logs?onglet=webhooks&statut=failed');
        self::assertStringContainsString('Aucun événement', $page->filter('.tm-bo15-card')->text());

        $client->request('GET', '/admin/logs/export.csv?onglet=audit&periode=1');
        self::assertResponseIsSuccessful();
        $csv = (string) $client->getInternalResponse()->getContent();
        self::assertStringStartsWith("date;auteur;type_auteur;action;objet;id_objet;ip_masquee;en_impersonation\n", $csv);
        self::assertStringContainsString(';admin.login;', $csv);
        $exported = $connection->fetchAssociative("SELECT actor_id, subject_type, context FROM admin.audit_log WHERE action = 'admin.export' ORDER BY seq DESC LIMIT 1");
        self::assertIsArray($exported, 'l’export est journalisé');
        self::assertSame(self::FINANCE, $exported['actor_id']);
        self::assertSame('audit_log', $exported['subject_type']);
        self::assertEquals(['filters' => 'periode=1', 'rows' => substr_count($csv, "\n") - 1], DbalValue::json($exported['context']));
    }

    public function testModeratorCannotOpenTheJournal(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->adminSession($client, '0190f5e4-0000-7000-8000-0000000a8062', 'moderator');
        $client->request('GET', '/admin/logs');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/admin/logs/export.csv');
        self::assertResponseStatusCodeSame(403);
    }
}
