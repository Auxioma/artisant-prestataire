<?php

declare(strict_types=1);

namespace App\Quote\UI\Http;

use App\Quote\Application\Editor\QuoteEditor;
use App\Quote\Application\Editor\QuoteEditorView;
use App\Quote\Application\Editor\SaveQuoteDraft;
use App\Quote\Application\Pdf\QuotePreviewPdf;
use App\Quote\Application\Pdf\QuoteVersionPdf;
use App\Quote\Application\Revision\PreviewRevision;
use App\Quote\Application\Revision\ReviseQuote;
use App\Quote\Application\Revision\RevisionPreview;
use App\Quote\Application\Revision\WithdrawQuote;
use App\Quote\Application\Sending\SendQuote;
use App\Quote\Domain\Mention\MentionCheck;
use App\Quote\Domain\Quote\LineType;
use App\Quote\Domain\Quote\QuoteContent;
use App\Quote\Domain\Quote\QuoteLine;
use App\Quote\Domain\Quote\QuoteRuleViolation;
use App\Quote\Domain\Quote\QuoteStatus;
use App\Quote\Domain\Quote\VatRate;
use App\Quote\Domain\Sending\QuoteSendingRefused;
use App\Quote\Domain\Sending\RefusalReason;
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
 * jour) ; aucun crédit consommé (RG-100). Aperçu PDF du brouillon (PR04.4). Envoi (PR04.5) après la modale de
 * confirmation (Figma 74:121) : le formulaire est enregistré puis envoyé (RG-101) ; un devis envoyé s'affiche en lecture.
 * « Modifier le devis » (PR05, Figma 167:1749) : révision v2, v3… (RG-102) ; « Retirer le devis » avant consultation
 * (RG-103).
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

        return $this->renderEditor($view, $request, $error, $request->query->getBoolean('reviser') && $view->status->isRevisable());
    }

    /** RG-102 — « Envoyer la révision » (confirmée) : nouvelle version figée, envoyée au client (E-12b), sans crédit. */
    #[Route('/reviser', name: 'pro_quote_revise', methods: ['POST'])]
    public function revise(Request $request): Response
    {
        $requestId = $this->requestId($request);
        $this->assertCsrf($request);
        $input = $request->request->all();
        unset($input['_token']);
        try {
            $this->commands->dispatch(new ReviseQuote($this->userId(), $requestId, $input));
        } catch (QuoteRuleViolation $e) {
            return $this->renderEditor($this->view($requestId), $request, $e->getMessage(), true);
        }
        $view = $this->view($requestId);
        $this->addFlash('success', \sprintf('Version %d du devis %s envoyée au client.', $view->version, $view->number ?? ''));

        return $this->redirectToRoute('pro_quote_new', ['demande' => $requestId], Response::HTTP_SEE_OTHER);
    }

    /** Révision en cours : totaux et contrôle RG-120 recalculés sans rien enregistrer. */
    #[Route('/reviser/apercu', name: 'pro_quote_revise_preview', methods: ['POST'])]
    public function revisePreview(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            return new JsonResponse(['error' => 'Session expirée : rechargez la page.'], Response::HTTP_FORBIDDEN);
        }
        $input = $request->request->all();
        unset($input['_token']);
        try {
            $preview = $this->queries->ask(new PreviewRevision($this->userId(), $requestId, $input));
        } catch (QuoteRuleViolation $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$preview instanceof RevisionPreview) {
            throw new NotFoundHttpException();
        }

        return new JsonResponse(['saved_at' => null] + self::recomputed($preview->content, $preview->mentions, $preview->complete()));
    }

    /** RG-103 — « Retirer le devis » (confirmé) : avant consultation ; la place se libère, le crédit n'est pas restitué. */
    #[Route('/retirer', name: 'pro_quote_withdraw', methods: ['POST'])]
    public function withdraw(Request $request): Response
    {
        $requestId = $this->requestId($request);
        $this->assertCsrf($request);
        try {
            $this->commands->dispatch(new WithdrawQuote($this->userId(), $requestId));
        } catch (QuoteRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('pro_quote_new', ['demande' => $requestId], Response::HTTP_SEE_OTHER);
        }
        $this->addFlash('success', 'Devis retiré : la place est libérée sur la demande. Le crédit utilisé n’est pas restitué.');

        return $this->redirectToRoute('pro_request', ['id' => $requestId], Response::HTTP_SEE_OTHER);
    }

    /**
     * PR04.5 — « Envoyer le devis » (confirmé dans la modale) : enregistre le formulaire, puis envoi atomique RG-101.
     * Refus : brouillon conservé, message RG-101 ; sans crédit, renvoi vers les forfaits (P13).
     */
    #[Route('/envoyer', name: 'pro_quote_send', methods: ['POST'])]
    public function send(Request $request): Response
    {
        $requestId = $this->requestId($request);
        $this->assertCsrf($request);
        try {
            $this->save($request, $requestId);
        } catch (QuoteRuleViolation $e) {
            return $this->renderEditor($this->view($requestId), $request, $e->getMessage());
        }
        try {
            $this->commands->dispatch(new SendQuote($this->userId(), $requestId));
        } catch (QuoteSendingRefused $e) {
            $this->addFlash('error', $e->getMessage());
            if (RefusalReason::NoCredit === $e->reason) {
                return $this->redirectToRoute('content_pricing', [], Response::HTTP_SEE_OTHER);
            }

            return $this->redirectToRoute('pro_quote_new', ['demande' => $requestId], Response::HTTP_SEE_OTHER);
        }
        $view = $this->view($requestId);
        $this->addFlash('success', \sprintf('Devis %s envoyé au client.', $view->number ?? ''));

        return $this->redirectToRoute('pro_quote_new', ['demande' => $requestId], Response::HTTP_SEE_OTHER);
    }

    private function renderEditor(QuoteEditorView $view, Request $request, ?string $error, bool $revising = false): Response
    {
        return $this->render('quote/editor.html.twig', [
            'view' => $view,
            'posted' => null === $error ? null : $request->request->all(),
            'error' => $error,
            'line_types' => LineType::cases(),
            'vat_rates' => VatRate::cases(),
            'csrf_id' => self::CSRF,
            'quantity_scale' => 3,
            'sent' => QuoteStatus::Draft !== $view->status && !$revising,
            'revising' => $revising,
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

        return new JsonResponse([
            'saved_at' => $view->savedAt?->setTimezone(new \DateTimeZone('Europe/Paris'))->format('H:i'),
        ] + self::recomputed($view->content, $view->mentions, $view->complete()));
    }

    /**
     * Totaux, totaux de ligne et contrôle RG-120 pour l'éditeur (brouillon ou révision).
     *
     * @param list<MentionCheck> $mentions
     *
     * @return array<string, mixed>
     */
    private static function recomputed(?QuoteContent $content, array $mentions, bool $complete): array
    {
        $totals = $content?->totals;

        return [
            'totals' => null === $totals ? null : [
                'ht' => MoneyExtension::money($totals->ht->amount),
                'vat' => $content->vatExempt ? 'Non applicable' : MoneyExtension::money($totals->vat->amount),
                'ttc' => MoneyExtension::money($totals->ttc->amount),
                'discount' => $totals->discount->amount > 0 ? MoneyExtension::money(-$totals->discount->amount) : null,
                'deposit' => null === $totals->deposit ? null : MoneyExtension::money($totals->deposit->amount),
            ],
            'lines' => null === $content ? [] : array_map(static fn (QuoteLine $l): string => $l->type->isPriced() ? MoneyExtension::money($l->totalHt()->amount) : '', $content->lines),
            'mentions' => array_map(static fn (MentionCheck $c): array => ['label' => $c->label, 'ok' => $c->ok], $mentions),
            'complete' => $complete,
            'certificate' => true === $content?->needsClientCertificate(),
        ];
    }

    /** PR04.4 — aperçu du brouillon enregistré (filigrane, sans numéro), ouvert dans le navigateur. */
    #[Route('/apercu', name: 'pro_quote_preview', methods: ['GET'])]
    public function preview(Request $request): Response
    {
        $bytes = $this->queries->ask(new QuotePreviewPdf($this->userId(), $this->requestId($request)));
        if (!\is_string($bytes)) {
            throw new NotFoundHttpException('Enregistrez d’abord le brouillon.');
        }

        return self::pdf($bytes, 'apercu-devis.pdf');
    }

    /** PDF figé d'une version envoyée (empreinte vérifiée), pour l'artisan auteur. */
    #[Route('/{id}/pdf/{version}', name: 'pro_quote_pdf', requirements: ['id' => '[0-9a-f-]{36}', 'version' => '\d{1,3}'], methods: ['GET'])]
    public function versionPdf(string $id, int $version): Response
    {
        try {
            $bytes = $this->queries->ask(new QuoteVersionPdf($this->userId(), $id, $version));
        } catch (QuoteRuleViolation $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }
        if (!\is_string($bytes)) {
            throw new NotFoundHttpException();
        }

        return self::pdf($bytes, \sprintf('devis-v%d.pdf', $version));
    }

    private static function pdf(string $bytes, string $name): Response
    {
        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => \sprintf('inline; filename="%s"', $name),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
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
