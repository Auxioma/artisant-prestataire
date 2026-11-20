<?php

declare(strict_types=1);

namespace App\Tests\Integration\Design;

use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;
use Twig\Environment;

/** T-110 : gabarit de base, en-tête (CDC §3.1), pied de page, fil d'Ariane (§3.3, §7). */
final class LayoutTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    protected function setUp(): void
    {
        self::bootKernel();
        $stack = self::getContainer()->get(RequestStack::class);
        \assert($stack instanceof RequestStack);
        $stack->push(Request::create('https://www.trouvemoi.test/plomberie'));
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(string $component, array $props = []): Crawler
    {
        return $this->renderTwigComponent($component, $props)->crawler();
    }

    /** Régression : un import CSS en JS devient un module « data: » bloqué par la CSP, et plus aucun script ne démarre. */
    public function testImportmapContainsNoDataUrlModule(): void
    {
        $twig = self::getContainer()->get('twig');
        \assert($twig instanceof Environment);
        $html = $twig->render('base.html.twig');

        self::assertSame(1, preg_match('#<script type="importmap" nonce="[^"]+"[^>]*>(.*?)</script>#s', $html, $match), 'Importmap inline avec nonce CSP');
        $importmap = json_decode($match[1], true, 16, \JSON_THROW_ON_ERROR);
        self::assertIsArray($importmap);
        $imports = $importmap['imports'] ?? null;
        self::assertIsArray($imports);
        foreach ($imports as $name => $url) {
            self::assertIsString($url);
            self::assertStringStartsNotWith('data:', $url, \sprintf('« %s » : module data: bloqué par la CSP (charger la CSS par <link>)', $name));
        }
        self::assertStringContainsString('<meta name="csp-nonce"', $html, 'Nonce fourni à Turbo');
        self::assertStringContainsString('<html lang="fr">', $html);
        self::assertStringContainsString('href="#contenu"', $html, 'Lien d’évitement');
    }

    public function testVisitorHeader(): void
    {
        $header = $this->render('Header', ['variant' => 'visitor', 'active' => 'home']);

        self::assertSame('Navigation principale', $header->filter('.tm-header__nav')->attr('aria-label'));
        self::assertSame('Accueil', trim($header->filter('.tm-header__nav [aria-current="page"]')->text()));
        self::assertCount(0, $header->filter('button[aria-controls="tm-mega-menu"]'), 'P01.1 : méga-menu vide → élément masqué');
        self::assertStringContainsString('100 devis offerts', $header->filter('#tm-signup-menu')->text(), 'Valeur lue dans credits.free_on_signup (RG-014)');
        self::assertCount(1, $header->filter('dialog#tm-mobile-menu'));
        self::assertSame('tm-mobile-menu', $header->filter('[aria-haspopup="dialog"]')->attr('aria-controls'));
    }

    public function testMegaMenuListsActiveCategoriesWithJobs(): void
    {
        $bus = self::getContainer()->get(\App\Shared\Application\Bus\CommandBus::class);
        \assert($bus instanceof \App\Shared\Application\Bus\CommandBus);
        $bus->dispatch(new \App\Catalog\Application\Command\CreateCategory('Plomberie', 'wrench'));
        $bus->dispatch(new \App\Catalog\Application\Command\CreateJob('plomberie', 'Plombier'));
        self::getContainer()->get('cache.catalog')->clear();

        $header = $this->render('Header', ['variant' => 'visitor']);
        $mega = $header->filter('button[aria-controls="tm-mega-menu"]');
        self::assertSame('false', $mega->attr('aria-expanded'));
        self::assertNotNull($header->filter('#tm-mega-menu')->attr('hidden'));
        self::assertSame('Plomberie', trim($header->filter('.tm-mega__category-link')->text()));
        self::assertSame('Plombier', trim($header->filter('.tm-mega__job')->text()));
        self::assertCount(1, $header->filter('.tm-mobile-menu__accordion'));
    }

    public function testArtisanHeaderShowsCompactCreditGaugeAndCounters(): void
    {
        $header = $this->render('Header', [
            'variant' => 'artisan', 'accountName' => 'Atelier', 'unreadMessages' => 3, 'unreadNotifications' => 1,
            'credits' => ['remaining' => 14, 'total' => 100, 'state' => 'alert'],
        ]);

        $pill = $header->filter('.tm-credit-pill');
        self::assertStringContainsString('tm-credit-pill--alert', (string) $pill->attr('class'));
        self::assertStringContainsString('14 devis', $pill->text());
        self::assertStringContainsString('Messagerie, 3 non lus', $header->text());
        self::assertStringContainsString('Notifications, 1 non lue', $header->text());
        self::assertSame('true', $header->filter('.tm-icon-link__count')->first()->attr('aria-hidden'), 'Pastille décorative, information portée par le texte');
        self::assertStringContainsString('Mes devis', $header->filter('#tm-account-menu')->text());
    }

    public function testTunnelHeaderHasNoNavigation(): void
    {
        $header = $this->render('Header', ['variant' => 'tunnel']);

        self::assertCount(0, $header->filter('.tm-header__nav'));
        self::assertCount(0, $header->filter('dialog'));
        self::assertStringContainsString('Quitter', $header->text());
    }

    public function testBreadcrumbMarkupAndStructuredData(): void
    {
        $twig = self::getContainer()->get('twig');
        \assert($twig instanceof Environment);
        $html = $twig->createTemplate('<twig:Breadcrumb :items="items" />')->render(['items' => [
            ['label' => 'Accueil', 'href' => '/'],
            ['label' => 'Plomberie </script><script>alert(1)</script>', 'href' => '/plomberie'],
            ['label' => 'Le Havre'],
        ]]);
        $crawler = new Crawler($html);

        self::assertSame('Fil d’Ariane', $crawler->filter('nav')->attr('aria-label'));
        self::assertSame('Le Havre', $crawler->filter('[aria-current="page"]')->text());
        self::assertCount(2, $crawler->filter('nav a'));

        $json = $crawler->filter('script[type="application/ld+json"]')->text();
        self::assertStringNotContainsString('<', $json, 'Libellé échappé (JSON_HEX_TAG) : aucune fermeture de script injectable');
        $data = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('BreadcrumbList', $data['@type']);
        $elements = $data['itemListElement'];
        self::assertIsArray($elements);
        self::assertIsArray($elements[1]);
        self::assertIsArray($elements[2]);
        self::assertSame('https://www.trouvemoi.test/plomberie', $elements[1]['item']);
        self::assertArrayNotHasKey('item', $elements[2], 'Page courante sans lien');
    }

    public function testFooterWithoutOptionalBlocks(): void
    {
        $footer = $this->render('Footer');

        self::assertCount(0, $footer->filter('.tm-footer__socials'), 'Aucun réseau affiché sans URL renseignée en BO');
        self::assertCount(0, $footer->filter('.tm-footer__newsletter'), 'Pas de formulaire sans route de traitement');
        self::assertStringContainsString('Comment sont classés les professionnels ?', $footer->text(), 'Information L111-7');
        self::assertCount(1, $footer->filter('button[data-cookie-consent-open]'), 'Gérer mes cookies (CMP)');
        self::assertStringContainsString('© '.date('Y'), $footer->text());
    }

    public function testFooterSocialLinksOpenSafelyInNewTab(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $connection->executeStatement("INSERT INTO admin.setting (key, value, type, updated_at) VALUES ('social.linkedin', '\"https://www.linkedin.com/company/trouvemoi\"', 'nullable_url', now()) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value");
        self::getContainer()->get('cache.settings')->clear();
        self::assertSame(1, DbalValue::int($connection->fetchOne("SELECT count(*) FROM admin.setting WHERE key = 'social.linkedin'")));

        $link = $this->render('Footer')->filter('.tm-footer__social');
        self::assertCount(1, $link);
        self::assertSame('https://www.linkedin.com/company/trouvemoi', $link->attr('href'));
        self::assertSame('noopener noreferrer', $link->attr('rel'));
        self::assertSame('_blank', $link->attr('target'));
        self::assertStringContainsString('LinkedIn (nouvel onglet)', $link->text());
    }
}
