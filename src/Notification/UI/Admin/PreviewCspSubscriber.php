<?php

declare(strict_types=1);

namespace App\Notification\UI\Admin;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * BO11.1 — l'aperçu d'un e-mail (styles en ligne, comme dans une messagerie) s'affiche dans un cadre du back-office
 * avec une CSP dédiée : aucun script (sandbox), styles en ligne et images https seulement, encadrement par la même
 * origine. Exécuté après l'écouteur CSP de Nelmio.
 */
final class PreviewCspSubscriber implements EventSubscriberInterface
{
    private const string ROUTE = 'admin_notifications_preview';
    private const int AFTER_CSP = -10;
    private const string POLICY = "default-src 'none'; style-src 'unsafe-inline'; img-src https: data:; frame-ancestors 'self'; sandbox";

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', self::AFTER_CSP]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || self::ROUTE !== $event->getRequest()->attributes->get('_route')) {
            return;
        }
        $headers = $event->getResponse()->headers;
        $headers->set('Content-Security-Policy', self::POLICY);
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
    }
}
