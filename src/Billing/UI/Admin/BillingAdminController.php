<?php

declare(strict_types=1);

namespace App\Billing\UI\Admin;

use App\Administration\Application\Port\Reauthentication;
use App\Billing\Application\Invoice\AdminArtisanBilling;
use App\Billing\Application\Invoice\AdminArtisanBillingView;
use App\Billing\Application\Invoice\Invoices;
use App\Billing\Application\Invoice\RefundInvoice;
use App\Billing\Application\Invoice\RefundRejected;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentAdministrator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Facturation d'un artisan au back-office (depuis la fiche BO03.3) : abonnement, factures, avoirs ; remboursement total
 * ou partiel (§4.3) réservé au rôle Finance (`admin.plans.create`), avec ré-authentification (ADR-0013). P-103.
 */
#[Route('/admin/facturation')]
final class BillingAdminController extends AbstractController
{
    private const string CSRF = 'admin_refund';
    private const int CENTS = 100;

    public function __construct(private readonly QueryBus $queries, private readonly CommandBus $commands, private readonly Invoices $invoices, private readonly CurrentAdministrator $admin)
    {
    }

    #[Route('/artisans/{id}', name: 'admin_billing_artisan', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    #[IsGranted('admin.plans.view')]
    public function artisan(string $id): Response
    {
        $view = $this->queries->ask(new AdminArtisanBilling($id));
        if (!$view instanceof AdminArtisanBillingView) {
            throw new NotFoundHttpException('Aucun compte de facturation pour cet artisan.');
        }

        return $this->render('admin/billing/artisan.html.twig', ['view' => $view, 'reasons' => RefundInvoice::REASONS]);
    }

    #[Route('/factures/{id}/rembourser', name: 'admin_invoice_refund', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'POST'])]
    #[IsGranted('admin.plans.create')]
    public function refund(Request $request, string $id): Response
    {
        $invoice = $this->invoices->findAny($id) ?? throw new NotFoundHttpException('Facture introuvable.');
        if (!$this->isGranted(Reauthentication::ATTRIBUTE)) {
            $this->addFlash('info', 'Pour rembourser, confirmez votre identité (mot de passe et code).');

            return $this->redirectToRoute('admin_reauth', ['cible' => $this->generateUrl('admin_invoice_refund', ['id' => $id])], Response::HTTP_SEE_OTHER);
        }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
                throw new AccessDeniedHttpException('Jeton CSRF invalide.');
            }
            try {
                $this->commands->dispatch(new RefundInvoice(
                    $this->admin->id() ?? throw new AccessDeniedHttpException(),
                    $id,
                    'full' === $request->request->getString('scope') ? null : self::cents($request->request->getString('amount')),
                    $request->request->getString('reason'),
                    $request->request->getString('comment'),
                ));
                $this->addFlash('success', 'Remboursement effectué : avoir émis et artisan prévenu (E-48).');

                return $this->redirectToRoute('admin_billing_artisan', ['id' => $invoice->artisanId], Response::HTTP_SEE_OTHER);
            } catch (RefundRejected $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('admin/billing/refund.html.twig', [
            'invoice' => $invoice,
            'reasons' => RefundInvoice::REASONS,
            'posted' => $request->isMethod('POST') ? $request->request->all() : null,
            'error' => $error,
            'csrf_id' => self::CSRF,
        ], new Response(null, null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /** « 29 », « 29,00 », « 12,5 » → centimes ; saisie illisible → 0 (refusé par le cas d'usage). */
    private static function cents(string $value): int
    {
        $value = str_replace([' ', "\u{a0}", '€'], '', $value);

        return 1 === preg_match('/^(\d{1,6})(?:[.,](\d{1,2}))?$/', $value, $m) ? (int) $m[1] * self::CENTS + (int) str_pad($m[2] ?? '0', 2, '0') : 0;
    }
}
