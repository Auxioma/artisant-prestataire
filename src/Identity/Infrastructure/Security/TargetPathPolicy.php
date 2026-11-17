<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;

/**
 * A04 : « paramètre redirect validé contre une liste blanche interne ». Seul un chemin relatif à ce site, correspondant
 * à une route GET de l'application, est accepté — jamais une URL externe (redirection ouverte, ASVS 5.1.5).
 */
final readonly class TargetPathPolicy
{
    /** Routes vers lesquelles un retour après connexion n'a pas de sens. */
    private const array EXCLUDED_ROUTES = ['identity_login', 'identity_logout', 'identity_register_choice', 'identity_register_client', 'identity_verify_email'];
    private const int MAX_LENGTH = 2000;

    public function __construct(private UrlMatcherInterface $matcher)
    {
    }

    public function sanitize(?string $target): ?string
    {
        if (null === $target || '' === $target || \strlen($target) > self::MAX_LENGTH
            || !str_starts_with($target, '/') || str_starts_with($target, '//') || str_contains($target, '\\')
            || 1 === preg_match('/[\x00-\x1F\x7F]/', $target)) {
            return null;
        }
        $path = parse_url($target, \PHP_URL_PATH);
        if (!\is_string($path)) {
            return null;
        }
        $context = $this->matcher->getContext();
        $method = $context->getMethod();
        try {
            $context->setMethod('GET');
            $route = $this->matcher->match($path)['_route'] ?? null;
        } catch (ExceptionInterface) {
            return null;
        } finally {
            $context->setMethod($method);
        }
        if (!\is_string($route) || str_starts_with($route, '_') || \in_array($route, self::EXCLUDED_ROUTES, true)) {
            return null;
        }

        return $target;
    }
}
