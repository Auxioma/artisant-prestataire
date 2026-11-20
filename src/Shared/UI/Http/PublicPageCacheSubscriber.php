<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CDC §8 (TTFB < 600 ms « cache CDN ») : les pages publiques vues par un visiteur sans session deviennent cachables
 * par le CDN (`s-maxage`, durée `site.public_cache_seconds`) ; le navigateur revalide toujours (`max-age=0`).
 * Exclus : toute requête portant un cookie de session (connecté, usurpation d'identité), toute réponse qui dépose un
 * cookie ou n'est pas un 200, les méthodes autres que GET / HEAD.
 *
 * S'exécute après l'écouteur de session de Symfony, qui rend privée toute page ayant lu le jeton de sécurité
 * (`app.user`, `is_granted`) : sans cookie de session à l'arrivée, ce jeton est forcément vide et la page identique
 * pour tous. Si une session a réellement été ouverte pendant la requête, son cookie figure dans la réponse et celle-ci
 * reste privée (P-155).
 */
final readonly class PublicPageCacheSubscriber implements EventSubscriberInterface
{
    /** Pages publiques sans donnée propre au visiteur (formulaires à jeton, tunnel et API exclus). */
    public const array ROUTES = [
        'public_home', 'public_search', 'public_artisan_profile',
        'search_directory', 'search_directory_page',
        'catalog_categories', 'catalog_category', 'catalog_job', 'catalog_department', 'catalog_city', 'catalog_job_city',
        'content_how_it_works', 'content_pricing', 'content_faq', 'content_faq_topic', 'content_faq_question',
        'content_page', 'content_accessibility',
        'identity_legal_document', 'identity_legal_versions', 'identity_legal_version',
    ];

    private const int DISABLED = 0;

    /** Après `SessionListener::onKernelResponse` (-1000). */
    private const int PRIORITY = -1010;

    public function __construct(private SettingsProvider $settings)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', self::PRIORITY]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        if (!$event->isMainRequest() || !$this->cacheable($request, $response)) {
            return;
        }
        $seconds = $this->settings->int(SettingKey::SitePublicCacheSeconds);
        if ($seconds <= self::DISABLED) {
            return;
        }
        $response->headers->removeCacheControlDirective('must-revalidate');
        $response->setPublic();
        $response->setMaxAge(self::DISABLED);
        $response->setSharedMaxAge($seconds);
        $response->setVary('Cookie', false);
    }

    private function cacheable(Request $request, Response $response): bool
    {
        return \in_array($request->getMethod(), [Request::METHOD_GET, Request::METHOD_HEAD], true)
            && \in_array($request->attributes->get('_route'), self::ROUTES, true)
            && !$request->hasPreviousSession()
            && Response::HTTP_OK === $response->getStatusCode()
            && [] === $response->headers->getCookies()
            && !$response->headers->hasCacheControlDirective('no-store')
            && !$response->headers->hasCacheControlDirective('no-cache');
    }
}
