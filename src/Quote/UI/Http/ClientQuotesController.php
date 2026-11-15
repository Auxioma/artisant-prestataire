<?php

declare(strict_types=1);

namespace App\Quote\UI\Http;

use App\Quote\Application\Appointment\QuoteAppointment;
use App\Quote\Application\Client\AcceptQuote;
use App\Quote\Application\Client\CancelAcceptance;
use App\Quote\Application\Client\ClientQuotePdf;
use App\Quote\Application\Client\ClientQuotes;
use App\Quote\Application\Client\ClientRequestQuotes;
use App\Quote\Application\Client\MarkQuoteViewed;
use App\Quote\Application\Client\RefuseQuote;
use App\Quote\Domain\Quote\ClientRefusalReason;
use App\Quote\Domain\Quote\QuoteRuleViolation;
use App\QuoteRequest\Application\Port\ClientRequestReader;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * C03 (/espace-client/demandes/{id}, Figma 22:80) — comparateur des devis reçus : cartes, comparatif détaillé, « Voir le
 * devis détaillé » (PDF figé, devis marqué consulté), « Choisir ce professionnel » (modale 195:9075, RG-071/072),
 * « Refuser » (modale motivée C03.5). Le client ne voit que ses demandes.
 */
final class ClientQuotesController extends AbstractController
{
    private const string CSRF = 'client_quotes';
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(private readonly QueryBus $queries, private readonly CommandBus $commands, private readonly CurrentUser $user, private readonly SettingsProvider $settings)
    {
    }

    #[Route('/espace-client/demandes/{id}', name: 'client_request', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function show(string $id, ClientRequestReader $reader): Response
    {
        $view = $this->queries->ask(new ClientRequestQuotes($this->userId(), $id));
        if (!$view instanceof ClientQuotes) {
            throw new NotFoundHttpException('Demande introuvable.');
        }

        $accepted = $view->accepted();

        return $this->render('client/quotes/compare.html.twig', [
            'view' => $view,
            'appointment' => null === $accepted ? null : $this->queries->ask(new QuoteAppointment($this->userId(), $accepted->quoteId)),
            'today' => new \DateTimeImmutable(),
            'cancel_min_hours' => $this->settings->int(SettingKey::QuotesCancelAcceptanceMinHoursBeforeAppointment),
            'reasons' => ClientRefusalReason::cases(),
            'csrf_id' => self::CSRF,
            'space_counts' => ['requests' => $reader->stats($this->userId())['active']],
        ]);
    }

    /** « Voir le devis détaillé » : PDF figé de la version courante ; la première ouverture marque le devis consulté. */
    #[Route('/espace-client/devis/{quote}/pdf', name: 'client_quote_pdf', requirements: ['quote' => self::UUID], methods: ['GET'])]
    public function pdf(string $quote): Response
    {
        $bytes = $this->queries->ask(new ClientQuotePdf($this->userId(), $quote));
        if (!\is_string($bytes)) {
            throw new NotFoundHttpException();
        }
        $this->commands->dispatch(new MarkQuoteViewed($this->userId(), $quote));

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="devis.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    #[Route('/espace-client/devis/{quote}/accepter', name: 'client_quote_accept', requirements: ['quote' => self::UUID], methods: ['POST'])]
    public function accept(Request $request, string $quote): Response
    {
        return $this->decide($request, new AcceptQuote($this->userId(), $quote), 'Devis accepté : le professionnel reçoit vos coordonnées et vous recevez les siennes par e-mail.');
    }

    #[Route('/espace-client/devis/{quote}/refuser', name: 'client_quote_refuse', requirements: ['quote' => self::UUID], methods: ['POST'])]
    public function refuse(Request $request, string $quote): Response
    {
        $comment = trim($request->request->getString('comment'));

        return $this->decide($request, new RefuseQuote($this->userId(), $quote, $request->request->getString('reason'), '' === $comment ? null : $comment), 'Devis refusé : le professionnel est prévenu.');
    }

    /** RG-072 — annulation de l'acceptation avec motif, jusqu'à 48 h avant le rendez-vous confirmé. */
    #[Route('/espace-client/devis/{quote}/annuler-acceptation', name: 'client_quote_cancel_acceptance', requirements: ['quote' => self::UUID], methods: ['POST'])]
    public function cancelAcceptance(Request $request, string $quote): Response
    {
        return $this->decide($request, new CancelAcceptance($this->userId(), $quote, $request->request->getString('motif')), 'Acceptation annulée : le professionnel est prévenu et votre demande est de nouveau ouverte aux devis.');
    }

    private function decide(Request $request, AcceptQuote|RefuseQuote|CancelAcceptance $command, string $success): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
        try {
            $this->commands->dispatch($command);
            $this->addFlash('success', $success);
        } catch (QuoteRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }
        $back = $request->request->getString('back');

        return $this->redirect(1 === preg_match('#^/espace-client/demandes/'.self::UUID.'$#', $back) ? $back : $this->generateUrl('client_requests'), Response::HTTP_SEE_OTHER);
    }

    private function userId(): string
    {
        return $this->user->id() ?? throw new AccessDeniedHttpException();
    }
}
