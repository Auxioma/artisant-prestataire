<?php

declare(strict_types=1);

namespace App\Tests\Functional\Content;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Plan du site HTML (maquette Mondesir V2) : lien du pied de page, rubriques, aucun lien vers une page non livrée. */
final class SitemapPageTest extends WebTestCase
{
    public function testFooterLinkAndSections(): void
    {
        $client = self::createClient();

        $home = $client->request('GET', '/');
        self::assertCount(1, $home->filter('.tm-footer a[href="/plan-du-site"]'), 'lien « Plan du site » du pied de page');

        $page = $client->request('GET', '/plan-du-site');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Plan du site');
        $titles = $page->filter('.tm-sitemap__card-title')->each(static fn ($n): string => trim($n->text()));
        foreach (['Accueil', 'Catégories', 'Nos professionnels', 'Comment ça marche ?', 'Aide / FAQ', 'Mon compte', 'Services', 'Offres et tarifs', 'Informations légales'] as $title) {
            self::assertContains($title, $titles);
        }
        self::assertCount(0, $page->filter('.tm-sitemap a[href="#"]'), 'aucun lien mort');
        self::assertCount(5, $page->filter('.tm-sitemap__quick-links a'));
        self::assertCount(1, $page->filter('.tm-sitemap a[href="/cookies"]'));
    }
}
