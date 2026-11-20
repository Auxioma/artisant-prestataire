<?php

declare(strict_types=1);

namespace App\Tests\Functional\Content;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** CDC §9 (T-903b) : déclaration d'accessibilité et mention de l'état de conformité sur chaque page (P-153). */
final class AccessibilityStatementTest extends WebTestCase
{
    public function testStatementAndFooterMention(): void
    {
        $client = self::createClient();

        $home = $client->request('GET', '/');
        $link = $home->filter('.tm-footer a[href="/accessibilite"]');
        self::assertCount(1, $link, 'mention dans le pied de page');
        self::assertSame('Accessibilité : non conforme', $link->text());

        $page = $client->request('GET', '/accessibilite');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Déclaration d’accessibilité');
        self::assertStringContainsString('non conforme', $page->filter('#etat')->ancestors()->first()->text());
        foreach (['etat', 'resultats', 'non-accessibles', 'etablissement', 'retour', 'recours', 'schema'] as $id) {
            self::assertCount(1, $page->filter('.tm-legal__toc a[href="#'.$id.'"]'), $id);
            self::assertCount(1, $page->filter('h2#'.$id), $id);
        }
        self::assertSame('/contact?motif=Accessibilit%C3%A9', $page->filter('#retour')->ancestors()->first()->filter('a.tm-button')->attr('href'));
        self::assertStringContainsString('Défenseur des droits', $page->filter('#recours')->ancestors()->first()->text());

        $contact = $client->request('GET', '/contact?motif=Accessibilit%C3%A9');
        self::assertSame('Accessibilité', $contact->filter('select option[selected]')->text(), 'motif pré-sélectionné');
    }
}
