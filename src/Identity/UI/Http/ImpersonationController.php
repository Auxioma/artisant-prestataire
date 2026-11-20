<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Security\ImpersonationContext;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/** ADR-0013 — entrée (traitée par ImpersonationAuthenticator) et fin d'une connexion assistée. */
final class ImpersonationController extends AbstractController
{
    /** Clé de session du jeton du pare-feu « main » (Symfony : `_security_<pare-feu>`). */
    private const string FIREWALL_SESSION_KEY = '_security_main';

    #[Route('/connexion-assistee/{token}', name: 'identity_impersonation_enter', requirements: ['token' => '[A-Za-z0-9_-]{43}'], methods: ['GET'])]
    public function enter(): Response
    {
        // Atteint seulement si l'authentificateur n'a pas pris la main (déjà connecté avec ce ticket, par exemple).
        return $this->redirectToRoute('public_home');
    }

    /** Fin volontaire (bouton du bandeau, POST + CSRF) ou automatique (durée dépassée, GET depuis le garde). */
    #[Route('/connexion-assistee-terminer', name: 'identity_impersonation_end', methods: ['GET', 'POST'])]
    public function end(Request $request, ImpersonationContext $impersonation, TokenStorageInterface $tokens, AuditTrail $audit, IdGenerator $ids, Clock $clock): Response
    {
        $session = $impersonation->current();
        if (null === $session) {
            return $this->redirectToRoute('public_home');
        }
        $expired = !$request->isMethod('POST');
        if (!$expired && !$this->isCsrfTokenValid('impersonation_end', $request->request->getString('_token'))) {
            return $this->redirectToRoute('public_home');
        }
        if ($expired && !$session->expired($clock->now())) {
            return $this->redirectToRoute('public_home');
        }
        $audit->record(new AuditRecord(
            id: $ids->next(),
            occurredAt: $clock->now(),
            actorType: 'admin',
            actorId: $session->adminId,
            action: 'admin.impersonation_ended',
            subjectType: 'user',
            subjectId: $session->userId,
            ip: $request->getClientIp(),
            context: ['reason' => $expired ? 'expired' : 'ended', 'minutes' => intdiv($clock->now()->getTimestamp() - $session->startedAt->getTimestamp(), 60)],
        ));
        // Seule la session du site est fermée : le back-office partage le cookie de session et reste ouvert.
        $tokens->setToken(null);
        $store = $request->getSession();
        $store->remove(self::FIREWALL_SESSION_KEY);
        $store->remove(ImpersonationContext::SESSION_KEY);
        $store->migrate(true);

        return new RedirectResponse($session->returnUrl);
    }
}
