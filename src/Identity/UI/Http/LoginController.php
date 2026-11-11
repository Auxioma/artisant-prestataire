<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\Port\LoginProtection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * A04 — formulaire de connexion (Figma 15:3). Le POST est traité par l'authentificateur form_login du pare-feu ;
 * RG-045 (captcha, blocage) est appliqué par LoginSubscriber. Message d'échec unique (anti-énumération).
 */
final class LoginController extends AbstractController
{
    public const string GENERIC_ERROR = 'Email ou mot de passe incorrect.';

    public function __construct(
        private readonly AuthenticationUtils $authentication,
        private readonly LoginProtection $protection,
    ) {
    }

    #[Route('/connexion', name: 'identity_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('public_home');
        }
        $error = $this->authentication->getLastAuthenticationError();
        $message = match (true) {
            null === $error => null,
            // Messages choisis par l'application (blocage RG-045, captcha, compte inaccessible après mot de passe correct)
            $error instanceof CustomUserMessageAuthenticationException, $error instanceof CustomUserMessageAccountStatusException => $error->getMessageKey(),
            default => self::GENERIC_ERROR,
        };
        $lastUsername = $this->authentication->getLastUsername();

        return $this->render('auth/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $message,
            'captcha' => $this->protection->captchaRequired($lastUsername, $request->getClientIp()),
            'target_path' => $this->protection->safeTargetPath($request->query->getString('redirect') ?: null),
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /** Le pare-feu intercepte cette route (configuration security.yaml) : ce corps n'est jamais exécuté. */
    #[Route('/deconnexion', name: 'identity_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Déconnexion gérée par le pare-feu.');
    }
}
