<?php

declare(strict_types=1);

namespace App\Content\UI\Http;

use App\Content\Application\Port\NotFoundLog;
use App\Content\Application\Port\RedirectStore;
use App\Shared\Domain\Time\Clock;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * BO12.2 : avant de répondre 404 à un GET/HEAD, applique une redirection 301/302 enregistrée
 * (les cibles sont toujours des chemins internes : aucun open redirect possible).
 */
final readonly class RedirectOnNotFoundSubscriber implements EventSubscriberInterface
{
    /** Hors rapport des 404 : ressources techniques et zones privées. */
    private const array IGNORED = ['/admin', '/api/', '/assets/', '/_', '/media/', '/sitemaps/', '/favicon', '/apple-touch-icon', '/.well-known/'];

    public function __construct(private RedirectStore $redirects, private NotFoundLog $notFound, private Clock $clock)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 64]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$event->getThrowable() instanceof NotFoundHttpException || !\in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return;
        }
        $redirect = $this->redirects->resolve($request->getPathInfo());
        if (null === $redirect || !str_starts_with($redirect['target'], '/') || str_starts_with($redirect['target'], '//')) {
            $this->log($request->getPathInfo(), $request->headers->get('Referer'));

            return;
        }
        $query = $request->getQueryString();
        $event->setResponse(new RedirectResponse($request->getBaseUrl().$redirect['target'].(null !== $query ? '?'.$query : ''), $redirect['status']));
    }

    /** BO12.2 — rapport des 404 : chemin et page d'origine réduite à son domaine et son chemin (jamais de paramètres). */
    private function log(string $path, ?string $referer): void
    {
        foreach (self::IGNORED as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }
        $origin = null;
        if (null !== $referer) {
            $parts = parse_url($referer);
            $origin = \is_array($parts) && isset($parts['host']) ? $parts['host'].($parts['path'] ?? '') : null;
        }
        $this->notFound->record($path, $origin, $this->clock->now());
    }
}
