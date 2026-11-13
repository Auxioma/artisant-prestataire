<?php

declare(strict_types=1);

namespace App\QuoteRequest\UI\Http;

use App\QuoteRequest\Application\Client\ClientRequestPage;
use App\QuoteRequest\Application\Client\ClientRequests;
use App\QuoteRequest\Application\Client\CloseQuoteRequest;
use App\QuoteRequest\Application\Client\CloseReason;
use App\QuoteRequest\Application\Client\DuplicateQuoteRequest;
use App\QuoteRequest\Application\Client\RelaunchQuoteRequest;
use App\QuoteRequest\Application\Port\ClientRequestReader;
use App\QuoteRequest\Domain\Request\QuoteRequest;
use App\QuoteRequest\Domain\Request\RequestRuleViolation;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Application\Storage\FileReader;
use App\Shared\Application\Storage\StoredFileNotFound;
use App\Shared\Domain\Identifier\IdGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * C02 (/espace-client/demandes, Figma 159:3905) — demandes du client : groupes (en cours, terminées, expirées ou
 * annulées, brouillons), recherche, période, cartes, « Clôturer » (motif), « Relancer » (RG-064), « Dupliquer ».
 */
#[Route('/espace-client/demandes')]
final class ClientRequestsController extends AbstractController
{
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
    private const string CSRF = 'client_requests';
    private const int PER_PAGE = 10;

    public function __construct(private readonly QueryBus $queries, private readonly CommandBus $commands, private readonly CurrentUser $user)
    {
    }

    #[Route('', name: 'client_requests', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $q = $request->query;
        $filters = [
            'q' => trim(mb_substr($q->getString('q'), 0, 80)),
            'statut' => \array_key_exists($q->getString('statut'), ClientRequests::GROUPS) ? $q->getString('statut') : '',
            'date' => \array_key_exists($q->getString('date'), ClientRequests::PERIODS) ? $q->getString('date') : '',
        ];
        $page = $this->queries->ask(new ClientRequests($this->userId(), '' === $filters['statut'] ? null : $filters['statut'], $filters['q'], '' === $filters['date'] ? null : (int) $filters['date'], max(1, $q->getInt('page', 1)), self::PER_PAGE));
        \assert($page instanceof ClientRequestPage);

        return $this->render('client/requests/index.html.twig', [
            'page' => $page,
            'filters' => $filters,
            'active' => array_filter($filters, static fn (string $v): bool => '' !== $v),
            'groups' => ClientRequests::GROUPS,
            'periods' => ClientRequests::PERIODS,
            'reasons' => CloseReason::cases(),
            'relaunch_max' => QuoteRequest::RELAUNCH_MAX,
            'csrf_id' => self::CSRF,
        ]);
    }

    #[Route('/{id}/cloturer', name: 'client_request_close', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function close(Request $request, string $id): Response
    {
        return $this->act($request, new CloseQuoteRequest($this->userId(), $id, $request->request->getString('reason')), 'Demande clôturée : les professionnels ne peuvent plus y répondre.');
    }

    #[Route('/{id}/relancer', name: 'client_request_relaunch', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function relaunch(Request $request, string $id): Response
    {
        return $this->act($request, new RelaunchQuoteRequest($this->userId(), $id), 'Demande relancée : elle est de nouveau proposée aux professionnels.');
    }

    /** Recopie la demande dans le brouillon du tunnel et ouvre son récapitulatif. */
    #[Route('/{id}/dupliquer', name: 'client_request_duplicate', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function duplicate(Request $request, string $id, IdGenerator $ids): Response
    {
        $this->assertCsrf($request);
        try {
            $this->commands->dispatch(new DuplicateQuoteRequest($this->userId(), $id, $ids->next()));
        } catch (RequestRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('client_requests', [], Response::HTTP_SEE_OTHER);
        }
        $this->addFlash('success', 'Demande dupliquée : vérifiez le récapitulatif, ajoutez vos photos si besoin, puis envoyez-la.');

        return $this->redirectToRoute('request_step', ['step' => 6], Response::HTTP_SEE_OTHER);
    }

    /** Vignette d'une photo de la demande, pour son seul propriétaire (fichier analysé, lecture journalisée). */
    #[Route('/{id}/photos/{file}', name: 'client_request_photo', requirements: ['id' => self::UUID, 'file' => self::UUID], methods: ['GET'])]
    public function photo(Request $request, string $id, string $file, ClientRequestReader $reader, FileReader $files): Response
    {
        if (!\in_array($file, $reader->photos($this->userId(), $id), true)) {
            throw new NotFoundHttpException();
        }
        try {
            $stored = $files->available($file);
            $stream = $files->openForActor($file, 'user', $this->userId(), $request->getClientIp());
        } catch (StoredFileNotFound) {
            throw new NotFoundHttpException();
        }

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, Response::HTTP_OK, ['Content-Type' => $stored->type->value, 'Content-Disposition' => 'inline', 'Cache-Control' => 'private, max-age=600', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function act(Request $request, CloseQuoteRequest|RelaunchQuoteRequest $command, string $success): Response
    {
        $this->assertCsrf($request);
        try {
            $this->commands->dispatch($command);
            $this->addFlash('success', $success);
        } catch (RequestRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }
        $back = $request->request->getString('back');

        return $this->redirect(str_starts_with($back, '/espace-client/demandes') ? $back : $this->generateUrl('client_requests'), Response::HTTP_SEE_OTHER);
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
    }

    private function userId(): string
    {
        return $this->user->id() ?? throw new AccessDeniedHttpException();
    }
}
