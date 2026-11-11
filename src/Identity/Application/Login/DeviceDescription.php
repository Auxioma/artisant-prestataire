<?php

declare(strict_types=1);

namespace App\Identity\Application\Login;

/**
 * Résumé lisible et grossier de l'appareil (« Chrome sur Windows ») pour l'alerte E-05 et la liste des appareils :
 * jamais la chaîne User-Agent complète (minimisation des données).
 */
final readonly class DeviceDescription
{
    private const array BROWSERS = ['Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'];
    private const array SYSTEMS = ['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows', 'Mac OS X' => 'macOS', 'Macintosh' => 'macOS', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux'];

    public static function fromUserAgent(?string $userAgent): string
    {
        $userAgent ??= '';
        $browser = 'Navigateur inconnu';
        foreach (self::BROWSERS as $marker => $name) {
            if (str_contains($userAgent, $marker)) {
                $browser = $name;
                break;
            }
        }
        foreach (self::SYSTEMS as $marker => $name) {
            if (str_contains($userAgent, $marker)) {
                return $browser.' sur '.$name;
            }
        }

        return $browser;
    }
}
