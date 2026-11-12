<?php

declare(strict_types=1);

namespace App\Content\UI\Admin;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * BO10.1 — l'aperçu de l'accueil s'affiche dans les cadres desktop / mobile du back-office : pour ce seul rendu, la CSP
 * autorise l'encadrement par la même origine (`frame-ancestors 'self'`, comme X-Frame-Options SAMEORIGIN).
 * Exécuté après l'écouteur CSP de Nelmio (priorité 0).
 */
final class PreviewFramingSubscriber implements EventSubscriberInterface
{
    private const string ROUTE = 'admin_cms_preview_render';
    private const int AFTER_CSP = -10;

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
        $policy = $headers->get('Content-Security-Policy');
        if (\is_string($policy)) {
            $headers->set('Content-Security-Policy', str_replace("frame-ancestors 'none'", "frame-ancestors 'self'", $policy));
        }
    }
}
