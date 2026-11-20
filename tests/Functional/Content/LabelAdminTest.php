<?php

declare(strict_types=1);

namespace App\Tests\Functional\Content;

use App\Content\Application\Label\SyncLabels;
use App\Content\UI\Console\SyncLabelsCommand;
use App\Shared\Application\Bus\CommandBus;
use App\Tests\Support\AdminSession;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** T-801e — BO10.6 libellés : relevé des gabarits, texte modifié affiché sur le site, retour au texte d'origine. */
final class LabelAdminTest extends WebTestCase
{
    use AdminSession;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a8e01';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $templates = self::getContainer()->getParameter('kernel.project_dir');
        \assert(\is_string($templates));
        $commands = self::getContainer()->get(CommandBus::class);
        \assert($commands instanceof CommandBus);
        $commands->dispatch(new SyncLabels(SyncLabelsCommand::scan($templates.'/templates')));
    }

    public function testScanEditAndReset(): void
    {
        $templates = self::getContainer()->getParameter('kernel.project_dir');
        \assert(\is_string($templates));
        $defaults = SyncLabelsCommand::scan($templates.'/templates');
        self::assertSame('Se connecter', $defaults['header.login']);
        self::assertSame('S’inscrire', $defaults['footer.newsletter_submit']);

        $this->adminSession($this->client, self::ADMIN, 'content');
        $page = $this->client->request('GET', '/admin/cms/libelles?q=connecter');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('#label-header-login'));
        $this->client->submit($page->filter('#label-header-login form')->form(), ['text' => 'Connexion']);
        $this->client->submit($this->client->request('GET', '/admin/cms/libelles')->filter('#label-footer-newsletter_submit form')->form(), ['text' => '<b>Go</b>']);

        $this->client->getCookieJar()->clear();
        $home = $this->client->request('GET', '/');
        self::assertStringContainsString('Connexion', $home->filter('.tm-header')->text());
        self::assertStringNotContainsString('Se connecter', $home->filter('.tm-header__desktop-only')->text());
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        self::assertNull($connection->fetchOne("SELECT text FROM content.ui_label WHERE key = 'footer.newsletter_submit'"), 'balises refusées : texte inchangé');

        $this->adminSession($this->client, '0190f5e4-0000-7000-8000-0000000a8e02', 'content');
        $page = $this->client->request('GET', '/admin/cms/libelles?q=connecter');
        $this->client->submit($page->filter('#label-header-login')->selectButton('Revenir à l’origine')->form());
        $this->client->getCookieJar()->clear();
        self::assertStringContainsString('Se connecter', $this->client->request('GET', '/')->filter('.tm-header')->text());
    }
}
