<?php

declare(strict_types=1);

namespace App\Billing\UI\Http;

use App\Billing\Application\Webhook\HandleStripeEvent;
use App\Billing\Application\Webhook\InvalidWebhook;
use App\Billing\Application\Webhook\ProcessedEvents;
use App\Billing\Application\Webhook\WebhookVerifier;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Time\Clock;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ADR-0010 — `POST /webhooks/stripe` : corps brut limité à 256 Ko, débit limité, signature obligatoire. L'événement est
 * enregistré une seule fois puis traité de façon asynchrone ; la réponse 200 part tout de suite (Stripe réessaie sinon).
 * Aucune session, aucun CSRF : l'authenticité tient à la signature.
 */
#[AsController]
final readonly class StripeWebhookController
{
    private const int MAX_BYTES = 262144;

    public function __construct(
        private WebhookVerifier $verifier,
        private ProcessedEvents $events,
        private CommandBus $commands,
        private Clock $clock,
        #[Target('stripe_webhook.limiter')]
        private RateLimiterFactoryInterface $limiter,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/webhooks/stripe', name: 'billing_stripe_webhook', methods: ['POST'], stateless: true)]
    public function __invoke(Request $request): Response
    {
        if (!$this->limiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'trop de requêtes'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $payload = (string) $request->getContent();
        if ('' === $payload || \strlen($payload) > self::MAX_BYTES) {
            return new JsonResponse(['error' => 'corps invalide'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $event = $this->verifier->verify($payload, (string) $request->headers->get('Stripe-Signature', ''));
        } catch (InvalidWebhook $e) {
            $this->logger->warning('Webhook Stripe refusé : signature invalide.', ['reason' => $e->getMessage(), 'ip' => $request->getClientIp()]);

            return new JsonResponse(['error' => 'signature invalide'], Response::HTTP_BAD_REQUEST);
        }
        if ($this->events->record($event, hash('sha256', $payload), $this->clock->now())) {
            $this->commands->dispatch(new HandleStripeEvent($event->id));
        }

        return new JsonResponse(['received' => true]);
    }
}
