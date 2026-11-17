<?php

declare(strict_types=1);

namespace App\ArtisanProfile\UI\Http;

use App\ArtisanProfile\Application\Signup\CompleteDocumentsStep;
use App\ArtisanProfile\Application\Signup\DocumentChecklist;
use App\ArtisanProfile\Application\Signup\DocumentSlot;
use App\ArtisanProfile\Application\Signup\SignupRejected;
use App\ArtisanProfile\Application\Signup\UploadDocument;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Storage\RejectedUpload;
use App\Shared\Domain\Identifier\IdGenerator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A03.6 — justificatifs (Figma 230:12070) : dépôt par pièce attendue, pipeline ADR-0008 (chiffrement, antivirus),
 * remplacement possible, « Terminer plus tard » (dossier envoyé, vérification en attente des pièces).
 */
#[Route('/inscription/pro')]
final class DocumentsController extends AbstractSignupController
{
    #[Route('/justificatifs', name: 'artisan_signup_documents', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, IdGenerator $ids): Response
    {
        $state = $this->state();
        if (null !== $redirect = $this->guardStep($state, 6)) {
            return $redirect;
        }
        $rejected = \sprintf('Fichier non accepté (PDF, JPG, PNG — %d Mo max).', $this->settings->int(SettingKey::RequestsMaxFileMb));
        $error = null;
        $slot = $request->query->getString('piece');

        if ($this->isOversizedPost($request)) {
            $error = $rejected;
        } elseif ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'artisan_documents');
            $action = $request->request->getString('action');
            $slot = $request->request->getString('slot');
            try {
                if ('upload' === $action) {
                    $file = self::uploaded($request, 'photo') ?? self::uploaded($request, 'document');
                    if (null === $file) {
                        throw new SignupRejected('file', SignupRejected::FILE_REJECTED, '' === $slot ? 'Choisissez le type de justificatif.' : 'Choisissez un fichier.');
                    }
                    if (!$file->isValid()) {
                        throw new SignupRejected('file', SignupRejected::FILE_REJECTED);
                    }
                    $this->commands->dispatch(new UploadDocument($ids->next(), $this->userId(), $slot, $file->getPathname(), $file->getClientOriginalName()));
                    $this->addFlash('success', 'Justificatif ajouté : il est en cours d’analyse.');

                    return $this->redirectToRoute('artisan_signup_documents', status: Response::HTTP_SEE_OTHER);
                }
                if (\in_array($action, ['continue', 'later'], true) && !$state->isOpen()) {
                    return $this->redirectToRoute('artisan_signup_submitted', status: Response::HTTP_SEE_OTHER);
                }
                if (\in_array($action, ['continue', 'later'], true)) {
                    $this->commands->dispatch(new CompleteDocumentsStep($this->userId(), 'later' === $action));

                    return $this->redirectToRoute('artisan_signup_profile', status: Response::HTTP_SEE_OTHER);
                }
            } catch (SignupRejected $e) {
                $error = SignupRejected::FILE_REJECTED === $e->reason && \in_array($e->getMessage(), [SignupRejected::FILE_REJECTED, RejectedUpload::EMPTY, RejectedUpload::TOO_LARGE, RejectedUpload::TYPE_NOT_ALLOWED, RejectedUpload::UNREADABLE, RejectedUpload::IMAGE_TOO_LARGE, RejectedUpload::PDF_ACTIVE_CONTENT, RejectedUpload::PDF_ENCRYPTED], true)
                    ? $rejected.self::detail($e->getMessage())
                    : $e->getMessage();
            } catch (ArtisanRuleViolation $e) {
                $error = $e->getMessage();
            }
        }

        $slots = $this->queries->ask(new DocumentChecklist($this->userId()));
        $slots = array_values(array_filter(\is_array($slots) ? $slots : [], static fn (mixed $s): bool => $s instanceof DocumentSlot));

        return $this->render('pro_signup/documents.html.twig', [
            'steps' => self::STEPS,
            'slots' => $slots,
            'selected_slot' => $slot,
            'open' => $state->isOpen(),
            'max_mb' => $this->settings->int(SettingKey::RequestsMaxFileMb),
            'error' => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private static function uploaded(Request $request, string $field): ?UploadedFile
    {
        $file = $request->files->get($field);

        return $file instanceof UploadedFile && \UPLOAD_ERR_NO_FILE !== $file->getError() ? $file : null;
    }

    /** Précision utile à l'artisan, sans détail technique. */
    private static function detail(string $reason): string
    {
        return match ($reason) {
            RejectedUpload::PDF_ACTIVE_CONTENT => ' Ce PDF contient des éléments actifs (scripts) : exportez-le à nouveau ou scannez le document.',
            RejectedUpload::PDF_ENCRYPTED => ' Ce PDF est protégé par un mot de passe.',
            RejectedUpload::IMAGE_TOO_LARGE => ' Image trop grande : réduisez sa résolution.',
            default => '',
        };
    }
}
