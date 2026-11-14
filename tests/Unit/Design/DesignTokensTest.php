<?php

declare(strict_types=1);

namespace App\Tests\Unit\Design;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fous du design system (T-108) : contrastes WCAG 2.1 AA (CDC §9, audit Figma V2.1),
 * aucune couleur hors tokens.css (RG-001), icônes référencées présentes dans le sprite.
 */
final class DesignTokensTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';
    private const float AA_NORMAL_TEXT = 4.5;
    private const float AA_LARGE_TEXT_OR_UI = 3.0;

    /** @var array<string, string>|null */
    private static ?array $palette = null;

    /** @return array<string, string> nom de variable => #rrggbb */
    private static function palette(): array
    {
        if (null === self::$palette) {
            $css = (string) file_get_contents(self::ROOT.'/assets/styles/tokens.css');
            preg_match_all('/(--tm-[a-z-]+):\s*(#[0-9a-fA-F]{6})\b/', $css, $matches, \PREG_SET_ORDER);
            self::$palette = [];
            foreach ($matches as [, $name, $hex]) {
                self::$palette[$name] = strtolower($hex);
            }
        }

        return self::$palette;
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(
            static function (string $pair): float {
                $c = hexdec($pair) / 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            },
            str_split(ltrim($hex, '#'), 2),
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private static function contrast(string $foreground, string $background): float
    {
        $l1 = self::luminance($foreground);
        $l2 = self::luminance($background);

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /** @return iterable<string, array{string, string, float}> */
    public static function textPairs(): iterable
    {
        $normal = self::AA_NORMAL_TEXT;
        yield 'texte courant (ink) sur blanc' => ['--tm-ink', '--tm-white', $normal];
        yield 'texte courant sur fond app' => ['--tm-ink', '--tm-bg', $normal];
        yield 'titres (navy) sur blanc' => ['--tm-navy', '--tm-white', $normal];
        yield 'titres sur bleu très clair' => ['--tm-navy', '--tm-ice', $normal];
        yield 'texte secondaire (muted) sur blanc — audit V2.1' => ['--tm-muted', '--tm-white', $normal];
        yield 'texte secondaire sur fond app' => ['--tm-muted', '--tm-bg', $normal];
        yield 'texte secondaire sur bleu très clair' => ['--tm-muted', '--tm-ice', $normal];
        yield 'orange texte sur blanc — audit V2.1' => ['--tm-orange-ink', '--tm-white', $normal];
        yield 'orange texte sur fond alerte' => ['--tm-orange-ink', '--tm-orange-soft', $normal];
        yield 'lien (blue) sur blanc' => ['--tm-blue', '--tm-white', $normal];
        yield 'lien sur survol secondaire' => ['--tm-blue', '--tm-blue-soft', $normal];
        yield 'vert texte sur blanc — CDC Q21' => ['--tm-green', '--tm-white', $normal];
        yield 'badge Disponible : vert sur menthe' => ['--tm-green', '--tm-mint', $normal];
        yield 'erreur sur blanc' => ['--tm-red', '--tm-white', $normal];
        yield 'erreur sur fond rouge clair — audit V2.1' => ['--tm-red', '--tm-red-soft', $normal];
        yield 'texte blanc sur bouton danger' => ['--tm-white', '--tm-red', $normal];
        yield 'texte blanc sur bleu secondaire' => ['--tm-white', '--tm-blue', $normal];
        yield 'texte blanc sur marine' => ['--tm-white', '--tm-navy', $normal];
        yield 'texte marine sur orange (bouton primaire, CDC §2.4)' => ['--tm-navy', '--tm-orange', $normal];
        yield 'texte marine sur orange clair' => ['--tm-navy', '--tm-gold', $normal];
        yield 'bordure de champ focus (blue) sur blanc — composant UI' => ['--tm-blue', '--tm-white', self::AA_LARGE_TEXT_OR_UI];
    }

    #[DataProvider('textPairs')]
    public function testPairMeetsWcagAa(string $foreground, string $background, float $minimum): void
    {
        $palette = self::palette();
        self::assertArrayHasKey($foreground, $palette);
        self::assertArrayHasKey($background, $palette);

        $ratio = self::contrast($palette[$foreground], $palette[$background]);
        self::assertGreaterThanOrEqual($minimum, $ratio, \sprintf('%s sur %s : %.2f:1', $foreground, $background, $ratio));
    }

    /** Les combinaisons interdites du CDC §2.4 restent effectivement non conformes : on ne doit pas les utiliser. */
    public function testDocumentedForbiddenPairsAreIndeedBelowAa(): void
    {
        $palette = self::palette();

        self::assertLessThan(self::AA_NORMAL_TEXT, self::contrast($palette['--tm-white'], $palette['--tm-orange']));
        self::assertLessThan(self::AA_NORMAL_TEXT, self::contrast($palette['--tm-white'], $palette['--tm-success']));
        self::assertLessThan(self::AA_NORMAL_TEXT, self::contrast($palette['--tm-orange'], $palette['--tm-white']));
    }

    public function testCdcMandatoryVariablesAreDefined(): void
    {
        $css = (string) file_get_contents(self::ROOT.'/assets/styles/tokens.css');
        foreach (['--primary', '--secondary', '--orange', '--orange-light', '--background', '--background-light', '--success', '--gradient-blue', '--gradient-orange', '--font-family', '--danger', '--warning', '--radius-btn', '--radius-card', '--shadow-card', '--focus-ring'] as $variable) {
            self::assertMatchesRegularExpression('/\s'.preg_quote($variable, '/').':/', $css, $variable.' (CDC §2.3) manquant');
        }
    }

    public function testNoFontSizeBelowTwelvePixels(): void
    {
        foreach (glob(self::ROOT.'/assets/styles/{,*/}*.css', \GLOB_BRACE) ?: [] as $file) {
            preg_match_all('/\b(\d+(?:\.\d+)?)px\/\d+px/', (string) file_get_contents($file), $sizes);
            foreach ($sizes[1] as $size) {
                self::assertGreaterThanOrEqual(12.0, (float) $size, basename($file).' : taille de police < 12 px (audit V2.1)');
            }
        }
    }

    public function testNoHardcodedColorOutsideTokens(): void
    {
        $files = array_merge(
            glob(self::ROOT.'/assets/styles/{,*/}*.css', \GLOB_BRACE) ?: [],
            glob(self::ROOT.'/templates/{,*/,*/*/}*.twig', \GLOB_BRACE) ?: [],
        );
        foreach ($files as $file) {
            // tokens.css est la source (les couleurs des e-mails sont recopiées dans EmailPalette, côté PHP).
            if (str_ends_with($file, '/tokens.css')) {
                continue;
            }
            $content = (string) file_get_contents($file);
            // On retire les ancres (href="#…"), références SVG (url(#…)) et commentaires Twig ({# … #}) avant la recherche.
            $code = preg_replace('/href="[^"]*#[^"]*"|url\(#[^)]*\)|\{#.*?#\}/s', '', $content) ?? '';
            self::assertDoesNotMatchRegularExpression(
                '/#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})\b|\b(?:rgba?|hsla?)\(/',
                $code,
                basename($file).' : couleur codée en dur (utiliser tokens.css, RG-001)',
            );
        }
    }

    public function testEveryReferencedIconExistsInSprite(): void
    {
        $sprite = (string) file_get_contents(self::ROOT.'/assets/icons/sprite.svg');
        preg_match_all('/<symbol id="([A-Za-z-]+)"/', $sprite, $symbols);
        self::assertCount(77, $symbols[1], '39 icônes Figma V2 + cookie + étoile et cœur pleins + 5 réseaux sociaux + 11 des écrans A01/A02 (T-202) + connexion (T-204) + 14 de l’espace pro (T-405b, dont fileEuro propre à la maquette) + 2 de l’espace client (T-406a) + crayon du back-office (T-601b) + téléchargement des factures (T-605a)');

        foreach (glob(self::ROOT.'/templates/{,*/,*/*/}*.twig', \GLOB_BRACE) ?: [] as $file) {
            preg_match_all('/<twig:Icon[^>]*\sname="([A-Za-z-]+)"/', (string) file_get_contents($file), $used);
            foreach ($used[1] as $name) {
                self::assertContains($name, $symbols[1], \sprintf('%s : icône « %s » absente du sprite', basename($file), $name));
            }
        }
    }
}
