<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig;

use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Fonctions Twig de navigation globale (header, footer, fil d'Ariane — CDC §3).
 *
 * - tm_path() / tm_url() : génèrent une route si elle existe, sinon « # » — les pages cibles arrivent au fil des lots
 *   (annexe A) sans casser la navigation commune.
 * - tm_setting_int() : valeurs métier affichées dans l'interface, toujours lues dans les paramètres (RG-014).
 * - tm_social_links(all = false) : réseaux renseignés en BO13 (aucune URL fictive) ; `true` : les cinq, url null si non renseigné.
 * (tm_main_categories() est fournie par le module Catalog : Catalog\UI\Twig\CatalogExtension.)
 */
final class NavigationExtension extends AbstractExtension
{
    private const array SOCIAL_NETWORKS = [
        'facebook' => [SettingKey::SocialFacebook, 'Facebook'],
        'instagram' => [SettingKey::SocialInstagram, 'Instagram'],
        'youtube' => [SettingKey::SocialYoutube, 'YouTube'],
        'tiktok' => [SettingKey::SocialTiktok, 'TikTok'],
        'linkedin' => [SettingKey::SocialLinkedin, 'LinkedIn'],
    ];

    public function __construct(
        private readonly RouterInterface $router,
        private readonly SettingsProvider $settings,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tm_path', $this->path(...)),
            new TwigFunction('tm_url', $this->url(...)),
            new TwigFunction('tm_route_exists', $this->routeExists(...)),
            new TwigFunction('tm_setting_int', $this->settingInt(...)),
            new TwigFunction('tm_social_links', $this->socialLinks(...)),
        ];
    }

    public function routeExists(string $route): bool
    {
        return null !== $this->router->getRouteCollection()->get($route);
    }

    /**
     * @param array<string, string|int> $parameters
     */
    public function path(string $route, array $parameters = []): string
    {
        return $this->routeExists($route) ? $this->router->generate($route, $parameters) : '#';
    }

    /**
     * @param array<string, string|int> $parameters
     */
    public function url(string $route, array $parameters = []): string
    {
        return $this->routeExists($route) ? $this->router->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL) : '#';
    }

    public function settingInt(string $key): int
    {
        return $this->settings->int(SettingKey::from($key));
    }

    /**
     * Réseaux renseignés en BO13. `$all` (pied de page, maquette V2) : les cinq réseaux, `url` null pour ceux qui ne
     * sont pas encore renseignés (icône affichée sans lien : jamais d'URL fictive).
     *
     * @return ($all is true ? list<array{network: string, label: string, url: ?string}> : list<array{network: string, label: string, url: string}>)
     */
    public function socialLinks(bool $all = false): array
    {
        $links = [];
        foreach (self::SOCIAL_NETWORKS as $network => [$key, $label]) {
            $url = $this->settings->nullableUrl($key);
            if (null !== $url || $all) {
                $links[] = ['network' => $network, 'label' => $label, 'url' => $url];
            }
        }

        return $links;
    }
}
