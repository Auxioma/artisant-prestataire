<?php

declare(strict_types=1);

namespace App\Billing\UI\Pro;

use App\Billing\Application\Invoice\InvoiceArchive;
use App\Billing\Application\Invoice\Invoices;
use App\Billing\Application\Port\PaymentGateway;
use App\Billing\Application\Subscription\SubscriptionReader;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Application\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * PR13 — facture d'abonnement en PDF (copie archivée, empreinte vérifiée ; à défaut, page de facture Stripe) et accès
 * au portail client Stripe pour gérer cartes et mandats SEPA (PR13.2). Uniquement les factures de l'artisan connecté.
 */
final class ProInvoicesController extends AbstractController
{
    public function __construct(
        private readonly Invoices $invoices,
        private readonly InvoiceArchive $archive,
        private readonly SubscriptionReader $reader,
        private readonly PaymentGateway $gateway,
        private readonly CurrentUser $user,
    ) {
    }

    #[Route('/espace-pro/abonnement/factures/{id}/pdf', name: 'pro_invoice_pdf', methods: ['GET'])]
    public function pdf(string $id): Response
    {
        $invoice = $this->invoices->find($this->artisanId(), $id) ?? throw new NotFoundHttpException();
        if (null === $invoice['pdf_key'] || null === $invoice['pdf_sha256']) {
            $url = $invoice['view']->hostedUrl ?? throw new NotFoundHttpException('Facture pas encore disponible.');

            return new RedirectResponse($url);
        }
        $bytes = $this->archive->get($invoice['pdf_key']);
        if (!hash_equals($invoice['pdf_sha256'], hash('sha256', $bytes))) {
            throw new \RuntimeException('Copie de facture altérée.');
        }
        $name = 'facture-'.preg_replace('/[^A-Za-z0-9-]/', '-', $invoice['view']->number ?? $id).'.pdf';

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    #[Route('/espace-pro/abonnement/moyen-de-paiement', name: 'pro_payment_portal', methods: ['POST'])]
    public function portal(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('pro_payment_portal', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }
        $customer = $this->invoices->customerOfArtisan($this->artisanId());
        if (null === $customer) {
            $this->addFlash('error', 'Un moyen de paiement s’ajoute lors de votre première souscription.');

            return $this->redirectToRoute('pro_subscription', [], Response::HTTP_SEE_OTHER);
        }
        try {
            $url = $this->gateway->createPortalSession($customer, $this->generateUrl('pro_subscription', [], UrlGeneratorInterface::ABSOLUTE_URL).'#paiement');
        } catch (ExternalServiceUnavailable) {
            $this->addFlash('error', 'Le service de paiement ne répond pas. Réessayez dans quelques instants.');

            return $this->redirectToRoute('pro_subscription', [], Response::HTTP_SEE_OTHER);
        }

        return new RedirectResponse($url, Response::HTTP_SEE_OTHER);
    }

    private function artisanId(): string
    {
        $userId = $this->user->id() ?? throw new AccessDeniedHttpException();

        return $this->reader->artisanOfUser($userId) ?? throw new NotFoundHttpException();
    }
}
