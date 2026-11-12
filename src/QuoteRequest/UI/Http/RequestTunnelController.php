<?php

declare(strict_types=1);

namespace App\QuoteRequest\UI\Http;

use App\ArtisanProfile\Application\PublicProfile\PublicArtisanProfile;
use App\ArtisanProfile\Application\PublicProfile\PublicProfileView;
use App\Catalog\Application\Query\JobBySlug;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Catalog\Application\Questionnaire\JobQuestionnaire;
use App\Catalog\Application\Questionnaire\JobQuestionnaireView;
use App\QuoteRequest\Application\Draft\AddDraftAttachment;
use App\QuoteRequest\Application\Draft\CurrentRequestDraft;
use App\QuoteRequest\Application\Draft\DraftOwner;
use App\QuoteRequest\Application\Draft\DraftRejected;
use App\QuoteRequest\Application\Draft\RemoveDraftAttachment;
use App\QuoteRequest\Application\Draft\RequestDraftEditor;
use App\QuoteRequest\Application\Draft\RequestDraftView;
use App\QuoteRequest\Application\Draft\SaveDraftStep;
use App\QuoteRequest\Application\Draft\StartRequestDraft;
use App\QuoteRequest\Domain\Request\BudgetRange;
use App\QuoteRequest\Domain\Request\ProjectType;
use App\QuoteRequest\Domain\Request\RequestDetails;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Application\Storage\FileReader;
use App\Shared\Application\Storage\StoredFileNotFound;
use App\Shared\Domain\Identifier\IdGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P11 (/demande-de-devis) — tunnel de dépôt, étapes 1 à 3 (T-402a) : besoin, détails (questionnaire du métier), photos.
 * Formulaires classiques (fonctionnent sans JavaScript) ; brouillon enregistré à chaque étape et automatiquement toutes
 * les 10 s (RG-060), rattaché au compte connecté ou, pour un visiteur, à un jeton aléatoire en cookie.
 */
