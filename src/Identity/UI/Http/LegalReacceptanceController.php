<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\Legal\AcceptLegalUpdates;
use App\Identity\Application\Legal\LegalDocumentVersion;
use App\Identity\Application\Legal\PendingLegalAcceptances;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Application\Security\IpAnonymizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * RG-002 — modale bloquante de ré-acceptation : tant qu'une version « substantielle » des CGU (ou des CGV
 * professionnelles) n'est pas acceptée, l'utilisateur connecté est ramené ici (LegalReacceptanceSubscriber).
 */
final class LegalReacceptanceController extends AbstractController
{
    public const string SESSION_TARGET = 'legal.reaccept_target';

    public function __construct(private readonly QueryBus $queries, private readonly CommandBus $commands, private readonly IpAnonymizer $ipAnonymizer, private readonly CurrentUser $currentUser)
    {
    }

    #[Route('/conditions/mise-a-jour', name: 'identity_legal_reaccept', methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function __invoke(Request $request): Response
    {
        $userId = $this->currentUser->id();
        if (null === $userId) {
            return $this->redirectToRoute('public_home');
        }
        $pending = $this->queries->ask(new PendingLegalAcceptances($userId));
        $pending = array_values(array_filter(\is_array($pending) ? $pending : [], static fn (mixed $d): bool => $d instanceof LegalDocumentVersion));
        $target = $request->getSession()->get(self::SESSION_TARGET);
        $target = \is_string($target) && str_starts_with($target, '/') && !str_starts_with($target, '//') ? $target : $this->generateUrl('public_home');
        if ([] === $pending) {
            $request->getSession()->remove(self::SESSION_TARGET);

            return $this->redirect($target);
        }

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
                $error = 'La page a expiré : merci de réessayer.';
            } elseif (!$request->request->getBoolean('accept')) {
                $error = 'Merci de cocher la case pour accepter les nouvelles conditions.';
            } else {
                $this->commands->dispatch(new AcceptLegalUpdates($userId, $this->ipAnonymizer->hash($request->getClientIp())));
                $request->getSession()->remove(self::SESSION_TARGET);
                $request->getSession()->remove(LegalReacceptanceSubscriber::SESSION_CHECKED);
                $this->addFlash('success', 'Merci, les nouvelles conditions sont acceptées.');

                return $this->redirect($target, Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('legal/reaccept.html.twig', ['documents' => $pending, 'error' => $error], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
