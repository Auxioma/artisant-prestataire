<?php

declare(strict_types=1);

namespace App\Tests\Integration\Design;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/** CMP (CDC §3.2, lignes directrices CNIL) : refuser aussi simple qu'accepter, rien pré-activé. */
final class CookieConsentComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testBannerAndPreferences(): void
    {
        $crawler = $this->renderTwigComponent('CookieConsent')->crawler();

        $root = $crawler->filter('[data-controller="consent"]');
        self::assertSame('1', $root->attr('data-consent-version-value'));
        self::assertSame('6', $root->attr('data-consent-months-value'));
        self::assertSame('/consentement-cookies', $root->attr('data-consent-endpoint-value'));

        $banner = $crawler->filter('.tm-cmp__banner');
        self::assertNotNull($banner->attr('hidden'), 'Masqué tant que le script n’a pas vérifié le consentement');
        $refuse = $banner->filter('[data-action="consent#refuseAll"]');
        $accept = $banner->filter('[data-action="consent#acceptAll"]');
        self::assertSame($refuse->attr('class'), $accept->attr('class'), 'Même niveau visuel pour refuser et accepter');
        self::assertStringContainsString('6 mois', $banner->text());

        $switches = $crawler->filter('dialog input[role="switch"]');
        self::assertCount(4, $switches);
        foreach (['audience', 'social', 'marketing'] as $category) {
            $input = $crawler->filter(\sprintf('dialog input[name="%s"]', $category));
            self::assertNull($input->attr('checked'), $category.' non pré-activé');
            self::assertNull($input->attr('disabled'));
        }
        $necessary = $crawler->filter('dialog input[name="necessary"]');
        self::assertNotNull($necessary->attr('checked'));
        self::assertNotNull($necessary->attr('disabled'));
        self::assertSame('tm-cmp-preferences-title', $crawler->filter('dialog')->attr('aria-labelledby'));
    }
}
