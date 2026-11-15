<?php

declare(strict_types=1);

namespace App\Moderation\UI\Admin;

use App\Administration\Application\Command\ChangeSetting;
use App\Moderation\Application\Report\DecideReport;
use App\Moderation\Application\Report\OpenReports;
use App\Moderation\Application\Report\ReportRejected;
use App\Moderation\Application\Report\ReportView;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Moderation\DecideProfileReview;
use App\Shared\Application\Moderation\DecideReportedMessage;
use App\Shared\Application\Moderation\ModerateReview;
use App\Shared\Application\Moderation\ModerationDecisionRejected;
use App\Shared\Application\Moderation\PendingProfileReviews;
use App\Shared\Application\Moderation\PendingReviews;
use App\Shared\Application\Moderation\ProfileReviewItem;
use App\Shared\Application\Moderation\ReportableContent;
use App\Shared\Application\Moderation\ReportedMessageItem;
use App\Shared\Application\Moderation\ReportedMessages;
use App\Shared\Application\Moderation\RevealMessageOriginal;
use App\Shared\Application\Moderation\ReviewModerationItem;
use App\Shared\Application\Security\CurrentAdministrator;
use App\Shared\Application\Settings\InvalidSettingValue;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO05 (/admin/moderation/{onglet}, Figma 186:2172) — files de modération : avis, messages signalés, signalements,
 * contenus de profils (relecture RG-031), offres, et règles automatiques éditables (BO05.3). Messages signalés
 * (T-701d2) : contexte du fil, lecture journalisée de l'original scellé, classement ou retrait. Avis (T-704d) : preuve
 * de certification, historique de l'auteur, score de risque, décisions. Signalements (BO05.2) : avis, profils et
 * demandes signalés, décision et réponse au signalant. Offres : lot suivant.
 */
