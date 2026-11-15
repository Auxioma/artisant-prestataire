<?php

declare(strict_types=1);

namespace App\Notification\UI\Admin;

use App\Notification\Application\Admin\DeliveryLogPage;
use App\Notification\Application\Admin\DeliveryLogQuery;
use App\Notification\Application\Admin\PreviewTemplate;
use App\Notification\Application\Admin\TemplateAdminCommand;
use App\Notification\Application\Admin\TemplateAdminQuery;
use App\Notification\Application\Admin\TemplateAdminRejected;
use App\Notification\Application\Admin\TemplateDetail;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentAdministrator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO11 « Emails, notifications et newsletter » (/admin/communications, Figma 186:4802) : modèles (liste filtrée,
 * édition avec variables, aperçu rendu, version texte, envoi de test, enregistrement versionné) et journal d'envoi
 * (filtres, export CSV). Newsletter : BO11.3 (décision P-134). Écarts : P-136.
 */
#[Route('/admin/communications')]
final class CommunicationsController extends AbstractController
{
    private const string CSRF = 'admin_communications';
    private const string DRAFT = 'bo11.preview_draft';
    private const int PER_PAGE = 6;
    private const string CODE = 'E-[0-9]{2}[a-z]?|I-[0-9]{2}';

    public function __construct(private readonly QueryBus $queries, private readonly CommandBus $commands, private readonly CurrentAdministrator $admin)
    {
    }

