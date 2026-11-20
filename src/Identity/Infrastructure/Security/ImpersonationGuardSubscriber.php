<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Shared\Application\Security\ImpersonationContext;
use App\Shared\Domain\Time\Clock;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * ADR-0013 — pendant une connexion assistée : actions financières et sensibles du compte bloquées (souscription,
 * changement de forfait, résiliation, moyen de paiement, mot de passe, adresse e-mail, appareils, suppression), et
 * fin automatique au-delà de la durée maximale.
 */
final readonly class ImpersonationGuardSubscriber implements EventSubscriberInterface
{
    public const array BLOCKED_ROUTES = [
        'pro_subscribe', 'pro_subscribe_return', 'pro_subscription_change', 'pro_subscription_cancel', 'pro_subscription_resume', 'pro_payment_portal',
        'client_security_password', 'client_security_email', 'client_security_devices', 'client_account_delete',
    ];

    public function __construct(private ImpersonationContext $impersonation, private Clock $clock, private UrlGeneratorInterface $urls)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Après le pare-feu (8) : la session et l'utilisateur sont connus.
        return [KernelEvents::REQUEST => ['onRequest', 4]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $session = $this->impersonation->current();
        $route = $event->getRequest()->attributes->get('_route');
        $route = \is_string($route) ? $route : '';
        if (null === $session || 'identity_impersonation_end' === $route) {
            return;
        }
        if ($session->expired($this->clock->now())) {
            $event->setResponse(new RedirectResponse($this->urls->generate('identity_impersonation_end', ['expiree' => 1])));

            return;
        }
        if (\in_array($route, self::BLOCKED_ROUTES, true)) {
            $flashes = $event->getRequest()->getSession();
            if ($flashes instanceof FlashBagAwareSessionInterface) {
                $flashes->getFlashBag()->add('error', 'Action bloquée pendant une connexion assistée (paiement, abonnement et sécurité du compte).');
            }
            $referer = $event->getRequest()->headers->get('referer');
            $event->setResponse(new RedirectResponse(null !== $referer && str_starts_with($referer, $event->getRequest()->getSchemeAndHttpHost().'/') ? $referer : $this->urls->generate('public_home')));
        }
    }
}
