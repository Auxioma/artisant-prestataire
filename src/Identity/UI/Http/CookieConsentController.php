<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\Command\RecordCookieConsent;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Security\IpAnonymizer;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Exception\InvalidValue;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Preuve du consentement cookies (CDC §3.2). Le choix lui-même est conservé côté navigateur (cookie tm_consent) ;
 * cet endpoint en garde la trace horodatée côté serveur.
 * Protection CSRF : vérification de l'origine (même origine exigée) + JSON obligatoire + limite de débit.
 */
final readonly class CookieConsentController
{
    public const string VISITOR_COOKIE = 'tm_cid';
    private const int MAX_BODY_BYTES = 2048;

    public function __construct(
        private CommandBus $commands,
        private SettingsProvider $settings,
        private IpAnonymizer $ipAnonymizer,
        #[Autowire(service: 'limiter.cookie_consent')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/consentement-cookies', name: 'identity_cookie_consent', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Trop de requêtes'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        if (!$this->isSameOrigin($request)) {
            return new JsonResponse(['error' => 'Origine refusée'], Response::HTTP_FORBIDDEN);
        }
        if ('json' !== $request->getContentTypeFormat() || \strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return new JsonResponse(['error' => 'Requête invalide'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        try {
            $payload = $request->toArray();
            $choices = $payload['choices'] ?? null;
            $source = $payload['source'] ?? null;
            $version = $payload['version'] ?? null;
            if (!\is_array($choices) || !\is_string($source) || !\is_int($version)) {
                throw InvalidValue::for('Consentement', 'format attendu {choices, source, version}');
            }
            $visitorId = $this->visitorId($request);
            /** @var array<string, mixed> $choices */
            $this->commands->dispatch(new RecordCookieConsent(
                visitorId: $visitorId,
                choices: $choices,
                source: $source,
                policyVersion: $version,
                ipHash: $this->ipAnonymizer->hash($request->getClientIp()),
            ));
        } catch (InvalidValue|\JsonException|\Symfony\Component\HttpFoundation\Exception\JsonException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $response->headers->setCookie(Cookie::create(self::VISITOR_COOKIE)
            ->withValue($visitorId)
            ->withExpires(new \DateTimeImmutable(\sprintf('+%d months', $this->settings->int(SettingKey::CookiesConsentMonths))))
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX));

        return $response;
    }

    private function isSameOrigin(Request $request): bool
    {
        $origin = $request->headers->get('Origin');
        if (null !== $origin) {
            return $origin === $request->getSchemeAndHttpHost();
        }

        return 'same-origin' === $request->headers->get('Sec-Fetch-Site');
    }

    private function visitorId(Request $request): string
    {
        $existing = $request->cookies->getString(self::VISITOR_COOKIE);

        return Uuid::isValid($existing) ? strtolower($existing) : Uuid::v7()->toRfc4122();
    }
}
