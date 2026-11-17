<?php

declare(strict_types=1);

namespace App\Billing\UI\Admin;

use App\Billing\Application\Plan\AdminPlans;
use App\Billing\Application\Plan\PlanView;
use App\Billing\Application\Promo\AdminPromoCodes;
use App\Billing\Application\Promo\CreatePromoCode;
use App\Billing\Application\Promo\DisablePromoCode;
use App\Billing\Application\Promo\PromoCodeView;
use App\Billing\Domain\Plan\PlanStatus;
use App\Billing\Domain\Promo\PromoRuleViolation;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentAdministrator;
use App\Shared\Domain\Identifier\IdGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO08.3 (Figma 186:3467, section « Codes promo ») — création d'un code synchronisé avec Stripe (§4.3), suivi des
 * utilisations, désactivation, export CSV. Liste affichée sur /admin/forfaits.
 */
#[Route('/admin/forfaits/codes-promo')]
final class PromoCodesAdminController extends AbstractController
{
    private const string CSRF = 'admin_promo_codes';
    private const string CSV_SEPARATOR = ';';

    public function __construct(private readonly QueryBus $queries, private readonly CommandBus $commands, private readonly CurrentAdministrator $admin)
    {
    }

    #[Route('/nouveau', name: 'admin_promo_new', methods: ['GET', 'POST'])]
    #[IsGranted('admin.plans.create')]
    public function create(Request $request, IdGenerator $ids): Response
    {
        $error = null;
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);
            try {
                $this->commands->dispatch(new CreatePromoCode($this->adminId(), $ids->next(), $request->request->all()));
                $this->addFlash('success', 'Code promo créé et synchronisé avec Stripe.');

                return $this->redirect($this->generateUrl('admin_plans').'#promo', Response::HTTP_SEE_OTHER);
            } catch (PromoRuleViolation $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('admin/plans/promo_form.html.twig', [
            'posted' => $request->isMethod('POST') ? $request->request->all() : null,
            'error' => $error,
            'plans' => $this->sellablePlans(),
            'csrf_id' => self::CSRF,
        ], new Response(null, null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/{id}/desactiver', name: 'admin_promo_disable', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    #[IsGranted('admin.plans.edit')]
    public function disable(Request $request, string $id): Response
    {
        $this->assertCsrf($request);
        try {
            $this->commands->dispatch(new DisablePromoCode($this->adminId(), $id));
            $this->addFlash('success', 'Code promo désactivé : il ne peut plus être utilisé (les abonnés qui en bénéficient le gardent).');
        } catch (PromoRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($this->generateUrl('admin_plans').'#promo', Response::HTTP_SEE_OTHER);
    }

    #[Route('/export', name: 'admin_promo_export', methods: ['GET'])]
    #[IsGranted('admin.plans.export')]
    public function export(Request $request): StreamedResponse
    {
        $codes = self::codes($this->queries->ask(new AdminPromoCodes(trim($request->query->getString('code')), self::state($request))));
        $response = new StreamedResponse(static function () use ($codes): void {
            $out = fopen('php://output', 'w');
            if (false === $out) {
                return;
            }
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Code', 'Remise', 'Durée', 'Utilisations', 'Maximum', 'Par compte', 'Nouveaux abonnés', 'Forfaits', 'Fin de validité', 'Statut'], self::CSV_SEPARATOR, '"', '');
            foreach ($codes as $c) {
                $t = $c->terms;
                fputcsv($out, [$t->code, $t->label(), $t->durationLabel(), $c->redemptions, $t->maxRedemptions ?? '', $t->maxPerAccount ?? '', $t->newSubscribersOnly ? 'oui' : 'non', [] === $c->planNames ? 'Tous' : implode(', ', $c->planNames), $t->validUntil?->modify('-1 day')->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y') ?? '', $c->stateLabel()], self::CSV_SEPARATOR, '"', '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="codes-promo.csv"');

        return $response;
    }

    public static function state(Request $request): ?string
    {
        $state = $request->query->getString('statut');

        return \in_array($state, ['active', 'exhausted', 'expired', 'disabled'], true) ? $state : null;
    }

    /** @return list<PromoCodeView> */
    public static function codes(mixed $result): array
    {
        return \is_array($result) ? array_values(array_filter($result, static fn (mixed $c): bool => $c instanceof PromoCodeView)) : [];
    }

    /** @return list<PlanView> forfaits publiés (éligibles à un code) */
    private function sellablePlans(): array
    {
        $plans = $this->queries->ask(new AdminPlans());

        return \is_array($plans) ? array_values(array_filter($plans, static fn (mixed $p): bool => $p instanceof PlanView && PlanStatus::Published === $p->status)) : [];
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
