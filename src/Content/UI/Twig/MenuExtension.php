<?php

declare(strict_types=1);

namespace App\Content\UI\Twig;

use App\Content\Application\Menu\MenuItem;
use App\Content\Application\Menu\MenuQuery;
use App\Shared\Application\Bus\QueryBus;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * BO10.5 — `tm_menu(clé)` : éléments actifs d'un menu visibles par le profil courant, liens résolus
 * (route absente du site : élément omis). Une seule lecture par requête.
 */
final class MenuExtension extends AbstractExtension
{
    /** @var array<string, list<MenuItem>>|null */
    private ?array $menus = null;
    private ?int $request = null;

    public function __construct(private readonly QueryBus $queries, private readonly UrlGeneratorInterface $urls, private readonly Security $security, private readonly RequestStack $requests)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('tm_menu', $this->menu(...))];
    }

    /**
     * @param ?string $profile profil imposé (`visitor`, `client`, `artisan`) — menus « Mon compte », déjà choisis selon
     *                         la variante de l'en-tête ; sinon déduit de l'utilisateur connecté
     *
     * @return list<array{label: string, href: ?string, special: ?string, icon: ?string, newTab: bool}>
     */
    public function menu(string $key, ?string $profile = null): array
    {
        $request = $this->requests->getMainRequest();
        $requestId = null === $request ? null : spl_object_id($request);
        if (null === $this->menus || $requestId !== $this->request) {
            $this->request = $requestId;
            $this->menus = [];
            $items = $this->queries->ask(new MenuQuery());
            foreach (\is_array($items) ? $items : [] as $item) {
                if ($item instanceof MenuItem) {
                    $this->menus[$item->menu][] = $item;
                }
            }
        }
        $profile ??= $this->security->isGranted('ROLE_ARTISAN') ? 'artisan' : ($this->security->isGranted('ROLE_CLIENT') ? 'client' : 'visitor');
        $links = [];
        foreach ($this->menus[$key] ?? [] as $item) {
            if (!$item->active || !\in_array($item->audience, ['all', $profile], true)) {
                continue;
            }
            if (str_starts_with($item->link, 'special:')) {
                $links[] = ['label' => $item->label, 'href' => null, 'special' => substr($item->link, \strlen('special:')), 'icon' => $item->icon, 'newTab' => false];
                continue;
            }
            $href = $this->href($item->link);
            if (null !== $href) {
                $links[] = ['label' => $item->label, 'href' => $href, 'special' => null, 'icon' => $item->icon, 'newTab' => $item->newTab];
            }
        }

        return $links;
    }

    private function href(string $link): ?string
    {
        if (!str_starts_with($link, 'route:')) {
            return $link;
        }
        $parts = explode('?', substr($link, \strlen('route:')), 2);
        parse_str($parts[1] ?? '', $parameters);
        try {
            return $this->urls->generate($parts[0], $parameters);
        } catch (RoutingException) {
            return null; // page pas encore livrée
        }
    }
}
