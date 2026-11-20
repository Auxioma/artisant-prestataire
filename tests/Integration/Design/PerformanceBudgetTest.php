<?php

declare(strict_types=1);

namespace App\Tests\Integration\Design;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/** CDC §8 (T-904a) : images AVIF puis WebP, contrôleurs chargés à la demande, cache long des fichiers compilés. */
final class PerformanceBudgetTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    /** Contrôleurs présents sur toutes les pages (gabarit commun) : chargés d'emblée. */
    private const array EAGER = ['analytics', 'consent', 'disclosure', 'footer_accordion', 'header', 'modal', 'modal_opener'];

    public function testBannerAndLogoServeAvifFirst(): void
    {
        $banner = $this->renderTwigComponent('PageBanner', ['title' => 'Titre', 'image' => 'images/auth/a02-banner'])->crawler();
        $sources = $banner->filter('picture source');
        self::assertSame('image/avif', $sources->first()->attr('type'));
        self::assertStringContainsString('960w', (string) $sources->first()->attr('srcset'));
        self::assertSame('high', $banner->filter('picture img')->attr('fetchpriority'), 'LCP : pas de lazy-load');

        $logo = $this->renderTwigComponent('Logo')->crawler();
        self::assertSame('image/avif', $logo->filter('picture source')->attr('type'));
    }

    public function testOnlyLayoutControllersAreEager(): void
    {
        $dir = \dirname(__DIR__, 3).'/assets/controllers';
        foreach (new Finder()->files()->in($dir)->name('*_controller.js') as $file) {
            $name = substr($file->getFilename(), 0, -\strlen('_controller.js'));
            $lazy = str_contains($file->getContents(), "/* stimulusFetch: 'lazy' */");
            self::assertSame(!\in_array($name, self::EAGER, true), $lazy, $name);
        }
    }

    public function testFingerprintedAssetsAreCachedForAYear(): void
    {
        $caddyfile = (string) file_get_contents(\dirname(__DIR__, 3).'/docker/frankenphp/Caddyfile');
        self::assertStringContainsString('path /assets/*', $caddyfile);
        self::assertStringContainsString('Cache-Control "public, max-age=31536000, immutable"', $caddyfile);
        self::assertStringContainsString('docker/frankenphp/Caddyfile', (string) file_get_contents(\dirname(__DIR__, 3).'/Dockerfile'));
    }
}
