<?php

declare(strict_types=1);

namespace App\Administration\UI\Http;

use App\Administration\Application\Port\CurrentAdmin;
use App\Administration\Application\Port\Reauthentication;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/** Connexion (mot de passe puis code TOTP), déconnexion et ré-authentification du back-office (ADR-0013). */
final class AdminSecurityController extends AbstractController
{
    private const int REAUTH_MAX_FAILURES = 5;
    private const string REAUTH_FAILURES = 'admin.reauth_failures';

    #[Route('/admin/connexion', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(Request $request, AuthenticationUtils $authentication, CurrentAdmin $admin): Response
    {
        if (null !== $admin->id()) {
            return $this->redirectToRoute('admin_dashboard');
        }
        $error = $authentication->getLastAuthenticationError();

        return $this->render('admin/security/login.html.twig', [
            'last_username' => $authentication->getLastUsername(),
            'error' => null === $error ? null : ($error instanceof \Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException
                ? 'Trop de tentatives : réessayez dans quelques minutes.'
                : 'Adresse e-mail ou mot de passe incorrect.'),
            'expired' => $request->query->has('expiree'),
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNAUTHORIZED));
    }

    #[Route('/admin/deconnexion', name: 'admin_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Interceptée par le pare-feu admin.');
    }

    #[Route('/admin/reauthentification', name: 'admin_reauth', methods: ['GET', 'POST'])]
    public function reauthenticate(Request $request, Reauthentication $reauth, CurrentAdmin $admin): Response
    {
        if (null === $admin->id()) {
            return $this->redirectToRoute('admin_login');
        }
        $target = $request->query->getString('cible');
        $target = 1 === preg_match('#^/admin(/[A-Za-z0-9/_?=&%-]*)?$#', $target) ? $target : $this->generateUrl('admin_dashboard');
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_reauth', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $session = $request->getSession();
            if ($reauth->attempt($request->request->getString('password'), $request->request->getString('code'))) {
                $session->remove(self::REAUTH_FAILURES);

                return $this->redirect($target, Response::HTTP_SEE_OTHER);
            }
            $previous = $session->get(self::REAUTH_FAILURES, 0);
            $failures = (\is_int($previous) ? $previous : 0) + 1;
            if ($failures >= self::REAUTH_MAX_FAILURES) {
                $session->invalidate();

                return $this->redirectToRoute('admin_login', status: Response::HTTP_SEE_OTHER);
            }
            $session->set(self::REAUTH_FAILURES, $failures);
            $error = 'Mot de passe ou code incorrect.';
        }

        return $this->render('admin/security/reauth.html.twig', ['target' => $target, 'error' => $error], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
