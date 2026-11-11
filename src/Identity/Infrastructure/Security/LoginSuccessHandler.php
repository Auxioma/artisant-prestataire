<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/** Après connexion : page d'origine validée (liste blanche interne), sinon tableau de bord selon le profil. */
final readonly class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    use TargetPathTrait;

    public function __construct(private TargetPathPolicy $targets, private UrlGeneratorInterface $urls)
    {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): RedirectResponse
    {
        $session = $request->getSession();
        $stored = $this->getTargetPath($session, 'main');
        $this->removeTargetPath($session, 'main');

        $target = $this->targets->sanitize($request->request->getString('_target_path') ?: null)
            ?? $this->targets->sanitize(null === $stored ? null : self::relative($stored))
            ?? $this->dashboard($token);

        return new RedirectResponse($target, RedirectResponse::HTTP_SEE_OTHER);
    }

    /** Le pare-feu mémorise l'URL absolue demandée : seul son chemin (et sa requête) est réutilisé. */
    private static function relative(string $url): ?string
    {
        $path = parse_url($url, \PHP_URL_PATH);
        $query = parse_url($url, \PHP_URL_QUERY);

        return \is_string($path) ? $path.(\is_string($query) ? '?'.$query : '') : null;
    }

    /** Tableaux de bord C01 / PR01 livrés aux lots 4 et 5 : accueil en attendant. */
    private function dashboard(TokenInterface $token): string
    {
        $route = \in_array(SecurityUser::ROLE_ARTISAN, $token->getRoleNames(), true) ? 'pro_dashboard' : 'client_dashboard';
        try {
            return $this->urls->generate($route);
        } catch (RouteNotFoundException) {
            return $this->urls->generate('public_home');
        }
    }
}
