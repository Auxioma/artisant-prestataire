<?php

declare(strict_types=1);

namespace App\Tests\Functional\Administration;

use App\Tests\Support\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** T-805a — BO01 : indicateurs du mois (requêtes réelles de chaque module), choix du mois, export CSV réservé. */
final class DashboardAdminTest extends WebTestCase
{
    use AdminSession;

    public function testSuperAdminSeesTheKeyIndicatorsAndExportsThem(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->adminSession($client, '0190f5e4-0000-7000-8000-0000000a8051', 'super_admin');

        $page = $client->request('GET', '/admin?mois=2026-10');
        self::assertResponseIsSuccessful();
        self::assertCount(10, $page->filter('.tm-bo01-kpis')->first()->filter('.tm-bo01-kpi'));
        self::assertStringContainsString('Activité du 1er au 31 octobre 2026 · comparaison avec octobre 2025', $page->filter('.tm-admin-head__sub')->text());
        self::assertStringContainsString('Non mesuré', $page->filter('.tm-bo01-kpi')->first()->text(), 'visiteurs');
        self::assertStringContainsString('Note moyenne des avis', $page->filter('.tm-bo01-more')->text());
        self::assertSame('2026-10', $page->filter('#bo01_mois option[selected]')->attr('value'));

        $client->request('GET', '/admin/tableau-de-bord.csv?mois=2026-10');
        self::assertResponseIsSuccessful();
        $csv = (string) $client->getInternalResponse()->getContent();
        self::assertStringStartsWith("indicateur;cle;unite;periode;valeur;valeur_n_1;ecart;unite_ecart\n", $csv);
        self::assertStringContainsString(';mrr_cents;cents;"2026-10-01 / 2026-11-01 00:00";', $csv);
        self::assertSame(18, substr_count($csv, "\n"), 'en-tête + 17 indicateurs');
    }

    public function testModeratorSeesTheDashboardButCannotExport(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->adminSession($client, '0190f5e4-0000-7000-8000-0000000a8052', 'moderator');

        $page = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('a[href*="tableau-de-bord.csv"]'));
        $client->request('GET', '/admin/tableau-de-bord.csv');
        self::assertResponseStatusCodeSame(403);
    }
}
