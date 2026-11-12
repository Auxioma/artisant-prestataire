<?php

declare(strict_types=1);

namespace App\ArtisanProfile\UI\Http;

use App\ArtisanProfile\Application\Completion\ProfileCompletionOf;
use App\ArtisanProfile\Application\Signup\DocumentChecklist;
use App\ArtisanProfile\Application\Signup\DocumentSlot;
use App\ArtisanProfile\Application\Signup\SignupRejected;
use App\ArtisanProfile\Application\Signup\SignupState;
use App\ArtisanProfile\Application\Signup\SubmitSignup;
use App\ArtisanProfile\Application\Signup\UploadLogo;
use App\ArtisanProfile\Domain\Profile\ArtisanProfile;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\ArtisanProfile\Domain\Profile\ProfileCompletion;
use App\ArtisanProfile\Domain\Profile\RejectionReason;
use App\Catalog\Application\Geo\Query\CommunesByInsee;
use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Storage\FileReader;
use App\Shared\Application\Storage\StoredFileNotFound;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A03.7 — profil public express et récapitulatif (Figma 230:12231), puis écran « dossier en cours de vérification »
 * (230:12384). Aperçu de carte honnête : ni note ni avis inventés, badge « Vérifié » seulement après validation.
 */
#[Route('/inscription/pro')]
final class PublicProfileController extends AbstractSignupController
{
    public const string SESSION_COMPLETED = 'artisan.signup_completed';

