<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\Privacy\AccountDeletionRejected;
use App\Identity\Application\Privacy\CancelAccountDeletion;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RG-090 — pages publiques (le compte est désactivé) : confirmation de la demande, et rétractation depuis le lien signé
 * de l'e-mail E-08 (valable jusqu'à la date d'effacement), mot de passe exigé.
 */
final class AccountDeletionController extends AbstractController
{
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(private readonly CommandBus $commands, private readonly UriSigner $signer, private readonly SettingsProvider $settings)
    {
    }

    #[Route('/compte/suppression', name: 'identity_deletion_requested', methods: ['GET'])]
    public function requested(): Response
    {
        return $this->render('auth/deletion_requested.html.twig', ['grace_days' => $this->settings->int(SettingKey::GdprDeletionGraceDays)]);
    }

    #[Route('/compte/suppression/annuler/{user}', name: 'identity_deletion_cancel', requirements: ['user' => self::UUID], methods: ['GET', 'POST'])]
    public function cancel(Request $request, string $user): Response
    {
        if (!$this->signer->checkRequest($request)) {
            throw new NotFoundHttpException('Lien d’annulation invalide ou expiré.');
        }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('deletion_cancel', $request->request->getString('_token'))) {
                $error = 'Session expirée : réessayez.';
            } else {
                try {
                    $this->commands->dispatch(new CancelAccountDeletion($user, $request->request->getString('password'), $request->getClientIp()));
                    $this->addFlash('success', 'La suppression est annulée : votre compte est de nouveau actif, vous pouvez vous connecter.');

                    return $this->redirectToRoute('identity_login', [], Response::HTTP_SEE_OTHER);
                } catch (AccountDeletionRejected $e) {
                    $error = $e->getMessage();
                }
            }
        }

        return $this->render('auth/deletion_cancel.html.twig', ['error' => $error, 'action' => $request->getUri()], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
