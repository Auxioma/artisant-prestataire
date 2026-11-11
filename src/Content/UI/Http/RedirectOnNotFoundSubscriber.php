<?php

declare(strict_types=1);

namespace App\Content\UI\Http;

use App\Content\Application\Port\RedirectStore;
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
    public function __construct(private RedirectStore $redirects)
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
            return;
        }
        $query = $request->getQueryString();
        $event->setResponse(new RedirectResponse($request->getBaseUrl().$redirect['target'].(null !== $query ? '?'.$query : ''), $redirect['status']));
    }
}
