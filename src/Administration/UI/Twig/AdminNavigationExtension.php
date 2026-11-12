<?php

declare(strict_types=1);

namespace App\Administration\UI\Twig;

use App\Administration\Domain\Admin\AdminAction;
use App\Administration\Domain\Admin\AdminModule;
use App\Shared\Application\Admin\PendingArtisanVerifications;
use App\Shared\Application\Admin\PendingRequestModerations;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Moderation\PendingProfileReviews;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Navigation du back-office (Figma 186:76) : seules les rubriques que l'administrateur peut voir (permission
 * `admin.<module>.view`) ; une rubrique dont l'écran n'est pas encore livré est affichée sans lien (« Bientôt »).
 */
final class AdminNavigationExtension extends AbstractExtension
{
    /** Écran de chaque rubrique (route), quand il existe. */
    private const array ROUTES = [
        'dashboard' => 'admin_dashboard',
        'clients' => 'admin_clients',
        'artisans' => 'admin_artisans',
        'requests' => 'admin_requests',
        'moderation' => 'admin_moderation',
        'catalog' => 'admin_catalog',
        'seo' => 'admin_seo',
        'cms' => 'admin_cms',
        'notifications' => 'admin_notifications',
        'settings' => 'admin_settings',
        'team' => 'admin_team',
        'logs' => 'admin_logs',
    ];
    private const array ICONS = [
        'dashboard' => 'grid', 'clients' => 'user', 'artisans' => 'shield', 'requests' => 'file', 'moderation' => 'alert',
        'support' => 'msg', 'catalog' => 'briefcase', 'geo' => 'pin', 'plans' => 'star', 'offers' => 'tag', 'cms' => 'file',
        'notifications' => 'mail', 'seo' => 'search', 'settings' => 'sliders', 'team' => 'users', 'logs' => 'eye',
    ];

    public function __construct(private readonly AuthorizationCheckerInterface $auth, private readonly UrlGeneratorInterface $urls, private readonly QueryBus $queries)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('tm_admin_navigation', $this->navigation(...))];
    }

    /** @return array<string, list<array{module: string, label: string, icon: string, href: ?string, count: int}>> */
    public function navigation(): array
    {
        $groups = [];
        foreach (AdminModule::cases() as $module) {
            if (!$this->auth->isGranted($module->permission(AdminAction::View))) {
                continue;
            }
            $groups[$module->group()][] = ['module' => $module->value, 'label' => $module->label(), 'icon' => self::ICONS[$module->value], 'href' => $this->href($module), 'count' => $this->count($module)];
        }

        return $groups;
    }

    /** Pastille de travail en attente (Figma : « Artisans & vérification 12 », demandes en modération). */
    private function count(AdminModule $module): int
    {
        $query = match ($module) {
            AdminModule::Artisans => new PendingArtisanVerifications(),
            AdminModule::Requests => new PendingRequestModerations(),
            AdminModule::Moderation => new PendingProfileReviews(),
            default => null,
        };
        if (null === $query) {
            return 0;
        }
        $pending = $this->queries->ask($query);
        $pending = \is_array($pending) ? \count($pending) : $pending;

        return \is_int($pending) ? $pending : 0;
    }

    private function href(AdminModule $module): ?string
    {
        $route = self::ROUTES[$module->value] ?? null;
        if (null === $route) {
            return null;
        }
        try {
            return $this->urls->generate($route);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
