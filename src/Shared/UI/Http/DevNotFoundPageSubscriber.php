<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Recette en environnement de développement : une adresse inconnue affiche la vraie page 404 (P18) au lieu de la page
 * de débogage de Symfony. Les autres erreurs gardent la trace ; `?_trace=1` la rétablit pour une 404.
 */
#[When(env: 'dev')]
final class DevNotFoundPageSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => 'onRequest'];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->isMainRequest() || !$request->attributes->get('exception') instanceof NotFoundHttpException || $request->query->getBoolean('_trace')) {
            return;
        }
        $request->attributes->set('showException', false);
    }
}
