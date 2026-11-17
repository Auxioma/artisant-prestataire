<?php

declare(strict_types=1);

namespace App\Moderation\UI\Http;

use App\Moderation\Application\Report\ReportReason;
use App\Moderation\Application\Report\ReportRejected;
use App\Moderation\Application\Report\SubmitReport;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Moderation\ReportableContent;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Domain\Identifier\IdGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Signaler » (P10.9 avis, P10 profil, PR03 demande) : utilisateur connecté (les visiteurs passent par la connexion),
 * motif normalisé et précisions ; le signalement rejoint la file BO05.2. Pas de maquette dédiée : P-127.
 */
final class ReportController extends AbstractController
{
    private const string CSRF = 'report';
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /** @param iterable<ReportableContent> $reportables */
    public function __construct(
        private readonly CommandBus $commands,
        private readonly CurrentUser $user,
        private readonly IdGenerator $ids,
        #[AutowireIterator('app.reportable_content')]
        private readonly iterable $reportables,
    ) {
    }

    #[Route('/signaler/{type}/{id}', name: 'moderation_report', requirements: ['type' => 'avis|profil|demande', 'id' => self::UUID], methods: ['GET', 'POST'])]
    public function report(Request $request, string $type, string $id): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED');
        $userId = $this->user->id() ?? throw new AccessDeniedHttpException();
        $reportable = null;
        foreach ($this->reportables as $candidate) {
            \assert($candidate instanceof ReportableContent);
            $reportable = $candidate->type() === $type ? $candidate : $reportable;
        }
        if (null === $reportable) {
            throw new NotFoundHttpException();
        }
        $content = $reportable->describe($id, $userId) ?? throw new NotFoundHttpException();
        $back = $request->query->getString('retour');
        $back = str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : $this->generateUrl('public_home');
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
                throw new AccessDeniedHttpException();
            }
            try {
                $this->commands->dispatch(new SubmitReport($this->ids->next(), $userId, $type, $id, $request->request->getString('reason'), $request->request->getString('details')));
                $this->addFlash('success', 'Merci : votre signalement a été transmis à l’équipe de modération (délai cible : 48 h). Vous recevrez sa réponse par e-mail.');

                return $this->redirect($back, Response::HTTP_SEE_OTHER);
            } catch (ReportRejected $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('moderation/report.html.twig', [
            'type' => $type,
            'label' => $reportable->label(),
            'content' => $content,
            'reasons' => ReportReason::forType($type),
            'selected' => $request->request->getString('reason'),
            'details' => $request->request->getString('details'),
            'error' => $error,
            'back' => $back,
            'csrf_id' => self::CSRF,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