    #[Route('', name: 'admin_notifications', methods: ['GET'])]
    #[IsGranted('admin.notifications.view')]
    public function index(Request $request): Response
    {
        $all = $this->templates();
        $q = mb_strtolower(trim($request->query->getString('q')));
        $recipient = $request->query->getString('destinataire');
        $channel = $request->query->getString('canal');
        $filtered = array_values(array_filter($all, static fn (TemplateDetail $t): bool => ('' === $q || str_contains(mb_strtolower($t->code.' '.$t->label), $q))
            && ('' === $recipient || str_contains(mb_strtolower($t->recipient), mb_strtolower($recipient)))
            && ('' === $channel || $t->has($channel))));
        $pages = max(1, (int) ceil(\count($filtered) / self::PER_PAGE));
        $page = min($pages, max(1, $request->query->getInt('page', 1)));
        $code = $request->query->getString('modele');
        $current = $this->detail('' !== $code ? $code : ($filtered[0]->code ?? ($all[0]->code ?? '')));
        $log = $this->queries->ask(new DeliveryLogQuery(
            '' === $request->query->getString('journal_modele') ? null : $request->query->getString('journal_modele'),
            '' === $request->query->getString('journal_statut') ? null : $request->query->getString('journal_statut'),
            '' === $request->query->getString('journal_destinataire') ? null : $request->query->getString('journal_destinataire'),
            null,
            max(1, $request->query->getInt('journal_page', 1)),
        ));
        \assert($log instanceof DeliveryLogPage);
        $recipients = array_values(array_unique(array_map(static fn (TemplateDetail $t): string => $t->recipient, $all)));
        sort($recipients);

        return $this->render('admin/communications/index.html.twig', [
            'all_count' => \count($all),
            'templates' => \array_slice($filtered, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'filtered_count' => \count($filtered),
            'page' => $page,
            'pages' => $pages,
            'per_page' => self::PER_PAGE,
            'recipients' => $recipients,
            'current' => $current['template'] ?? null,
            'versions' => $current['versions'] ?? [],
            'draft' => null !== $current && $request->query->getBoolean('brouillon') ? $request->getSession()->get(self::DRAFT.'.'.$current['template']->code) : null,
            'text_view' => 'texte' === $request->query->getString('vue'),
            'log' => $log,
            'statuses' => DeliveryLogPage::STATUSES,
            'filters' => $request->query->all(),
            'csrf_id' => self::CSRF,
        ]);
    }

    #[Route('/modeles/{code}', name: 'admin_notifications_template', requirements: ['code' => self::CODE], methods: ['POST'])]
    #[IsGranted('admin.notifications.edit')]
    public function action(Request $request, string $code): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }
        $action = $request->request->getString('action');
        /** @var array<string, mixed> $data */
        $data = $request->request->all('data');
        $data['active'] = $request->request->getBoolean('active');
        if ('preview' === $action) {
            $request->getSession()->set(self::DRAFT.'.'.$code, $data);

            return $this->redirectToRoute('admin_notifications', ['modele' => $code, 'brouillon' => 1, '_fragment' => 'editeur'], Response::HTTP_SEE_OTHER);
        }
        if ('restore' === $action) {
            $data = ['version' => $request->request->getInt('version')];
        }
        if ('test' === $action) {
            $data = ['to' => $this->getUser()?->getUserIdentifier() ?? ''];
        }
        try {
            $this->commands->dispatch(new TemplateAdminCommand($action, $code, $this->admin->id(), $data));
            $request->getSession()->remove(self::DRAFT.'.'.$code);
            $this->addFlash('success', match ($action) {
                'test' => 'E-mail de test envoyé à votre adresse.',
                'restore' => 'Version restaurée (enregistrée comme nouvelle version).',
                default => 'Modèle enregistré : nouvelle version en service.',
            });
        } catch (TemplateAdminRejected $e) {
            $this->addFlash('error', $e->getMessage());
            if ('save' === $action) {
                $request->getSession()->set(self::DRAFT.'.'.$code, $data);

                return $this->redirectToRoute('admin_notifications', ['modele' => $code, 'brouillon' => 1, '_fragment' => 'editeur'], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->redirectToRoute('admin_notifications', ['modele' => $code, '_fragment' => 'editeur'], Response::HTTP_SEE_OTHER);
    }

    /** Aperçu rendu (cadre isolé : CSP dédiée, PreviewCspSubscriber) — enregistré, ou brouillon de la session. */
    #[Route('/modeles/{code}/apercu', name: 'admin_notifications_preview', requirements: ['code' => self::CODE], methods: ['GET'])]
    #[IsGranted('admin.notifications.view')]
    public function preview(Request $request, string $code): Response
    {
        $draft = $request->query->getBoolean('brouillon') ? $request->getSession()->get(self::DRAFT.'.'.$code) : null;
        /** @var array<string, mixed>|null $content */
        $content = \is_array($draft) ? $draft : null;
        $preview = $this->queries->ask(new PreviewTemplate($code, $content));
        \assert(\is_array($preview));
        $text = static fn (string $key, string $default): string => \is_string($preview[$key] ?? null) ? $preview[$key] : $default;
        if (\is_string($preview['error'] ?? null)) {
            $html = '<p style="font-family:sans-serif"><strong>Modèle invalide</strong> — '.htmlspecialchars($preview['error']).'</p>';
        } elseif ('texte' === $request->query->getString('vue')) {
            $html = '<pre style="font-family:monospace;white-space:pre-wrap;margin:16px">'.htmlspecialchars($text('text', 'Aucune version texte.')).'</pre>';
        } else {
            $html = \is_string($preview['html'] ?? null) ? $preview['html'] : '<p style="font-family:sans-serif">Ce déclencheur n’envoie pas d’e-mail : '.htmlspecialchars($text('in_app', '')).'</p>';
        }

        return new Response($html, Response::HTTP_OK, ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }

    #[Route('/journal.csv', name: 'admin_notifications_log_export', methods: ['GET'])]
    #[IsGranted('admin.notifications.view')]
    public function export(Request $request): StreamedResponse
    {
        $queries = $this->queries;
        $filters = [$request->query->getString('journal_modele') ?: null, $request->query->getString('journal_statut') ?: null, $request->query->getString('journal_destinataire') ?: null];

        return new StreamedResponse(static function () use ($queries, $filters): void {
            $out = fopen('php://output', 'w');
            \assert(false !== $out);
            fputcsv($out, ['date', 'destinataire', 'modele', 'version', 'canal', 'statut', 'erreur'], ';', '"', '');
            $page = 1;
            do {
                $result = $queries->ask(new DeliveryLogQuery($filters[0], $filters[1], $filters[2], null, $page));
                \assert($result instanceof DeliveryLogPage);
                foreach ($result->rows as $row) {
                    fputcsv($out, [$row['at']->format(\DATE_ATOM), $row['recipient'] ?? '', $row['code'], $row['version'], $row['channel'], $row['status'], $row['error'] ?? ''], ';', '"', '');
                }
                ++$page;
            } while ($page <= $result->pages());
            fclose($out);
        }, Response::HTTP_OK, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="journal-envois.csv"', 'Cache-Control' => 'no-store']);
    }

    /** @return list<TemplateDetail> */
    private function templates(): array
    {
        $list = $this->queries->ask(new TemplateAdminQuery());

        return \is_array($list) ? array_values(array_filter($list, static fn (mixed $t): bool => $t instanceof TemplateDetail)) : [];
    }

    /** @return array{template: TemplateDetail, versions: list<\App\Notification\Application\Admin\TemplateVersion>}|null */
    private function detail(string $code): ?array
    {
        if ('' === $code) {
            return null;
        }
        $detail = $this->queries->ask(new TemplateAdminQuery($code));
        if (null === $detail) {
            throw new NotFoundHttpException();
        }
        \assert(\is_array($detail) && $detail['template'] instanceof TemplateDetail);

        /** @var array{template: TemplateDetail, versions: list<\App\Notification\Application\Admin\TemplateVersion>} $detail */
        return $detail;
    }
}
