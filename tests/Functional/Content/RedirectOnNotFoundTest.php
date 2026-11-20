<?php

declare(strict_types=1);

namespace App\Tests\Functional\Content;

use App\Content\Application\Port\RedirectStore;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Une ancienne URL de métier répond 301 vers la nouvelle (CDC §7, BO06), en conservant la requête. */
final class RedirectOnNotFoundTest extends WebTestCase
{
    public function testOldUrlRedirectsPermanently(): void
    {
        $client = self::createClient();
        $store = self::getContainer()->get(RedirectStore::class);
        \assert($store instanceof RedirectStore);
        $store->redirect('/plombier/', '/plombier-chauffagiste/', true, 'test');

        $client->request('GET', '/plombier/le-havre-76600?tri=note');

        self::assertResponseStatusCodeSame(301);
        self::assertResponseHeaderSame('Location', '/plombier-chauffagiste/le-havre-76600?tri=note');
    }

    public function testUnknownUrlStays404AndPostIsNotRedirected(): void
    {
        $client = self::createClient();
        $store = self::getContainer()->get(RedirectStore::class);
        \assert($store instanceof RedirectStore);
        $store->redirect('/ancien', '/nouveau', false, 'test');

        $client->request('GET', '/page-inconnue');
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/ancien');
        self::assertResponseStatusCodeSame(404, 'Pas de redirection silencieuse d’un POST');
    }
}
