<?php

declare(strict_types=1);

namespace App\QuoteRequest\UI\Http;

use App\ArtisanProfile\Application\PublicProfile\PublicArtisanProfile;
use App\ArtisanProfile\Application\PublicProfile\PublicProfileView;
use App\Catalog\Application\Geo\Query\CommunesByInsee;
use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Query\JobBySlug;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Catalog\Application\Questionnaire\JobQuestionnaire;
use App\Catalog\Application\Questionnaire\JobQuestionnaireView;
use App\Catalog\Domain\Questionnaire\QuestionnaireField;
use App\Catalog\Domain\Questionnaire\QuestionnaireSchema;
use App\QuoteRequest\Application\Draft\AddDraftAttachment;
use App\QuoteRequest\Application\Draft\AdoptVisitorDraft;
use App\QuoteRequest\Application\Draft\CurrentRequestDraft;
use App\QuoteRequest\Application\Draft\DraftOwner;
use App\QuoteRequest\Application\Draft\DraftRejected;
use App\QuoteRequest\Application\Draft\RemoveDraftAttachment;
use App\QuoteRequest\Application\Draft\RequestDraftEditor;
use App\QuoteRequest\Application\Draft\RequestDraftView;
use App\QuoteRequest\Application\Draft\SaveDraftStep;
use App\QuoteRequest\Application\Draft\StartRequestDraft;
use App\QuoteRequest\Application\Draft\SubmitRequestDraft;
use App\QuoteRequest\Domain\Request\BudgetRange;
use App\QuoteRequest\Domain\Request\ContactPreference;
use App\QuoteRequest\Domain\Request\OccupantStatus;
use App\QuoteRequest\Domain\Request\PreferredSlot;
use App\QuoteRequest\Domain\Request\ProjectType;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\QuoteRequest\Domain\Request\RequestDetails;
use App\QuoteRequest\Domain\Request\RequestRuleViolation;
use App\QuoteRequest\Domain\Request\Timing;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Identity\ChangeUserPhone;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;
use App\Shared\Application\Identity\UserPhone;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Application\Security\HumanVerification;
use App\Shared\Application\Security\IpAnonymizer;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Application\Storage\FileReader;
use App\Shared\Application\Storage\StoredFileNotFound;
use App\Shared\Domain\Exception\InvalidValue;
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
    private const string TURNSTILE_FIELD = 'cf-turnstile-response';
    private const string CAPTCHA_ACTION = 'request';
    private const string CONFIRM_MESSAGE = 'Confirmez l’exactitude des informations fournies.';
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /** Attribut de requête portant le cookie à émettre (jamais une propriété : le contrôleur est un service partagé). */
    private const string NEW_COOKIE = '_tm_new_draft_cookie';

    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
        private readonly CurrentUser $currentUser,
        private readonly SettingsProvider $settings,
        private readonly IdGenerator $ids,
        private readonly HumanVerification $humans,
        private readonly IpAnonymizer $ipAnonymizer,
        #[Autowire(service: 'limiter.request_submit')]
        private readonly RateLimiterFactory $submitLimiter,
    ) {
    }

    /** Entrée du tunnel (liens « Déposer une demande » du site) ; `?pro={id}` prépare une demande directe (RG-062). */
    #[Route('', name: 'request_new', methods: ['GET'])]
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

        return $this->toStep($request, $draft->step ?? 1);
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
            if (RequestDraftEditor::STEP_RECAP === $step && 'back' !== $request->request->getString('action')) {
                return $this->submit($request, $owner);
            }
            if ('back' === $request->request->getString('action')) {
                $this->save($owner, $draft, $step, $request->request->all(), false);

                return $this->toStep($request, max(1, $step - 1));
            }
            try {
                if (RequestDraftEditor::STEP_CONTACT === $step) {
                    $this->savePhone($request, $owner);
                }
                $this->commands->dispatch(new SaveDraftStep($owner, $draft->id ?? $this->ids->next(), $step, $request->request->all(), true));

                return $this->toStep($request, $step + 1);
            } catch (DraftRejected $e) {
                return $this->page($request, $owner, $step, $e, $request->request->all());
            }
        }

        return $this->page($request, $owner, $step, null, null);
    }

    /** Confirmation d'envoi (P11.7) : n° de demande, prochaines étapes ; réservée au client propriétaire. */
    #[Route('/envoyee/{id}', name: 'request_sent', requirements: ['id' => self::UUID], methods: ['GET'], priority: 10)]
    public function sent(string $id, QuoteRequestRepository $requests): Response
    {
        $request = $requests->find(new QuoteRequestId($id));
        if (null === $request || $request->clientId() !== $this->currentUser->id()) {
            throw $this->createNotFoundException();
        }

        $city = null;
        $communes = $this->queries->ask(new CommunesByInsee([$request->cityInsee()]));
        foreach (\is_array($communes) ? $communes : [] as $commune) {
            $city = $commune instanceof CommuneSummary ? $commune->name : $city;
        }

        return $this->render('request/sent.html.twig', [
            'request' => $request,
            'city' => $city,
            // Figma 25:3 « Délai moyen de première réponse » : affiché une fois mesuré sur les devis (lot 5, P-64).
            'average_response' => null,
            'job' => $this->job($request->jobId()),
            'target' => null === $request->targetArtisanId() ? null : $this->artisan($request->targetArtisanId()),
        ]);
    }

    /**
     * P11.7 — envoi : compte client à l'e-mail vérifié (RG-040), limite d'envoi, captcha invisible, puis demande créée
     * depuis le brouillon. En cas d'échec, le brouillon est conservé et l'erreur affichée sur le récapitulatif.
     */
    private function submit(Request $request, DraftOwner $owner): Response
    {
        $error = match (true) {
            null === $owner->userId => 'Connectez-vous ou créez votre compte pour envoyer votre demande : votre brouillon est conservé.',
            !$this->currentUser->isEmailVerified() => 'Validez d’abord votre adresse e-mail (lien reçu à l’inscription) : votre demande sera envoyée ensuite, votre brouillon est conservé.',
            !$request->request->getBoolean('confirm') => self::CONFIRM_MESSAGE,
            !$this->submitLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted() => 'Vous avez déjà envoyé plusieurs demandes : merci de réessayer dans une heure.',
            default => $this->captchaError($request),
        };
        if (null !== $error || null === $owner->userId) {
            return $this->page($request, $owner, RequestDraftEditor::STEP_RECAP, new DraftRejected($error, self::CONFIRM_MESSAGE === $error ? ['confirm' => 'Cochez cette case pour envoyer votre demande.'] : []), $request->request->all());
        }
        $requestId = $this->ids->next();
        try {
            $this->commands->dispatch(new SubmitRequestDraft($owner->userId, $requestId, $this->ipAnonymizer->hash($request->getClientIp()), $request->request->has('broadcast') ? $request->request->getBoolean('broadcast') : null));
        } catch (DraftRejected|RequestRuleViolation $e) {
            return $this->page($request, $owner, RequestDraftEditor::STEP_RECAP, new DraftRejected($e->getMessage()), null);
        }

        return $this->redirectToRoute('request_sent', ['id' => $requestId], Response::HTTP_SEE_OTHER);
    }

    private function captchaError(Request $request): ?string
    {
        try {
            return $this->humans->verify($request->request->getString(self::TURNSTILE_FIELD), $request->getClientIp(), self::CAPTCHA_ACTION)
                ? null
                : 'La vérification anti-robot a échoué. Merci de réessayer.';
        } catch (ExternalServiceUnavailable) {
            return 'Le service de vérification anti-robot est momentanément indisponible. Merci de réessayer dans quelques minutes.';
        }
    }

    /** A02 : le téléphone, facultatif à l'inscription, est requis au premier dépôt ; il est porté par le compte. */
    private function savePhone(Request $request, DraftOwner $owner): void
    {
        if (null === $owner->userId || null !== $this->phone($owner->userId)) {
            return;
        }
        $phone = trim($request->request->getString('phone'));
        if ('' === $phone) {
            throw new DraftRejected('Indiquez votre téléphone mobile.', ['phone' => 'Votre numéro de mobile est requis pour votre première demande ; il n’est communiqué qu’au professionnel dont vous acceptez le devis.']);
        }
        try {
            $this->commands->dispatch(new ChangeUserPhone($owner->userId, $phone));
        } catch (InvalidValue) {
            throw new DraftRejected('Numéro de téléphone invalide.', ['phone' => 'Numéro de téléphone invalide (ex. 06 12 34 56 78).']);
        }
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
        // Envoi fichier par fichier avec progression (Figma 99:2230) : réponse JSON, la page est ensuite rechargée ; les
        // erreurs restent en messages flash pour y être affichées.
        if ('json' === $request->getPreferredFormat()) {
            return new JsonResponse(['accepted' => $accepted]);
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
                'questionnaire' => $questionnaire = null === $draft->jobId ? null : $this->questionnaire($draft->jobId),
                'question_rows' => null === $questionnaire ? [] : self::questionRows($questionnaire->schema),
                'title_max' => RequestDetails::TITLE_MAX,
                'summary_max' => RequestDetails::SUMMARY_MAX,
                'budget_ranges' => BudgetRange::cases(),
                'description_min' => RequestDetails::DESCRIPTION_MIN,
                'description_max' => RequestDetails::DESCRIPTION_MAX,
            ],
            RequestDraftEditor::STEP_PHOTOS => ['max_files' => $this->settings->int(SettingKey::RequestsMaxPhotos), 'max_mb' => $this->settings->int(SettingKey::RequestsMaxFileMb)],
            RequestDraftEditor::STEP_PLACE => [
                'timings' => Timing::cases(), 'slots' => PreferredSlot::cases(), 'occupants' => OccupantStatus::cases(),
                'min_date' => new \DateTimeImmutable('today'), 'max_date' => new \DateTimeImmutable(\sprintf('today +%d months', RequestDraftEditor::DESIRED_DATE_MAX_MONTHS)),
            ],
            RequestDraftEditor::STEP_CONTACT => $this->contactData($owner, $request),
            default => $this->recapData($owner, $draft),
        };
        $template = 'request/step'.$step.'.html.twig';

        return $this->withCookie($this->render($template, $data, new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY)), $request);
    }

    /** @return array<string, mixed> */
    private function contactData(DraftOwner $owner, Request $request): array
    {
        $contact = null === $owner->userId ? null : $this->queries->ask(new UserContact($owner->userId));

        return [
            'preferences' => ContactPreference::cases(),
            'contact' => $contact instanceof UserContactDetails ? $contact : null,
            'has_phone' => null !== $owner->userId && null !== $this->phone($owner->userId),
            'email_verified' => $this->currentUser->isEmailVerified(),
            'login_url' => $this->generateUrl('identity_login', ['redirect' => $request->getPathInfo()]),
        ];
    }

    /** @return array<string, mixed> */
    private function recapData(DraftOwner $owner, RequestDraftView $draft): array
    {
        $questionnaire = null === $draft->jobId ? null : $this->questionnaire($draft->jobId);

        return [
            'job' => $this->job($draft->jobId),
            'answers' => null === $questionnaire ? [] : $questionnaire->schema->describe($draft->answers),
            'project_type' => ProjectType::tryFrom($draft->projectType ?? ''),
            'budget' => BudgetRange::tryFrom($draft->budgetRange ?? ''),
            'timing' => Timing::tryFrom($draft->timing ?? ''),
            'slots' => array_values(array_filter(array_map(PreferredSlot::tryFrom(...), $draft->preferredSlots))),
            'occupant' => OccupantStatus::tryFrom($draft->occupantStatus ?? ''),
            'preference' => ContactPreference::tryFrom($draft->contactPreference ?? ''),
            'max_quotes' => $this->settings->int(SettingKey::QuotesMaxPerRequest),
            'signed_in' => null !== $owner->userId,
            'email_verified' => $this->currentUser->isEmailVerified(),
        ];
    }

    /**
     * Figma 5:57 : deux questions « demi-largeur » consécutives partagent une ligne (type de logement, étage).
     *
     * @return list<list<QuestionnaireField>>
     */
    private static function questionRows(QuestionnaireSchema $schema): array
    {
        $rows = [];
        $pending = null;
        foreach ($schema->fields as $field) {
            if ($field->half && null !== $pending) {
                $rows[] = [$pending, $field];
                $pending = null;
            } elseif ($field->half) {
                $pending = $field;
            } else {
                if (null !== $pending) {
                    $rows[] = [$pending];
                    $pending = null;
                }
                $rows[] = [$field];
            }
        }
        if (null !== $pending) {
            $rows[] = [$pending];
        }

        return $rows;
    }

    private function phone(string $userId): ?string
    {
        $phone = $this->queries->ask(new UserPhone($userId));

        return \is_string($phone) ? $phone : null;
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
            // P11.6 : le brouillon commencé avant la connexion est rattaché au compte, puis le jeton est oublié.
            $token = $request->cookies->getString(self::COOKIE);
            if (1 === preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
                $this->commands->dispatch(new AdoptVisitorDraft(DraftOwner::visitor($token), $userId));
                $request->cookies->remove(self::COOKIE);
                $request->attributes->set(self::NEW_COOKIE, Cookie::create(self::COOKIE, '')->withExpires(1)->withPath('/demande-de-devis'));
            }

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
