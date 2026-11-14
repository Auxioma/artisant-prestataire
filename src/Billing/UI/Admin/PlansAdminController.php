<?php

declare(strict_types=1);

namespace App\Billing\UI\Admin;

use App\Administration\Application\Command\ChangeSetting;
use App\Billing\Application\Plan\AdminPlans;
use App\Billing\Application\Plan\PlanAction;
use App\Billing\Application\Plan\PlanView;
use App\Billing\Application\Plan\SavePlan;
use App\Billing\Application\Promo\AdminPromoCodes;
use App\Billing\Domain\Plan\PlanRuleViolation;
use App\Billing\Domain\Plan\PlanVisibility;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentAdministrator;
use App\Shared\Application\Settings\InvalidSettingValue;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Identifier\IdGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO08 (/admin/forfaits, Figma 186:3467) — monétisation : forfaits (BO08.1 : liste, création, modification, publication,
 * archivage, ordre), paramètres de crédits (BO08.2, journalisés par ChangeSetting), codes promo (BO08.3, avec Stripe).
 */
#[Route('/admin/forfaits')]
final class PlansAdminController extends AbstractController
{
    private const string CSRF = 'admin_plans';
    private const string SETTINGS_REASON = 'BO08.2 : paramètres de crédits';
    private const array ORDERS = ['free,plan,extra' => 'Gratuit → forfait → additionnel', 'plan,free,extra' => 'Forfait → gratuit → additionnel'];

    public function __construct(private readonly QueryBus $queries, private readonly CommandBus $commands, private readonly CurrentAdministrator $admin, private readonly SettingsProvider $settings)
    {
    }

    #[Route('', name: 'admin_plans', methods: ['GET'])]
    #[IsGranted('admin.plans.view')]
    public function index(Request $request): Response
    {
        $promoFilters = ['code' => trim($request->query->getString('code')), 'statut' => (string) PromoCodesAdminController::state($request)];

        return $this->render('admin/plans/index.html.twig', [
            'plans' => $this->plans(),
            'promo_codes' => PromoCodesAdminController::codes($this->queries->ask(new AdminPromoCodes($promoFilters['code'], PromoCodesAdminController::state($request)))),
            'promo_filters' => $promoFilters,
            'credits' => [
                'free' => $this->settings->int(SettingKey::CreditsFreeOnSignup),
                'grace' => $this->settings->int(SettingKey::BillingGraceDays),
                'thresholds' => implode(', ', $this->settings->intList(SettingKey::CreditsAlertThresholds)),
                'plan_alerts' => implode(', ', $this->settings->intList(SettingKey::CreditsPlanAlertPct)),
                'rollover' => $this->settings->bool(SettingKey::PlansRollover),
                'order' => implode(',', $this->settings->stringList(SettingKey::CreditsConsumptionOrder)),
            ],
            'orders' => self::ORDERS,
            'csrf_id' => self::CSRF,
        ]);
    }

    #[Route('/nouveau', name: 'admin_plan_new', methods: ['GET', 'POST'])]
    #[IsGranted('admin.plans.create')]
    public function create(Request $request, IdGenerator $ids): Response
    {
        return $this->form($request, null, $ids);
    }

    #[Route('/{id}', name: 'admin_plan_edit', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'POST'])]
    #[IsGranted('admin.plans.edit')]
    public function edit(Request $request, string $id, IdGenerator $ids): Response
    {
        return $this->form($request, $this->plan($id), $ids);
    }

    #[Route('/{id}/action', name: 'admin_plan_action', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    #[IsGranted('admin.plans.edit')]
    public function action(Request $request, string $id): Response
    {
        $this->assertCsrf($request);
        $action = $request->request->getString('action');
        if (!\in_array($action, PlanAction::ACTIONS, true)) {
            throw new NotFoundHttpException();
        }
        try {
            $this->commands->dispatch(new PlanAction($this->adminId(), $id, $action));
            $this->addFlash('success', match ($action) {
                PlanAction::PUBLISH => 'Forfait publié : il est proposé à la souscription.',
                PlanAction::ARCHIVE => 'Forfait archivé : il n’est plus proposé ; ses abonnés le conservent.',
                PlanAction::DELETE => 'Brouillon supprimé.',
                default => 'Ordre mis à jour.',
            });
        } catch (PlanRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_plans', [], Response::HTTP_SEE_OTHER);
    }

    /** BO08.2 — chaque valeur modifiée est journalisée (avant / après) par ChangeSetting. */
    #[Route('/parametres', name: 'admin_plans_settings', methods: ['POST'])]
    #[IsGranted('admin.plans.edit')]
    public function settings(Request $request): Response
    {
        $this->assertCsrf($request);
        $list = static fn (string $v): array => array_values(array_map('intval', array_filter(array_map('trim', explode(',', $v)), static fn (string $x): bool => '' !== $x)));
        $order = $request->request->getString('order');
        $values = [
            SettingKey::CreditsFreeOnSignup->value => $request->request->getInt('free'),
            SettingKey::BillingGraceDays->value => $request->request->getInt('grace'),
            SettingKey::CreditsAlertThresholds->value => $list($request->request->getString('thresholds')),
            SettingKey::CreditsPlanAlertPct->value => $list($request->request->getString('plan_alerts')),
            SettingKey::PlansRollover->value => $request->request->getBoolean('rollover'),
            SettingKey::CreditsConsumptionOrder->value => explode(',', isset(self::ORDERS[$order]) ? $order : array_key_first(self::ORDERS)),
        ];
        try {
            foreach ($values as $key => $value) {
                $this->commands->dispatch(new ChangeSetting($key, $value, $this->adminId(), self::SETTINGS_REASON));
            }
            $this->addFlash('success', 'Paramètres de crédits enregistrés : ils s’appliquent sous quelques minutes.');
        } catch (InvalidSettingValue $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($this->generateUrl('admin_plans').'#credits', Response::HTTP_SEE_OTHER);
    }

    private function form(Request $request, ?PlanView $plan, IdGenerator $ids): Response
    {
        $error = null;
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);
            $input = $request->request->all();
            unset($input['_token']);
            try {
                $this->commands->dispatch(new SavePlan($this->adminId(), $plan?->id, $ids->next(), $input));
                $this->addFlash('success', null === $plan ? 'Forfait créé en brouillon.' : 'Forfait enregistré.');

                return $this->redirectToRoute('admin_plans', [], Response::HTTP_SEE_OTHER);
            } catch (PlanRuleViolation $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('admin/plans/form.html.twig', [
            'plan' => $plan,
            'posted' => null === $error ? null : $request->request->all(),
            'error' => $error,
            'visibilities' => PlanVisibility::cases(),
            'csrf_id' => self::CSRF,
        ], new Response(null, null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /** @return list<PlanView> */
    private function plans(): array
    {
        $plans = $this->queries->ask(new AdminPlans());

        return \is_array($plans) ? array_values(array_filter($plans, static fn (mixed $p): bool => $p instanceof PlanView)) : [];
    }

    private function plan(string $id): PlanView
    {
        foreach ($this->plans() as $plan) {
            if ($plan->id === $id) {
                return $plan;
            }
        }
        throw new NotFoundHttpException('Forfait introuvable.');
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
    }

    private function adminId(): string
    {
        return $this->admin->id() ?? throw new AccessDeniedHttpException();
    }
}
