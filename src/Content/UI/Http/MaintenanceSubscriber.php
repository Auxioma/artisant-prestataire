<?php

declare(strict_types=1);

namespace App\Content\UI\Http;

use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * P18 — mode maintenance (`site.maintenance`) : le site public répond 503 avec Retry-After ; le back-office, la sonde
 * de santé et les ressources statiques restent accessibles pour piloter la remise en service.
 */
final readonly class MaintenanceSubscriber implements EventSubscriberInterface
{
    private const array OPEN_PREFIXES = ['/admin', '/healthz', '/assets', '/_wdt', '/_profiler'];
    private const int SECONDS_PER_MINUTE = 60;
    private const int BEFORE_ROUTING = 64;

    public function __construct(private SettingsProvider $settings, private Environment $twig)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', self::BEFORE_ROUTING]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->settings->bool(SettingKey::SiteMaintenance)) {
            return;
        }
        $path = $event->getRequest()->getPathInfo();
        foreach (self::OPEN_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }
        $minutes = $this->settings->int(SettingKey::SiteMaintenanceRetryMinutes);
        $event->setResponse(new Response($this->twig->render('bundles/TwigBundle/Exception/maintenance.html.twig', ['minutes' => $minutes]), Response::HTTP_SERVICE_UNAVAILABLE, [
            'Retry-After' => (string) ($minutes * self::SECONDS_PER_MINUTE),
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]));
    }
}
