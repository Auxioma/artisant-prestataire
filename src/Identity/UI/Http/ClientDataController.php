<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\Privacy\DataExportRejected;
use App\Identity\Application\Privacy\DataExportView;
use App\Identity\Application\Privacy\LatestDataExport;
use App\Identity\Application\Privacy\RequestDataExport;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Application\Storage\FileReader;
use App\Shared\Application\Storage\StoredFileNotFound;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * C07.4 « Mes données » (onglet de C07, pas de maquette dédiée — P-125) : demander l'archive (JSON + PDF lisible,
 * générée hors requête, E-07), la télécharger pendant 7 jours.
 */
#[Route('/espace-client/donnees')]
final class ClientDataController extends AbstractController
{
    private const string CSRF = 'client_data';

    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
        private readonly CurrentUser $user,
        private readonly IdGenerator $ids,
        private readonly Clock $clock,
    ) {
    }

    #[Route('', name: 'client_data', methods: ['GET'])]
    public function index(): Response
    {
        $export = $this->queries->ask(new LatestDataExport($this->userId()));

        return $this->render('client/profile/data.html.twig', [
            'export' => $export instanceof DataExportView ? $export : null,
            'now' => $this->clock->now(),
            'csrf_id' => self::CSRF,
        ]);
    }

    #[Route('/export', name: 'client_data_export', methods: ['POST'])]
    public function request(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }
        try {
            $this->commands->dispatch(new RequestDataExport($this->ids->next(), $this->userId(), $request->getClientIp()));
            $this->addFlash('success', 'Votre archive est en préparation : vous recevrez un e-mail dès qu’elle sera prête (sous 24 heures).');
        } catch (DataExportRejected $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('client_data', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/export/telecharger', name: 'client_data_download', methods: ['GET'])]
    public function download(Request $request, FileReader $files): StreamedResponse
    {
        $userId = $this->userId();
        $export = $this->queries->ask(new LatestDataExport($userId));
        if (!$export instanceof DataExportView || !$export->downloadable($this->clock->now()) || null === $export->fileId) {
            throw new NotFoundHttpException();
        }
        try {
            $file = $files->available($export->fileId);
            $stream = $files->openForActor($file->id, 'user', $userId, $request->getClientIp());
        } catch (StoredFileNotFound) {
            throw new NotFoundHttpException();
        }

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, Response::HTTP_OK, [
            'Content-Type' => $file->type->value,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'mes-donnees-trouvemoi.zip'),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function userId(): string
    {
        return $this->user->id() ?? throw new AccessDeniedHttpException();
    }
}
