<?php

declare(strict_types=1);

namespace App\Tests\Functional\Content;

use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** T-801d — BO10.5 menus : liens ajoutés, masqués, ordonnés, visibilité par profil, éléments spéciaux protégés. */
final class MenuAdminTest extends WebTestCase
{
    use AdminSession;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a8d01';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
    }

    /** @param array<string, mixed> $fields */
    private function post(array $fields): void
    {
        $page = $this->client->request('GET', '/admin/cms/menus');
        $token = (string) $page->filter('form[action="/admin/cms/menus"] input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/admin/cms/menus', ['_token' => $token] + $fields);
    }

    private function item(string $menu, string $label): string
    {
        return DbalValue::string($this->connection->fetchOne('SELECT id FROM content.menu_item WHERE menu = :m AND label = :l', ['m' => $menu, 'l' => $label]));
    }

    public function testSeededMenusAreEditableAndRendered(): void
    {
        $home = $this->client->request('GET', '/');
        self::assertSame(['Accueil', 'Nos professionnels', 'Comment ça marche ?', 'Aide / FAQ'], array_values(array_filter($home->filter('.tm-header__menu > li > a')->each(static fn ($a): string => trim($a->text())))));
        self::assertCount(1, $home->filter('.tm-footer button[data-cookie-consent-open]'), 'élément spécial « Gérer mes cookies »');

        $this->adminSession($this->client, self::ADMIN, 'content');
        $this->post(['action' => 'save', 'data' => ['menu' => 'main', 'label' => 'Guides', 'link' => '/pages/guides', 'audience' => 'visitor']]);
        $this->post(['action' => 'save', 'data' => ['menu' => 'main', 'label' => 'Piège', 'link' => 'javascript:alert(1)']]);
        self::assertFalse($this->connection->fetchOne("SELECT 1 FROM content.menu_item WHERE label = 'Piège'"));
        $guides = $this->item('main', 'Guides');
        $this->post(['action' => 'up', 'id' => $guides, 'menu' => 'main']);
        $faq = $this->item('main', 'Aide / FAQ');
        $this->post(['action' => 'save', 'id' => $faq, 'data' => ['label' => 'Aide', 'link' => 'route:content_faq', 'audience' => 'all']]);
        $cookies = $this->item('footer_info', 'Gérer mes cookies');
        $this->post(['action' => 'delete', 'id' => $cookies, 'menu' => 'footer_info']);
        self::assertSame(1, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM content.menu_item WHERE id = :id', ['id' => $cookies])), 'élément spécial non supprimable');

        // « Aide » décoché (masqué) ; « Guides » réservé aux visiteurs : absent pour l'administrateur connecté au BO.
        $this->client->getCookieJar()->clear();
        $home = $this->client->request('GET', '/');
        $labels = array_values(array_filter($home->filter('.tm-header__menu > li > a')->each(static fn ($a): string => trim($a->text()))));
        self::assertSame(['Accueil', 'Nos professionnels', 'Comment ça marche ?', 'Guides'], $labels);
    }
}
