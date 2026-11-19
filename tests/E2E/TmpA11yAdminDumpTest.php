<?php

declare(strict_types=1);

namespace App\Tests\E2E;

// TMPDUMP — capture temporaire pour l'audit RGAA (T-903), à supprimer.

use App\Tests\Support\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TmpA11yAdminDumpTest extends WebTestCase
{
    use AdminSession;

    public function testDump(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->adminSession($client, '0190f5e4-0000-7000-8000-0000000a9041', 'super_admin');
        foreach (['dashboard' => '/admin', 'clients' => '/admin/clients', 'artisans' => '/admin/artisans', 'queue' => '/admin/artisans/a-verifier', 'requests' => '/admin/demandes', 'moderation' => '/admin/moderation', 'plans' => '/admin/forfaits', 'cms' => '/admin/cms', 'communications' => '/admin/communications', 'seo' => '/admin/seo', 'settings' => '/admin/parametres', 'team' => '/admin/equipe', 'logs' => '/admin/logs', 'login' => '/admin/connexion'] as $name => $url) {
            $client->request('GET', $url);
            file_put_contents('/app/public/tmp-a11y-admin-'.$name.'.html', (string) $client->getResponse()->getContent());
        }
        self::assertTrue(true);
    }
}