    #[Route('/profil', name: 'artisan_signup_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request, FileReader $files): Response
    {
        $state = $this->state();
        if (null !== $redirect = $this->guardStep($state, 7)) {
            return $redirect;
        }
        $description = $state->shortDescription ?? '';
        $errors = [];
        $saved = false;

        if ($this->isOversizedPost($request)) {
            $errors['logo'] = $this->logoRejected();
        } elseif ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'artisan_profile');
            $description = $request->request->getString('description');
            try {
                $file = $request->files->get('logo');
                $chosen = $file instanceof UploadedFile && \UPLOAD_ERR_NO_FILE !== $file->getError();
                if ('upload_logo' === $request->request->getString('action') || $chosen) {
                    // Image choisie puis « Finaliser » sans l'avoir envoyée (sans JavaScript) : elle est enregistrée aussi.
                    if (!$file instanceof UploadedFile || !$file->isValid()) {
                        throw new SignupRejected('logo', SignupRejected::FILE_REJECTED);
                    }
                    $this->commands->dispatch(new UploadLogo($this->userId(), $file->getPathname(), $file->getClientOriginalName()));
                    $state = $this->state();
                    $saved = true;
                }
                if ('submit' === $request->request->getString('action')) {
                    $this->commands->dispatch(new SubmitSignup($this->userId(), $description));
                    $request->getSession()->set(self::SESSION_COMPLETED, true);

                    return $this->redirectToRoute('artisan_signup_submitted', status: Response::HTTP_SEE_OTHER);
                }
            } catch (SignupRejected $e) {
                $errors['logo'] = $this->logoRejected();
            } catch (ArtisanRuleViolation $e) {
                $errors['description'] = $e->getMessage();
            }
        }

        $slots = $this->slots();
        $required = array_filter($slots, static fn (DocumentSlot $s): bool => $s->required);

        return $this->render('pro_signup/profile.html.twig', [
            'steps' => self::STEPS,
            'state' => $state,
            'description' => $description,
            'description_max' => ArtisanProfile::SHORT_DESCRIPTION_MAX,
            'logo_ready' => $this->logoReady($state, $files),
            'logo_saved' => $saved,
            'jobs' => $this->jobNames($state),
            'area' => $this->areaSummary($state),
            'location' => $state->hqCity ?? $this->communeName($state->areaCenterInsee),
            'documents_provided' => \count(array_filter($required, static fn (DocumentSlot $s): bool => $s->provided())),
            'documents_required' => \count($required),
            'errors' => $errors,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /** Logo de l'artisan connecté, servi une fois l'analyse antivirus passée (jamais depuis la quarantaine). */
    #[Route('/logo', name: 'artisan_signup_logo', methods: ['GET'])]
    public function logo(Request $request, FileReader $files): StreamedResponse
    {
        $state = $this->state();
        if (null === $state->logoFileId) {
            throw new NotFoundHttpException();
        }
        try {
            $file = $files->available($state->logoFileId);
            $stream = $files->openForActor($file->id, 'user', $this->userId(), $request->getClientIp());
        } catch (StoredFileNotFound) {
            throw new NotFoundHttpException();
        }

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, Response::HTTP_OK, [
            'Content-Type' => $file->type->value,
            'Content-Length' => (string) $file->size,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    #[Route('/dossier-envoye', name: 'artisan_signup_submitted', methods: ['GET'])]
    public function submitted(Request $request, FileReader $files): Response
    {
        $state = $this->state();
        if (!$state->submitted) {
            return $this->redirectToRoute('artisan_signup_resume');
        }
        $missing = array_values(array_filter($this->slots(), static fn (DocumentSlot $s): bool => $s->required && !$s->provided()));

        $completion = $this->queries->ask(new ProfileCompletionOf($this->userId()));
        $reason = null === $state->statusReason ? null : RejectionReason::tryFrom($state->statusReason);

        return $this->render('pro_signup/submitted.html.twig', [
            'state' => $state,
            'completion' => $completion instanceof ProfileCompletion ? $completion : null,
            'photos_target' => $this->settings->int(SettingKey::ProCompletionPhotosTarget),
            'rejection_label' => $reason?->label(),
            'delay_hours' => $this->settings->int(SettingKey::ProVerificationDelayHours),
            'free_credits' => $this->settings->int(SettingKey::CreditsFreeOnSignup),
            'missing' => $missing,
            'has_logo' => null !== $state->logoFileId,
            'track_completed' => true === $request->getSession()->remove(self::SESSION_COMPLETED),
        ]);
    }

    /** @return list<DocumentSlot> */
    private function slots(): array
    {
        $slots = $this->queries->ask(new DocumentChecklist($this->userId()));

        return array_values(array_filter(\is_array($slots) ? $slots : [], static fn (mixed $s): bool => $s instanceof DocumentSlot));
    }

    private function logoReady(SignupState $state, FileReader $files): bool
    {
        if (null === $state->logoFileId) {
            return false;
        }
        try {
            $files->available($state->logoFileId);

            return true;
        } catch (StoredFileNotFound) {
            return false;
        }
    }

    private function logoRejected(): string
    {
        return \sprintf('Image non acceptée (JPG, PNG ou WebP — %d Mo max).', $this->settings->int(SettingKey::RequestsMaxFileMb));
    }

    /** @return list<string> métier principal en premier */
    private function jobNames(SignupState $state): array
    {
        $entries = [] === $state->jobIds ? [] : $this->queries->ask(new JobIndexEntries($state->jobIds));
        $names = [];
        foreach (\is_array($entries) ? $entries : [] as $entry) {
            if ($entry instanceof JobIndexEntry) {
                $names[$entry->id] = $entry->name.($entry->id === $state->mainJobId ? ' (principal)' : '');
            }
        }
        uksort($names, static fn (string $a, string $b): int => ($b === $state->mainJobId) <=> ($a === $state->mainJobId));

        return array_values($names);
    }

    private function areaSummary(SignupState $state): string
    {
        if ('radius' === $state->areaMode) {
            return \sprintf('%d km autour de %s', $state->areaRadiusKm, $this->communeName($state->areaCenterInsee) ?? '—');
        }
        $parts = [];
        if ([] !== $state->areaDepartments) {
            $parts[] = \sprintf('%d département%s', \count($state->areaDepartments), \count($state->areaDepartments) > 1 ? 's' : '');
        }
        if ([] !== $state->areaCommunes) {
            $parts[] = \sprintf('%d commune%s', \count($state->areaCommunes), \count($state->areaCommunes) > 1 ? 's' : '');
        }

        return implode(' et ', $parts) ?: '—';
    }

    private function communeName(?string $insee): ?string
    {
        if (null === $insee) {
            return null;
        }
        $found = $this->queries->ask(new CommunesByInsee([$insee]));
        $commune = \is_array($found) ? ($found[0] ?? null) : null;

        return $commune instanceof CommuneSummary ? $commune->name : null;
    }
}
