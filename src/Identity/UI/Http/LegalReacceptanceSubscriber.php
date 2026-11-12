<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\Legal\PendingLegalAcceptances;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * RG-002 — à chaque navigation (page HTML, GET), un utilisateur connecté qui n'a pas accepté la dernière version
 * substantielle des CGU / CGV est redirigé vers la page d'acceptation. Vérification en base au plus toutes les
 * RECHECK_SECONDS par session ; pages légales, déconnexion et API restent accessibles.
 */
final readonly class LegalReacceptanceSubscriber implements EventSubscriberInterface
{
    public const string SESSION_CHECKED = 'legal.checked_at';
    private const int RECHECK_SECONDS = 300;
    private const int AFTER_FIREWALL = 7;
    private const array ALLOWED_ROUTES = ['identity_legal_reaccept', 'identity_logout', 'identity_legal_document', 'identity_legal_versions', 'identity_legal_version', 'identity_legal_document_legacy', 'identity_cookie_consent'];

    public function __construct(private CurrentUser $currentUser, private QueryBus $queries, private UrlGeneratorInterface $urls)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', self::AFTER_FIREWALL]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod('GET') || !$request->hasSession() || \in_array($request->attributes->get('_route'), self::ALLOWED_ROUTES, true)
            || str_starts_with($request->getPathInfo(), '/api/') || str_starts_with($request->getPathInfo(), '/admin')) {
            return;
        }
        $userId = $this->currentUser->id();
        if (null === $userId) {
            return;
        }
        $session = $request->getSession();
        $checked = $session->get(self::SESSION_CHECKED);
        if (\is_int($checked) && time() - $checked < self::RECHECK_SECONDS) {
            return;
        }
        $pending = $this->queries->ask(new PendingLegalAcceptances($userId));
        if (\is_array($pending) && [] !== $pending) {
            $session->set(LegalReacceptanceController::SESSION_TARGET, $request->getRequestUri());
            $event->setResponse(new RedirectResponse($this->urls->generate('identity_legal_reaccept')));

            return;
        }
        $session->set(self::SESSION_CHECKED, time());
    }
}
