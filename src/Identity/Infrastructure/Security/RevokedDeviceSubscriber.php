<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\Port\KnownDevices;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * C07.2 : un appareil déconnecté depuis « Appareils connectés » perd sa session (et sa connexion persistante) à sa
 * requête suivante. Une nouvelle connexion depuis cet appareil le réactive.
 */
final readonly class RevokedDeviceSubscriber implements EventSubscriberInterface
{
    private const int AFTER_FIREWALL = 7;

    public function __construct(private Security $security, private KnownDevices $devices, private UrlGeneratorInterface $urls)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', self::AFTER_FIREWALL]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $device = $request->cookies->getString(LoginSubscriber::DEVICE_COOKIE);
        if (!$event->isMainRequest() || '' === $device) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof SecurityUser || !$this->devices->isRevoked($user->id, hash('sha256', $device))) {
            return;
        }
        $this->security->logout(false);
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('info', 'Cet appareil a été déconnecté depuis votre espace : reconnectez-vous.');
        }
        $event->setResponse(new RedirectResponse($this->urls->generate('identity_login')));
    }
}
