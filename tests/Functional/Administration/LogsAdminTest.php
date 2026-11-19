<?php

declare(strict_types=1);

namespace App\Tests\Functional\Administration;

use App\Administration\Application\Exports\GenerateScheduledExport;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** T-806a / T-806b / T-806c — BO15.1, BO15.2 et BO15.3 : journal d'audit filtré, IP masquée, connexions, webhooks Stripe, export CSV journalisé, droits. */
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

        $page = $client->request('GET', '/admin/logs?onglet=audit');
        self::assertResponseIsSuccessful();
        self::assertSame('Journal d’audit', $page->filter('.tm-admin-tab.is-active')->text());
        $table = $page->filter('.tm-bo15-table')->text();
        self::assertStringContainsString('Connexion au back-office', $table, 'sa propre connexion est journalisée');
        self::assertStringContainsString('•••', $table, 'IP masquée');
        self::assertStringNotContainsString('127.0.0.1', $table);

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
        $exported = $connection->fetchAssociative("SELECT actor_id, subject_type, subject_id, context FROM admin.audit_log WHERE action = 'admin.export' ORDER BY seq DESC LIMIT 1");
        self::assertIsArray($exported, 'l’export est journalisé');
        self::assertSame(self::FINANCE, $exported['actor_id']);
        self::assertSame(['export', 'journal_audit'], [$exported['subject_type'], $exported['subject_id']]);
        self::assertEquals(['filters' => 'periode=1', 'rows' => substr_count($csv, "\n") - 1], DbalValue::json($exported['context']));
    }

    public function testFinanceTabShowsMonthlyFiguresPlansCohortsAndReconciliation(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $plan = '0190f5e4-0000-7000-8000-0000000b8061';
        $connection->executeStatement("INSERT INTO billing.plan (id, slug, name, visibility, status, position, created_at, updated_at) VALUES (:p, 'formule-bo15-fictive', 'Formule BO15 Fictive', 'public', 'published', 9, now(), now())", ['p' => $plan]);
        $connection->executeStatement("INSERT INTO billing.plan_price (id, plan_id, interval, amount_cents, currency, active, created_at) VALUES ('0190f5e4-0000-7000-8000-0000000b8062', :p, 'month', 2900, 'EUR', true, now())", ['p' => $plan]);
        $connection->executeStatement("INSERT INTO billing.credit_account (artisan_id, user_id, created_at) VALUES ('0190f5e4-0000-7000-8000-0000000c8061', '0190f5e4-0000-7000-8000-0000000d8061', now())");
        $connection->executeStatement("INSERT INTO billing.subscription (id, artisan_id, plan_id, plan_price_id, interval, status, stripe_subscription_id, current_period_start, current_period_end, created_at, updated_at) VALUES ('0190f5e4-0000-7000-8000-0000000e8061', '0190f5e4-0000-7000-8000-0000000c8061', :p, '0190f5e4-0000-7000-8000-0000000b8062', 'month', 'active', 'sub_bo15', now() - interval '3 days', now() + interval '27 days', now(), now())", ['p' => $plan]);
        $connection->executeStatement("INSERT INTO billing.invoice (id, artisan_id, stripe_invoice_id, number, status, currency, amount_ht_cents, vat_cents, amount_ttc_cents, amount_paid_cents, issued_at, paid_at, updated_at) VALUES ('0190f5e4-0000-7000-8000-00000001d861', '0190f5e4-0000-7000-8000-0000000c8061', 'in_bo15', 'TM-BO15-1', 'paid', 'EUR', 2900, 580, 3480, 3480, now() - interval '3 days', now() - interval '3 days', now())");
        $connection->executeStatement("INSERT INTO billing.subscription_period (id, subscription_id, stripe_invoice_id, period_start, period_end, credits, amount_paid_cents, created_at) VALUES ('0190f5e4-0000-7000-8000-00000001e861', '0190f5e4-0000-7000-8000-0000000e8061', 'in_bo15', now() - interval '3 days', now() + interval '27 days', 20, 3480, now())");
        $connection->executeStatement("INSERT INTO billing.reconciliation_run (id, started_at, finished_at, stripe_checked, checked, issues) VALUES ('0190f5e4-0000-7000-8000-00000001f861', now() - interval '1 hour', now() - interval '59 minutes', true, 1204, 0)");
        $this->adminSession($client, self::FINANCE, 'finance');

        $client->request('GET', '/admin/finance');
        self::assertResponseRedirects('/admin/logs?onglet=finance', 301);
        $page = $client->request('GET', '/admin/logs');
        self::assertResponseIsSuccessful();
        self::assertSame('Finance', $page->filter('.tm-admin-tab.is-active')->text(), 'onglet par défaut');
        self::assertCount(6, $page->filter('.tm-bo15-kpis .tm-bo01-kpi'));
        self::assertStringContainsString('29 €', $page->filter('.tm-bo15-kpis')->text(), 'MRR HT');
        self::assertStringContainsString('348 €', $page->filter('.tm-bo15-kpis')->text(), 'ARR');
        self::assertStringContainsString('Formule BO15 Fictive', $page->filter('.tm-bo15-plans')->text());
        self::assertCount(4, $page->filter('.tm-bo15-cohorts tbody tr'));
        self::assertStringContainsString('1 204 éléments vérifiés, 0 écart', $page->filter('.tm-bo15-banner')->text());

        $client->request('GET', '/admin/logs/finance.csv');
        self::assertResponseIsSuccessful();
        $csv = (string) $client->getInternalResponse()->getContent();
        self::assertStringStartsWith("rubrique;libelle;valeur;valeur_n_1;ecart;unite_ecart\n", $csv);
        self::assertStringContainsString('mrr_par_forfait;"Formule BO15 Fictive";2900', $csv);
    }

    public function testPersonalDataExportsAreJournaledAndListed(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->adminSession($client, '0190f5e4-0000-7000-8000-0000000a8063', 'super_admin');

        $page = $client->request('GET', '/admin/logs?onglet=exports');
        self::assertResponseIsSuccessful();
        self::assertCount(10, $page->filter('.tm-bo15-exports li'), 'super-admin : tous les exports');
        self::assertStringContainsString('Clients (données personnelles · journalisé)', $page->filter('.tm-bo15-exports')->text());
        self::assertStringContainsString('Aucun export journalisé', $page->filter('#bo15-recent')->ancestors()->first()->text());

        $client->request('GET', '/admin/clients/export.csv?statut=active');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith("nom;email;telephone;type;statut;email_verifie;inscrit_le;derniere_connexion\n", (string) $client->getInternalResponse()->getContent());
        $client->request('GET', '/admin/artisans/export.csv');
        self::assertStringStartsWith("nom;siret;metiers;departement;statut;masque;completude;credits;inscrit_le\n", (string) $client->getInternalResponse()->getContent());
        $client->request('GET', '/admin/communications/journal.csv');
        self::assertResponseIsSuccessful();
        self::assertSame(['artisans', 'clients', 'journal_envois'], array_map(DbalValue::string(...), $connection->fetchFirstColumn("SELECT subject_id FROM admin.audit_log WHERE action = 'admin.export' ORDER BY subject_id")));

        $page = $client->request('GET', '/admin/logs?onglet=exports');
        $recent = $page->filter('#bo15-recent')->ancestors()->first()->text();
        self::assertStringContainsString('Clients', $recent);
        self::assertStringContainsString('statut : active', $recent, 'filtres journalisés');
        self::assertStringContainsString('Journal d’envoi des e-mails', $recent);
    }

    public function testScheduledExportsAreEncryptedListedDownloadedAndPurged(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        $bus = self::getContainer()->get(CommandBus::class);
        \assert($connection instanceof Connection && $bus instanceof CommandBus);
        $bus->dispatch(new GenerateScheduledExport('artisans_actifs'));
        $bus->dispatch(new GenerateScheduledExport('factures_mois'));
        self::assertSame(2, DbalValue::int($connection->fetchOne('SELECT count(*) FROM admin.scheduled_export')));
        self::assertSame(0, DbalValue::int($connection->fetchOne("SELECT count(*) FROM shared.stored_file f JOIN admin.scheduled_export e ON e.file_id = f.id WHERE f.wrapped_dek IS NULL OR f.purpose <> 'scheduled_export'")), 'fichiers chiffrés');

        $this->adminSession($client, '0190f5e4-0000-7000-8000-0000000a8065', 'finance');
        $page = $client->request('GET', '/admin/logs?onglet=exports');
        self::assertStringContainsString('Hebdomadaire · lundi 06:00', $page->filter('.tm-bo15-schedules')->text());
        self::assertCount(2, $page->filter('.tm-bo15-files li'));
        $client->click($page->filter('.tm-bo15-files a')->reduce(static fn ($a): bool => str_contains($a->text(), 'Artisans actifs'))->link());
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith("\u{FEFF}nom;siret;metiers;code_postal;completude;verifie_le\n", (string) $client->getInternalResponse()->getContent());
        self::assertSame(1, DbalValue::int($connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE action = 'admin.export' AND subject_id = 'planifie_artisans_actifs'")), 'téléchargement journalisé');

        // Arrivé à expiration : détruit au passage suivant, plus téléchargeable.
        $old = DbalValue::string($connection->fetchOne("SELECT id FROM admin.scheduled_export WHERE kind = 'factures_mois'"));
        $connection->executeStatement("UPDATE admin.scheduled_export SET created_at = now() - interval '100 days', expires_at = now() - interval '1 day' WHERE id = :id", ['id' => $old]);
        $client->request('GET', '/admin/logs/exports/'.$old);
        self::assertResponseStatusCodeSame(404);
        $bus->dispatch(new GenerateScheduledExport('factures_mois'));
        self::assertFalse($connection->fetchOne('SELECT 1 FROM admin.scheduled_export WHERE id = :id', ['id' => $old]));
        self::assertSame(2, DbalValue::int($connection->fetchOne('SELECT count(*) FROM admin.scheduled_export')));
    }

    public function testSupportCannotExportClients(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->adminSession($client, '0190f5e4-0000-7000-8000-0000000a8064', 'support');
        $page = $client->request('GET', '/admin/clients');
        self::assertCount(0, $page->filter('a[href*="clients/export.csv"]'));
        $client->request('GET', '/admin/clients/export.csv');
        self::assertResponseStatusCodeSame(403);
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
