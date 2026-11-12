<?php

declare(strict_types=1);

namespace App\ArtisanProfile\UI\Admin;

use App\ArtisanProfile\Application\Verification\AdminDocumentFile;
use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\ArtisanProfile\Application\Verification\DossierView;
use App\ArtisanProfile\Application\Verification\QueueItem;
use App\ArtisanProfile\Application\Verification\RejectArtisan;
use App\ArtisanProfile\Application\Verification\RequestArtisanInfo;
use App\ArtisanProfile\Application\Verification\ReviewDocument;
use App\ArtisanProfile\Application\Verification\VerificationDossier;
use App\ArtisanProfile\Application\Verification\VerificationQueue;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\ArtisanProfile\Domain\Profile\RejectionReason;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentAdministrator;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Application\Storage\FileReader;
use App\Shared\Application\Storage\StoredFileNotFound;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO03.2 (Figma 186:1324) — file de vérification des artisans : dossiers en attente, rappel Sirene, contrôles
 * automatiques, visionneuse côte à côte des justificatifs (chaque consultation journalisée), contrôle pièce par pièce
 * (E-24), décision horodatée avec l'administrateur (RG-130) : valider (E-21), demander un complément ou refuser (E-22).
 */
#[Route('/admin/artisans')]
final class VerificationController extends AbstractController
{
    private const int VIEWER_SLOTS = 2;

    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
        private readonly CurrentAdministrator $admin,
    ) {
    }

    /** BO03.1 (liste complète) : T-213 ; d'ici là, la rubrique ouvre la file de vérification. */
    #[Route('', name: 'admin_artisans', methods: ['GET'])]
    #[IsGranted('admin.artisans.view')]
    public function index(): Response
    {
        return $this->redirectToRoute('admin_artisans_queue');
    }

    #[Route('/a-verifier', name: 'admin_artisans_queue', methods: ['GET'])]
    #[IsGranted('admin.artisans.view')]
    public function queue(Request $request, SettingsProvider $settings): Response
    {
        $items = $this->queries->ask(new VerificationQueue());
        $items = array_values(array_filter(\is_array($items) ? $items : [], static fn (mixed $i): bool => $i instanceof QueueItem));
        $selected = $request->query->getString('dossier') ?: ($items[0]->profileId ?? '');
        $dossier = '' === $selected ? null : $this->queries->ask(new VerificationDossier($selected));
        $dossier = $dossier instanceof DossierView ? $dossier : null;
        $viewed = \array_slice(array_values(array_filter($request->query->all('voir'), static fn (mixed $v): bool => \is_string($v) && 1 === preg_match('/^[0-9a-f-]{36}$/', $v))), 0, self::VIEWER_SLOTS);

        return $this->render('admin/artisans/queue.html.twig', [
            'items' => $items,
            'dossier' => $dossier,
            'viewed' => $viewed,
            'reasons' => RejectionReason::cases(),
            'delay_hours' => $settings->int(SettingKey::ProVerificationDelayHours),
        ], new Response(status: '' !== $selected && null === $dossier ? Response::HTTP_NOT_FOUND : Response::HTTP_OK));
    }

    #[Route('/{id}/decision', name: 'admin_artisans_decide', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    #[IsGranted('admin.artisans.edit')]
    public function decide(Request $request, string $id): Response
    {
        $this->assertCsrf($request, 'admin_artisan_decision_'.$id);
        $admin = $this->adminId();
        $message = trim($request->request->getString('message'));
        try {
            $this->commands->dispatch(match ($request->request->getString('action')) {
                'approve' => new ApproveArtisan($id, $admin),
                'more_info' => new RequestArtisanInfo($id, $admin, $message),
                'reject' => new RejectArtisan($id, $admin, $request->request->getString('reason'), '' === $message ? null : $message),
                default => throw new AccessDeniedHttpException(),
            });
            $this->addFlash('success', match ($request->request->getString('action')) {
                'approve' => ['title' => 'Dossier validé', 'message' => 'Badge « Vérifié » attribué ; l’artisan est prévenu par e-mail.'],
                'more_info' => ['title' => 'Complément demandé', 'message' => 'L’artisan est prévenu par e-mail.'],
                default => ['title' => 'Dossier refusé', 'message' => 'L’artisan est prévenu par e-mail avec le motif.'],
            });

            return $this->redirectToRoute('admin_artisans_queue', status: Response::HTTP_SEE_OTHER);
        } catch (ArtisanRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_artisans_queue', ['dossier' => $id], Response::HTTP_SEE_OTHER);
        }
    }

    #[Route('/{id}/pieces/{document}/controle', name: 'admin_artisans_review', requirements: ['id' => '[0-9a-f-]{36}', 'document' => '[0-9a-f-]{36}'], methods: ['POST'])]
    #[IsGranted('admin.artisans.edit')]
    public function review(Request $request, string $id, string $document): Response
    {
        $this->assertCsrf($request, 'admin_artisan_review_'.$document);
        $decision = $request->request->getString('decision');
        try {
            $this->commands->dispatch(new ReviewDocument($id, $document, $this->adminId(), $decision, trim($request->request->getString('reason'))));
            $this->addFlash('success', 'approve' === $decision ? 'Justificatif validé.' : 'Justificatif refusé : l’artisan est invité à le redéposer.');
        } catch (ArtisanRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_artisans_queue', ['dossier' => $id, 'voir' => [$document]], Response::HTTP_SEE_OTHER);
    }

    /** Consultation d'un justificatif : déchiffré à la volée, jamais mis en cache, journalisée (qui, quoi, quand, IP). */
    #[Route('/{id}/pieces/{document}', name: 'admin_artisans_document', requirements: ['id' => '[0-9a-f-]{36}', 'document' => '[0-9a-f-]{36}'], methods: ['GET'])]
    #[IsGranted('admin.artisans.view')]
    public function document(Request $request, string $id, string $document, FileReader $files): StreamedResponse
    {
        $fileId = $this->queries->ask(new AdminDocumentFile($id, $document));
        if (!\is_string($fileId)) {
            throw new NotFoundHttpException();
        }
        try {
            $file = $files->available($fileId);
            $stream = $files->openForActor($file->id, 'admin', $this->adminId(), $request->getClientIp());
        } catch (StoredFileNotFound) {
            throw new NotFoundHttpException();
        }
        $pdf = 'application/pdf' === $file->type->value;

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, Response::HTTP_OK, [
            'Content-Type' => $file->type->value,
            'Content-Length' => (string) $file->size,
            'Content-Disposition' => 'inline; filename="'.$file->downloadName().'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            // PDF assaini (aucun script, ADR-0008) : la visionneuse du navigateur refuse un PDF « sandbox » ; image : isolée.
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'self'".($pdf ? '' : '; sandbox'),
        ]);
    }

    private function adminId(): string
    {
        return $this->admin->id() ?? throw new AccessDeniedHttpException();
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
    }
}
