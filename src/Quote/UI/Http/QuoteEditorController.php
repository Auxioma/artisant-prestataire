<?php

declare(strict_types=1);

namespace App\Quote\UI\Http;

use App\Quote\Application\Editor\QuoteEditor;
use App\Quote\Application\Editor\QuoteEditorView;
use App\Quote\Application\Editor\SaveQuoteDraft;
use App\Quote\Domain\Mention\MentionCheck;
use App\Quote\Domain\Quote\LineType;
use App\Quote\Domain\Quote\QuoteLine;
use App\Quote\Domain\Quote\QuoteRuleViolation;
use App\Quote\Domain\Quote\VatRate;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\UI\Twig\MoneyExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PR04 (/espace-pro/devis/nouveau?demande={id}, Figma 22:229) — éditeur de devis : en-tête, lignes, récapitulatif,
 * conditions, contrôle RG-120. Brouillon enregistré à la demande ou automatiquement (JSON : totaux et contrôles mis à
 * jour) ; aucun crédit consommé (RG-100). Aperçu PDF (T-503) et envoi (T-504) avec leurs tickets.
 */
#[Route('/espace-pro/devis')]
final class QuoteEditorController extends AbstractController
{
    private const string CSRF = 'quote_editor';
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private readonly QueryBus $queries, private readonly CommandBus $commands, private readonly CurrentUser $user, private readonly IdGenerator $ids)
    {
    }

    #[Route('/nouveau', name: 'pro_quote_new', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $requestId = $this->requestId($request);
        $error = null;
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);
            try {
                $this->save($request, $requestId);
                $this->addFlash('success', 'Brouillon enregistré : il ne consomme aucun crédit.');

                return $this->redirectToRoute('pro_quote_new', ['demande' => $requestId], Response::HTTP_SEE_OTHER);
            } catch (QuoteRuleViolation $e) {
                $error = $e->getMessage();
            }
        }
        $view = $this->view($requestId);

        return $this->render('quote/editor.html.twig', [
            'view' => $view,
            'posted' => null === $error ? null : $request->request->all(),
            'error' => $error,
            'line_types' => LineType::cases(),
            'vat_rates' => VatRate::cases(),
            'csrf_id' => self::CSRF,
            'quantity_scale' => 3,
        ], new Response(null, null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /** Enregistrement automatique : renvoie les totaux, les totaux de ligne et le contrôle RG-120 recalculés. */
    #[Route('/brouillon', name: 'pro_quote_autosave', methods: ['POST'])]
    public function autosave(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            return new JsonResponse(['error' => 'Session expirée : rechargez la page.'], Response::HTTP_FORBIDDEN);
        }
        try {
            $this->save($request, $requestId);
        } catch (QuoteRuleViolation $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $view = $this->view($requestId);
        $content = $view->content;
        $totals = $content?->totals;

        return new JsonResponse([
            'saved_at' => $view->savedAt?->setTimezone(new \DateTimeZone('Europe/Paris'))->format('H:i'),
            'totals' => null === $totals ? null : [
                'ht' => MoneyExtension::money($totals->ht->amount),
                'vat' => $content->vatExempt ? 'Non applicable' : MoneyExtension::money($totals->vat->amount),
                'ttc' => MoneyExtension::money($totals->ttc->amount),
                'discount' => $totals->discount->amount > 0 ? MoneyExtension::money(-$totals->discount->amount) : null,
                'deposit' => null === $totals->deposit ? null : MoneyExtension::money($totals->deposit->amount),
            ],
            'lines' => null === $content ? [] : array_map(static fn (QuoteLine $l): string => $l->type->isPriced() ? MoneyExtension::money($l->totalHt()->amount) : '', $content->lines),
            'mentions' => array_map(static fn (MentionCheck $c): array => ['label' => $c->label, 'ok' => $c->ok], $view->mentions),
            'complete' => $view->complete(),
            'certificate' => true === $content?->needsClientCertificate(),
        ]);
    }

    private function save(Request $request, string $requestId): void
    {
        $input = $request->request->all();
        unset($input['_token']);
        $this->commands->dispatch(new SaveQuoteDraft($this->userId(), $requestId, $this->ids->next(), $input));
    }

    private function view(string $requestId): QuoteEditorView
    {
        $view = $this->queries->ask(new QuoteEditor($this->userId(), $requestId));
        if (!$view instanceof QuoteEditorView) {
            throw new NotFoundHttpException();
        }

        return $view;
    }

    private function requestId(Request $request): string
    {
        $id = $request->query->getString('demande');
        if (1 !== preg_match(self::UUID, $id)) {
            throw new NotFoundHttpException();
        }

        return $id;
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