#[Route('/demande-de-devis')]
final class RequestTunnelController extends AbstractController
{
    public const array STEPS = ['Votre besoin', 'Détails', 'Photos', 'Lieu & délai', 'Coordonnées', 'Récapitulatif'];
    public const string COOKIE = 'tm_demande';
    private const string CSRF = 'request_tunnel';
    private const int TOKEN_BYTES = 32;
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /** Attribut de requête portant le cookie à émettre (jamais une propriété : le contrôleur est un service partagé). */
    private const string NEW_COOKIE = '_tm_new_draft_cookie';

    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
        private readonly CurrentUser $currentUser,
        private readonly SettingsProvider $settings,
        private readonly IdGenerator $ids,
    ) {
    }

    /**
     * Entrée du tunnel ; `?pro={id}` prépare une demande directe (RG-062). Route nommée `request_start` tant que l'envoi
     * (T-402b) n'est pas livré : les liens publics du site, conditionnés à `request_new`, restent masqués (P-62).
     */
    #[Route('', name: 'request_start', methods: ['GET'])]
    public function start(Request $request): Response
    {
        if (null !== ($blocked = $this->artisanBlocked())) {
            return $blocked;
        }
        $owner = $this->owner($request);
        $pro = $request->query->getString('pro');
        if ('' !== $pro) {
            try {
                $this->commands->dispatch(new StartRequestDraft($owner, $this->ids->next(), targetArtisanId: $pro));
            } catch (DraftRejected $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }
        $draft = $this->draft($owner);

        return $this->toStep($request, min($draft->step ?? 1, RequestDraftEditor::STEP_PHOTOS));
    }

    /** /demande-de-devis/{slug-metier} : métier pré-rempli (P11.2). */
    #[Route('/{job}', name: 'request_new_job', requirements: ['job' => '[a-z][a-z0-9-]*'], methods: ['GET'], priority: -10)]
    public function startWithJob(Request $request, string $job): Response
    {
        if (null !== ($blocked = $this->artisanBlocked())) {
            return $blocked;
        }
        $entry = $this->queries->ask(new JobBySlug($job));
        if (!$entry instanceof JobIndexEntry || !$entry->searchable) {
            throw $this->createNotFoundException('Métier inconnu.');
        }
        try {
            $this->commands->dispatch(new StartRequestDraft($this->owner($request), $this->ids->next(), jobId: $entry->id));
        } catch (DraftRejected $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->toStep($request, RequestDraftEditor::STEP_NEED);
    }

    #[Route('/{step}', name: 'request_step', requirements: ['step' => '[1-6]'], methods: ['GET', 'POST'])]
    public function step(Request $request, int $step): Response
    {
        if (null !== ($blocked = $this->artisanBlocked())) {
            return $blocked;
        }
        $owner = $this->owner($request);
        $draft = $this->draft($owner);
        if ($step > ($draft->step ?? 1)) {
            return $this->toStep($request, $draft->step ?? 1);
        }
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);
            if ('back' === $request->request->getString('action')) {
                $this->save($owner, $draft, $step, $request->request->all(), false);

                return $this->toStep($request, max(1, $step - 1));
            }
            try {
                $this->commands->dispatch(new SaveDraftStep($owner, $draft->id ?? $this->ids->next(), $step, $request->request->all(), true));

                return $this->toStep($request, $step + 1);
            } catch (DraftRejected $e) {
                return $this->page($request, $owner, $step, $e, $request->request->all());
            }
        }

        return $this->page($request, $owner, $step, null, null);
    }

    /** Sauvegarde automatique (toutes les 10 s, P11.1) : valeurs valides conservées, rien n'est exigé. */
    #[Route('/brouillon', name: 'request_autosave', methods: ['POST'], priority: 10)]
    public function autosave(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token')) || $this->currentUser->isArtisan()) {
            return new JsonResponse(['saved' => false], Response::HTTP_FORBIDDEN);
        }
        $owner = $this->owner($request);
        $draft = $this->draft($owner);
        $step = $request->request->getInt('step');
        if ($step < 1 || $step > ($draft->step ?? RequestDraftEditor::STEP_NEED)) {
            return new JsonResponse(['saved' => false], Response::HTTP_CONFLICT);
        }
        // Le brouillon d'un nouveau visiteur naît de sa première saisie à l'étape 1.
        $this->save($owner, $draft ?? RequestDraftView::fromPayload($this->ids->next(), RequestDraftEditor::STEP_NEED, [], [], new \DateTimeImmutable()), $step, $request->request->all(), false);

        return $this->withCookie(new JsonResponse(['saved' => true]), $request);
    }

    /** P11.4 — dépôt de photos ou de PDF (plusieurs fichiers à la fois), limité par IP. */
    #[Route('/fichiers', name: 'request_upload', methods: ['POST'], priority: 10)]
    public function upload(Request $request, #[Autowire(service: 'limiter.request_upload')] RateLimiterFactory $limiter): Response
    {
        $this->assertCsrf($request);
        $owner = $this->owner($request);
        $draft = $this->draft($owner) ?? throw new AccessDeniedHttpException();
        $files = $request->files->all('files');
        $accepted = 0;
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }
            if (!$file->isValid()) {
                $this->addFlash('error', \sprintf('« %s » : envoi interrompu ou fichier trop lourd.', mb_substr($file->getClientOriginalName(), 0, 60)));
                continue;
            }
            if (!$limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                throw new TooManyRequestsHttpException(null, 'Trop de fichiers envoyés : réessayez plus tard.');
            }
            try {
                $this->commands->dispatch(new AddDraftAttachment($owner, $draft->id, $file->getPathname(), $file->getClientOriginalName()));
                ++$accepted;
            } catch (DraftRejected $e) {
                $this->addFlash('error', \sprintf('« %s » : %s', mb_substr($file->getClientOriginalName(), 0, 60), $e->getMessage()));
            }
        }
        if ($accepted > 0) {
            $this->addFlash('success', 1 === $accepted ? 'Fichier ajouté : il sera visible après son analyse de sécurité.' : \sprintf('%d fichiers ajoutés : ils seront visibles après leur analyse de sécurité.', $accepted));
        } elseif ([] === $files) {
            $this->addFlash('error', 'Choisissez au moins un fichier.');
        }

        return $this->toStep($request, RequestDraftEditor::STEP_PHOTOS);
    }

    #[Route('/fichiers/{id}/supprimer', name: 'request_upload_delete', requirements: ['id' => self::UUID], methods: ['POST'], priority: 10)]
    public function deleteUpload(Request $request, string $id): Response
    {
        $this->assertCsrf($request);
        try {
            $this->commands->dispatch(new RemoveDraftAttachment($this->owner($request), $id));
            $this->addFlash('success', 'Fichier retiré.');
        } catch (DraftRejected $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->toStep($request, RequestDraftEditor::STEP_PHOTOS);
    }

    /** Aperçu d'une pièce du brouillon, pour son seul propriétaire, une fois analysée (lecture journalisée). */
    #[Route('/fichiers/{id}', name: 'request_upload_view', requirements: ['id' => self::UUID], methods: ['GET'], priority: 10)]
    public function viewUpload(Request $request, string $id, FileReader $reader): Response
    {
        $owner = $this->owner($request);
        $draft = $this->draft($owner);
        $attachment = null;
        foreach ($draft->attachments ?? [] as $a) {
            $attachment = $a->fileId === $id ? $a : $attachment;
        }
        if (null === $attachment) {
            throw new NotFoundHttpException();
        }
        try {
            $file = $reader->available($id);
            $stream = null === $owner->userId
                ? $reader->openForActor($id, 'system', null, $request->getClientIp()) // visiteur sans compte : tracé par son IP
                : $reader->openForActor($id, 'user', $owner->userId, $request->getClientIp());
        } catch (StoredFileNotFound) {
            throw new NotFoundHttpException();
        }

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, Response::HTTP_OK, [
            'Content-Type' => $file->type->value,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param array<array-key, mixed>|null $posted saisie refusée à réafficher */
    private function page(Request $request, DraftOwner $owner, int $step, ?DraftRejected $error, ?array $posted): Response
    {
        $draft = $this->draft($owner) ?? RequestDraftView::fromPayload($this->ids->next(), 1, [], [], new \DateTimeImmutable());
        $data = [
            'step' => $step,
            'steps' => self::STEPS,
            'draft' => $draft,
            'posted' => $posted,
            'error' => $error?->getMessage(),
            'errors' => $error->errors ?? [],
            'csrf_id' => self::CSRF,
            'target' => null === $draft->targetArtisanId ? null : $this->artisan($draft->targetArtisanId),
        ];
        $data += match ($step) {
            RequestDraftEditor::STEP_NEED => ['categories' => $this->jobsByCategory(), 'project_types' => ProjectType::cases()],
            RequestDraftEditor::STEP_DETAILS => [
                'job' => $this->job($draft->jobId),
                'questionnaire' => null === $draft->jobId ? null : $this->questionnaire($draft->jobId),
                'budget_ranges' => BudgetRange::cases(),
                'description_min' => RequestDetails::DESCRIPTION_MIN,
                'description_max' => RequestDetails::DESCRIPTION_MAX,
            ],
            RequestDraftEditor::STEP_PHOTOS => ['max_files' => $this->settings->int(SettingKey::RequestsMaxPhotos), 'max_mb' => $this->settings->int(SettingKey::RequestsMaxFileMb)],
            default => [],
        };
        $template = $step <= RequestDraftEditor::STEP_PHOTOS ? 'request/step'.$step.'.html.twig' : 'request/next_steps.html.twig';

        return $this->withCookie($this->render($template, $data, new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY)), $request);
    }

    /** @param array<array-key, mixed> $input */
    private function save(DraftOwner $owner, ?RequestDraftView $draft, int $step, array $input, bool $complete): void
    {
        if (null === $draft) {
            return;
        }
        try {
            $this->commands->dispatch(new SaveDraftStep($owner, $draft->id, $step, $input, $complete));
        } catch (DraftRejected) {
            // Sauvegarde partielle : rien d'exigé, une étape non encore accessible est simplement ignorée.
        }
    }

    /**
     * Propriétaire du brouillon : compte connecté, sinon jeton du cookie (créé à la première visite).
     */
    private function owner(Request $request): DraftOwner
    {
        $userId = $this->currentUser->id();
        if (null !== $userId) {
            return DraftOwner::user($userId);
        }
        $token = $request->cookies->getString(self::COOKIE);
        if (1 !== preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            $token = rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
            $request->attributes->set(self::NEW_COOKIE, Cookie::create(self::COOKIE, $token)
                ->withExpires(new \DateTimeImmutable(\sprintf('+%d days', $this->settings->int(SettingKey::RequestsDraftRetentionDays))))
                ->withPath('/demande-de-devis')
                ->withSecure($request->isSecure())
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_LAX));
            $request->cookies->set(self::COOKIE, $token);
        }

        return DraftOwner::visitor($token);
    }

    private function withCookie(Response $response, Request $request): Response
    {
        $cookie = $request->attributes->get(self::NEW_COOKIE);
        if ($cookie instanceof Cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    private function toStep(Request $request, int $step): Response
    {
        return $this->withCookie($this->redirectToRoute('request_step', ['step' => $step], Response::HTTP_SEE_OTHER), $request);
    }

    /** Les demandes se déposent avec un compte client (P11 : visiteur ou client). */
    private function artisanBlocked(): ?Response
    {
        return $this->currentUser->isArtisan() ? $this->render('request/artisan_blocked.html.twig', [], new Response(status: Response::HTTP_FORBIDDEN)) : null;
    }

    private function draft(DraftOwner $owner): ?RequestDraftView
    {
        $draft = $this->queries->ask(new CurrentRequestDraft($owner));

        return $draft instanceof RequestDraftView ? $draft : null;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
    }

    /** @return array<string, list<JobIndexEntry>> */
    private function jobsByCategory(): array
    {
        $categories = [];
        $entries = $this->queries->ask(new JobIndexEntries());
        foreach (\is_array($entries) ? $entries : [] as $entry) {
            if ($entry instanceof JobIndexEntry && $entry->searchable) {
                $categories[$entry->categoryName][] = $entry;
            }
        }

        return $categories;
    }

    private function job(?string $jobId): ?JobIndexEntry
    {
        $entries = null === $jobId ? [] : $this->queries->ask(new JobIndexEntries([$jobId]));
        foreach (\is_array($entries) ? $entries : [] as $entry) {
            if ($entry instanceof JobIndexEntry) {
                return $entry;
            }
        }

        return null;
    }

    private function questionnaire(string $jobId): ?JobQuestionnaireView
    {
        $view = $this->queries->ask(new JobQuestionnaire($jobId));

        return $view instanceof JobQuestionnaireView ? $view : null;
    }

    private function artisan(string $profileId): ?PublicProfileView
    {
        $view = $this->queries->ask(new PublicArtisanProfile($profileId));

        return $view instanceof PublicProfileView ? $view : null;
    }
}