#[Route('/admin/moderation')]
final class ModerationController extends AbstractController
{
    public const array TABS = ['avis' => 'Avis', 'messages' => 'Messages signalés', 'signalements' => 'Signalements', 'contenus' => 'Contenus de profils', 'offres' => 'Offres', 'regles' => 'Règles automatiques'];
    /** Seuils et points éditables (BO05.3 « Seuils anti-spam ») ; les autres règles ont leur formulaire. */
    private const array THRESHOLDS = [
        SettingKey::ModerationRequestRiskThreshold,
        SettingKey::ModerationReviewRiskThreshold,
        SettingKey::ModerationMessageRiskThreshold,
        SettingKey::ModerationProfileSampleRate,
        SettingKey::ModerationRequestPointsForbiddenTerm,
        SettingKey::ModerationRequestPointsLink,
        SettingKey::ModerationRequestPointsContact,
        SettingKey::ModerationRequestPointsDuplicateText,
        SettingKey::ModerationRequestPointsVelocity,
        SettingKey::ModerationRequestVelocityPerDay,
        SettingKey::ModerationRequestSimilarityDays,
    ];
    private const string CSRF = 'admin_moderation';
    private const string REASON = 'BO05.3 : règles automatiques';

    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
        private readonly CurrentAdministrator $admin,
        private readonly SettingsProvider $settings,
        /** @var iterable<ReportableContent> */
        #[AutowireIterator('app.reportable_content')]
        private readonly iterable $reportables,
    ) {
    }

    #[Route('', name: 'admin_moderation', methods: ['GET'])]
    #[Route('/{onglet}', name: 'admin_moderation_tab', requirements: ['onglet' => 'avis|messages|signalements|contenus|offres|regles'], methods: ['GET'])]
    #[IsGranted('admin.moderation.view')]
    public function index(Request $request, string $onglet = 'avis'): Response
    {
        return $this->page($onglet, $request->query->getString('element'));
    }

    /** Messages signalés : signalement classé, ou message retiré de l'affichage avec un motif. */
    #[Route('/messages/{id}', name: 'admin_moderation_message', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    #[IsGranted('admin.moderation.edit')]
    public function decideMessage(Request $request, string $id): Response
    {
        $this->assertCsrf($request);
        $remove = 'remove' === $request->request->getString('decision');
        try {
            $this->commands->dispatch(new DecideReportedMessage($this->adminId(), $id, $remove, $request->request->getString('reason') ?: null));
            $this->addFlash('success', $remove ? 'Message retiré : les deux participants voient « Message retiré par la modération ».' : 'Signalement classé sans suite.');
        } catch (ModerationDecisionRejected $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_moderation_tab', ['onglet' => 'messages', 'element' => $id], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('admin_moderation_tab', ['onglet' => 'messages'], Response::HTTP_SEE_OTHER);
    }

    /** BO05.1 — décision sur un avis ou une réponse (RG-035, RG-036) ; le refus et le masquage exigent un motif normalisé. */
    #[Route('/avis/{id}', name: 'admin_moderation_review', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    #[IsGranted('admin.moderation.edit')]
    public function decideReview(Request $request, string $id): Response
    {
        $this->assertCsrf($request);
        $decision = $request->request->getString('decision');
        try {
            $this->commands->dispatch(new ModerateReview($this->adminId(), $id, $decision, $request->request->getString('reason') ?: null, $request->request->getString('note') ?: null));
            $this->addFlash('success', ['publish' => 'Avis publié : l’auteur est prévenu.', 'reject' => 'Avis refusé : l’auteur reçoit le motif.', 'hide' => 'Avis masqué.', 'changes' => 'Modification demandée à l’auteur.', 'reply_publish' => 'Réponse publiée.', 'reply_reject' => 'Réponse refusée : l’artisan est prévenu.'][$decision] ?? 'Décision enregistrée.');
        } catch (ModerationDecisionRejected $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_moderation_tab', ['onglet' => 'avis', 'element' => $id], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('admin_moderation_tab', ['onglet' => 'avis'], Response::HTTP_SEE_OTHER);
    }

    /** Original d'un message dont les coordonnées ont été masquées (ADR-0014) : affiché une fois, lecture journalisée. */
    #[Route('/messages/{id}/original', name: 'admin_moderation_message_original', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    #[IsGranted('admin.moderation.edit')]
    public function original(Request $request, string $id): Response
    {
        $this->assertCsrf($request);
        $original = $this->queries->ask(new RevealMessageOriginal($this->adminId(), $id, $request->getClientIp()));

        $response = $this->page('messages', $id, \is_string($original) ? $original : null);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function page(string $onglet, string $selected, ?string $original = null): Response
    {
        $contents = $this->contents();
        $current = null;
        foreach ($contents as $item) {
            $current = $item->id === $selected ? $item : $current;
        }
        $reviews = $this->reviews();
        $review = null;
        foreach ($reviews as $item) {
            $review = $item->id === $selected ? $item : $review;
        }
        $reports = $this->queries->ask(new OpenReports());
        \assert(\is_array($reports));
        /** @var list<ReportView> $reports */
        $report = null;
        foreach ($reports as $item) {
            $report = $item->id === $selected ? $item : $report;
        }
        $messages = $this->messages();
        $message = null;
        foreach ($messages as $item) {
            $message = $item->id === $selected ? $item : $message;
        }

        return $this->render('admin/moderation/index.html.twig', [
            'tab' => $onglet,
            'tabs' => self::TABS,
            'counts' => ['avis' => \count($reviews), 'messages' => \count($messages), 'signalements' => \count($reports), 'contenus' => \count($contents), 'offres' => 0],
            'reports' => $reports,
            'report' => $report ?? ($reports[0] ?? null),
            'report_types' => $this->reportTypes($reports),
            'contents' => $contents,
            'current' => $current ?? ($contents[0] ?? null),
            'reviews' => $reviews,
            'review' => $review ?? ($reviews[0] ?? null),
            'review_reasons' => ['offensive' => 'Propos injurieux', 'off_topic' => 'Hors sujet', 'personal_data' => 'Données personnelles', 'conflict_of_interest' => 'Conflit d’intérêts'],
            'messages' => $messages,
            'message' => $message ?? ($messages[0] ?? null),
            'original' => null !== $original && null !== $message ? $original : null,
            'message_reasons' => DecideReportedMessage::REASONS,
            'reasons' => DecideProfileReview::REASONS,
            'forbidden_terms' => $this->settings->stringList(SettingKey::ModerationForbiddenTerms),
            'thresholds' => array_map(fn (SettingKey $k): array => ['key' => $k->value, 'label' => $k->definition()->label, 'value' => $this->settings->int($k)], self::THRESHOLDS),
            'request_threshold' => $this->settings->int(SettingKey::ModerationRequestRiskThreshold),
            'review_threshold' => $this->settings->int(SettingKey::ModerationReviewRiskThreshold),
            'message_threshold' => $this->settings->int(SettingKey::ModerationMessageRiskThreshold),
            'now' => new \DateTimeImmutable(),
            'csrf_id' => self::CSRF,
        ]);
    }

    /**
     * BO05.2 — signalement classé sans suite ou traité, réponse facultative au signalant (E-27). « Masquer l'avis » applique
     * d'abord la décision BO05.1 au contenu signalé.
     */
    #[Route('/signalements/{id}', name: 'admin_moderation_report', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    #[IsGranted('admin.moderation.edit')]
    public function report(Request $request, string $id): Response
    {
        $this->assertCsrf($request);
        $decision = $request->request->getString('decision');
        $response = $request->request->getString('response');
        try {
            if ('hide_review' === $decision) {
                $this->commands->dispatch(new ModerateReview($this->adminId(), $request->request->getString('subject'), 'hide', $request->request->getString('hide_reason')));
                $decision = 'actioned';
            }
            $this->commands->dispatch(new DecideReport($this->adminId(), $id, $decision, $response));
            $this->addFlash('success', 'actioned' === $decision ? 'Signalement traité ; le signalant est informé.' : 'Signalement classé sans suite ; le signalant est informé.');
        } catch (ReportRejected|ModerationDecisionRejected $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_moderation_tab', ['onglet' => 'signalements', 'element' => $id], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('admin_moderation_tab', ['onglet' => 'signalements'], Response::HTTP_SEE_OTHER);
    }

    /**
     * Libellés des types et lien d'administration de chaque élément signalé.
     *
     * @param list<ReportView> $reports
     *
     * @return array{labels: array<string, string>, links: array<string, array{route: string, parameters: array<string, string>}>}
     */
    private function reportTypes(array $reports): array
    {
        $labels = [];
        $links = [];
        foreach ($this->reportables as $reportable) {
            $labels[$reportable->type()] = $reportable->label();
            foreach ($reports as $report) {
                $link = $report->type === $reportable->type() ? $reportable->adminRoute($report->subjectId) : null;
                if (null !== $link) {
                    $links[$report->id] = $link;
                }
            }
        }

        return ['labels' => $labels, 'links' => $links];
    }

    /** RG-031 — contenu de profil validé, ou retiré du profil public (motif normalisé). */
    #[Route('/contenus/{id}', name: 'admin_moderation_content', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    #[IsGranted('admin.moderation.edit')]
    public function decide(Request $request, string $id): Response
    {
        $this->assertCsrf($request);
        $approve = 'approve' === $request->request->getString('decision');
        try {
            $this->commands->dispatch(new DecideProfileReview($this->adminId(), $id, $approve, $request->request->getString('reason') ?: null));
            $this->addFlash('success', $approve ? 'Contenu validé.' : 'Contenu retiré du profil public.');
        } catch (ModerationDecisionRejected $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_moderation_tab', ['onglet' => 'contenus', 'element' => $id], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('admin_moderation_tab', ['onglet' => 'contenus'], Response::HTTP_SEE_OTHER);
    }

    /** BO05.3 — mots interdits (un par ligne) ou seuils anti-spam. */
    #[Route('/regles', name: 'admin_moderation_rules', methods: ['POST'])]
    #[IsGranted('admin.moderation.edit')]
    public function rules(Request $request): Response
    {
        $this->assertCsrf($request);
        try {
            if ('terms' === $request->request->getString('form')) {
                $terms = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $request->request->getString('forbidden_terms')) ?: []), static fn (string $t): bool => '' !== $t)));
                $this->commands->dispatch(new ChangeSetting(SettingKey::ModerationForbiddenTerms->value, $terms, $this->adminId(), self::REASON));
            } else {
                foreach (self::THRESHOLDS as $key) {
                    $this->commands->dispatch(new ChangeSetting($key->value, $request->request->getInt(str_replace('.', '_', $key->value)), $this->adminId(), self::REASON));
                }
            }
            $this->addFlash('success', 'Règles enregistrées : elles s’appliquent sous quelques minutes (cache des paramètres).');
        } catch (InvalidSettingValue $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_moderation_tab', ['onglet' => 'regles'], Response::HTTP_SEE_OTHER);
    }

    /** @return list<ProfileReviewItem> */
    private function contents(): array
    {
        $items = $this->queries->ask(new PendingProfileReviews());

        return array_values(array_filter(\is_array($items) ? $items : [], static fn (mixed $i): bool => $i instanceof ProfileReviewItem));
    }

    /** @return list<ReviewModerationItem> */
    private function reviews(): array
    {
        $items = $this->queries->ask(new PendingReviews());

        return array_values(array_filter(\is_array($items) ? $items : [], static fn (mixed $i): bool => $i instanceof ReviewModerationItem));
    }

    /** @return list<ReportedMessageItem> */
    private function messages(): array
    {
        $items = $this->queries->ask(new ReportedMessages());

        return array_values(array_filter(\is_array($items) ? $items : [], static fn (mixed $i): bool => $i instanceof ReportedMessageItem));
    }

    private function adminId(): string
    {
        return $this->admin->id() ?? throw new AccessDeniedHttpException();
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
    }
}
