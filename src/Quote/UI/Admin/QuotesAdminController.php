<?php

declare(strict_types=1);

namespace App\Quote\UI\Admin;

use App\Quote\Application\Admin\AdminQuotes;
use App\Quote\Application\Admin\AdminQuotesPage;
use App\Quote\Application\Pdf\QuotePdfService;
use App\Quote\Domain\Quote\QuoteId;
use App\Quote\Domain\Quote\QuoteRepository;
use App\Quote\Domain\Quote\QuoteStatus;
use App\QuoteRequest\Application\Admin\AdminRequestList;
use App\QuoteRequest\Application\Admin\AdminRequestPage;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentAdministrator;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO04.2 (onglet « Devis » de 186:1669) — devis envoyés : n°, demande, artisan, montant TTC, statut, version, dates ;
 * recherche et statut ; PDF figé de la version courante (consultation journalisée).
 */
#[Route('/admin')]
final class QuotesAdminController extends AbstractController
{
    public function __construct(private readonly QueryBus $queries, private readonly CurrentAdministrator $admin)
    {
    }

    #[Route('/devis', name: 'admin_quotes', methods: ['GET'])]
    #[IsGranted('admin.requests.view')]
    public function index(Request $request): Response
    {
        $q = trim($request->query->getString('q'));
        $status = $request->query->getString('statut');
        $page = $this->queries->ask(new AdminQuotes('' === $q ? null : mb_substr($q, 0, 80), '' === $status ? null : $status, max(1, $request->query->getInt('page', 1))));
        $requests = $this->queries->ask(new AdminRequestList(perPage: 1));
        \assert($page instanceof AdminQuotesPage && $requests instanceof AdminRequestPage);

        return $this->render('admin/requests/quotes.html.twig', [
            'page' => $requests,
            'quotes' => $page,
            'filters' => array_filter(['q' => $q, 'statut' => $status]),
            'statuses' => array_filter(QuoteStatus::cases(), static fn (QuoteStatus $s): bool => QuoteStatus::Draft !== $s),
        ]);
    }

    #[Route('/devis/{id}/pdf', name: 'admin_quote_pdf', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    #[IsGranted('admin.requests.view')]
    public function pdf(string $id, QuoteRepository $quotes, QuotePdfService $pdf, AuditTrail $audit, IdGenerator $ids): Response
    {
        $quote = $quotes->find(new QuoteId($id));
        $file = null === $quote || QuoteStatus::Draft === $quote->status() ? null : $quote->current()->pdf;
        if (null === $file) {
            throw new NotFoundHttpException();
        }
        $audit->record(new AuditRecord(id: $ids->next(), occurredAt: new \DateTimeImmutable(), actorType: 'admin', actorId: $this->admin->id() ?? throw new AccessDeniedHttpException(), action: 'quote.pdf_viewed', subjectType: 'quote', subjectId: $id, context: ['number' => $quote?->number()]));

        return new Response($pdf->read($file), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="devis.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
