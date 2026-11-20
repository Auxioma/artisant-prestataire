<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Application\Security\ImpersonationContext;
use App\Shared\Application\Security\ImpersonationSession;
use Symfony\Component\HttpFoundation\RequestStack;

/** La connexion assistée est notée dans la session du site, à l'ouverture (ImpersonationAuthenticator). */
final readonly class SessionImpersonationContext implements ImpersonationContext
{
    public function __construct(private RequestStack $requests)
    {
    }

    public function current(): ?ImpersonationSession
    {
        $request = $this->requests->getMainRequest();
        // Requêtes sans état (webhooks…) : ne jamais toucher à la session, même si un cookie est présent.
        if (null === $request || true === $request->attributes->get('_stateless') || !$request->hasSession() || !$request->hasPreviousSession()) {
            return null;
        }
        $value = $request->getSession()->get(self::SESSION_KEY);

        return $value instanceof ImpersonationSession ? $value : null;
    }
}
