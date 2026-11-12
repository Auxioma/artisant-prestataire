<?php

declare(strict_types=1);

namespace App\Moderation\UI\Admin;

use App\Administration\Application\Command\ChangeSetting;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Moderation\DecideProfileReview;
use App\Shared\Application\Moderation\ModerationDecisionRejected;
use App\Shared\Application\Moderation\PendingProfileReviews;
use App\Shared\Application\Moderation\ProfileReviewItem;
use App\Shared\Application\Security\CurrentAdministrator;
use App\Shared\Application\Settings\InvalidSettingValue;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO05 (/admin/moderation/{onglet}, Figma 186:2172) — files de modération : avis, messages signalés, signalements,
 * contenus de profils (relecture RG-031), offres, et règles automatiques éditables (BO05.3). Les files des avis, des
 * messages, des signalements et des offres arrivent avec leurs lots ; leur onglet indique un compte nul.
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
    ) {
    }

    #[Route('', name: 'admin_moderation', methods: ['GET'])]
    #[Route('/{onglet}', name: 'admin_moderation_tab', requirements: ['onglet' => 'avis|messages|signalements|contenus|offres|regles'], methods: ['GET'])]
    #[IsGranted('admin.moderation.view')]
    public function index(Request $request, string $onglet = 'avis'): Response
    {
        $contents = $this->contents();
        $selected = $request->query->getString('element');
        $current = null;
        foreach ($contents as $item) {
            $current = $item->id === $selected ? $item : $current;
        }

        return $this->render('admin/moderation/index.html.twig', [
            'tab' => $onglet,
            'tabs' => self::TABS,
            'counts' => ['avis' => 0, 'messages' => 0, 'signalements' => 0, 'contenus' => \count($contents), 'offres' => 0],
            'contents' => $contents,
            'current' => $current ?? ($contents[0] ?? null),
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
