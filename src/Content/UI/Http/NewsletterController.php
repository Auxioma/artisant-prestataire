<?php

declare(strict_types=1);

namespace App\Content\UI\Http;

use App\Content\Application\Newsletter\NewsletterRejected;
use App\Content\Application\Newsletter\SubscribeToNewsletter;
use App\Content\Application\Newsletter\UnsubscribeFromNewsletter;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Security\IpAnonymizer;
use App\Shared\Domain\Identifier\IdGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pied de page « Restez informé » : inscription (formulaire sans JavaScript, retour sur la page d'origine avec un
 * message) et désinscription en un clic, confirmée par un bouton (un lien seul serait suivi par les antivirus de messagerie).
 */
final class NewsletterController extends AbstractController
{
    private const string TOKEN = '[0-9a-f]{64}';

    public function __construct(
        private readonly CommandBus $commands,
        private readonly IpAnonymizer $ipAnonymizer,
        private readonly IdGenerator $ids,
        #[Autowire(service: 'limiter.newsletter')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/newsletter', name: 'content_newsletter_subscribe', methods: ['POST'])]
    public function subscribe(Request $request): Response
    {
        $back = $this->back($request);
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            $this->addFlash('error', 'La page a expiré : merci de renouveler votre inscription.');

            return $this->redirect($back, Response::HTTP_SEE_OTHER);
        }
        if (!$this->limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            $this->addFlash('error', 'Trop de tentatives : merci de réessayer dans une heure.');

            return $this->redirect($back, Response::HTTP_SEE_OTHER);
        }
        try {
            $this->commands->dispatch(new SubscribeToNewsletter($this->ids->next(), $request->request->getString('email'), 'footer', $this->ipAnonymizer->hash($request->getClientIp())));
            $this->addFlash('success', 'Merci ! Votre inscription à la newsletter est bien enregistrée.');
        } catch (NewsletterRejected $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($back, Response::HTTP_SEE_OTHER);
    }

    #[Route('/newsletter/desinscription/{token}', name: 'content_newsletter_unsubscribe', requirements: ['token' => self::TOKEN], methods: ['GET', 'POST'])]
    public function unsubscribe(Request $request, string $token): Response
    {
        $done = false;
        $error = null;
        if ($request->isMethod('POST')) {
            try {
                $this->commands->dispatch(new UnsubscribeFromNewsletter($token));
                $done = true;
            } catch (NewsletterRejected $e) {
                $error = $e->getMessage();
            }
        }
        $response = $this->render('content/newsletter_unsubscribe.html.twig', ['token' => $token, 'done' => $done, 'error' => $error]);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /** Page d'origine si elle est sur ce site, sinon l'accueil (pas de redirection ouverte). */
    private function back(Request $request): string
    {
        $referer = $request->headers->get('referer', '');
        $origin = $request->getSchemeAndHttpHost().'/';

        return \is_string($referer) && str_starts_with($referer, $origin) ? $referer.(str_contains($referer, '#') ? '' : '#newsletter') : $this->generateUrl('public_home').'#newsletter';
    }
}
