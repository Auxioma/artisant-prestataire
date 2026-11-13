<?php

declare(strict_types=1);

namespace App\Quote\UI\Http;

use App\Quote\Application\Pro\ProQuoteFilter;
use App\Quote\Application\Pro\ProQuoteRow;
use App\Quote\Application\Pro\ProQuotes;
use App\Quote\Application\Pro\ProQuotesPage;
use App\Quote\Domain\Quote\QuoteStatus;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Text\FrenchNumber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PR05 (/espace-pro/devis, Figma 167:1749) — « Mes devis & chantiers » : onglets Devis / Chantiers, recherche, filtres
 * (statut, période, métier), cartes avec avancement, « en chiffres » ; export CSV des devis filtrés (PR05.1).
 */
final class ProQuotesController extends AbstractController
{
    private const int EXPORT_MAX = 5000;
    private const string CSV_SEPARATOR = ';';

    public function __construct(private readonly QueryBus $queries, private readonly CurrentUser $user)
    {
    }

    #[Route('/espace-pro/devis', name: 'pro_quotes', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = self::filter($request);
        $page = $this->queries->ask(new ProQuotes($this->userId(), $filter, max(1, $request->query->getInt('page', 1))));

        return $this->render('pro/quotes/index.html.twig', [
            'page' => $page instanceof ProQuotesPage ? $page : null,
            'filter' => $filter,
            'active' => array_filter(['onglet' => ProQuoteFilter::TAB_QUOTES === $filter->tab ? null : $filter->tab, 'q' => $filter->q, 'statut' => $filter->status, 'periode' => null === $filter->days ? null : (string) $filter->days, 'metier' => $filter->jobId]),
            'statuses' => array_filter(QuoteStatus::cases(), static fn (QuoteStatus $s): bool => QuoteStatus::Withdrawn !== $s),
            'periods' => ['30' => '30 derniers jours', '90' => '3 derniers mois', '365' => '12 derniers mois'],
        ]);
    }

    /** PR05.1 — export CSV (séparateur « ; », UTF-8 avec BOM pour les tableurs) des devis correspondant aux filtres. */
    #[Route('/espace-pro/devis/export.csv', name: 'pro_quotes_export', methods: ['GET'])]
    public function export(Request $request, AuditTrail $audit, IdGenerator $ids): Response
    {
        $userId = $this->userId();
        $page = $this->queries->ask(new ProQuotes($userId, self::filter($request), 1, self::EXPORT_MAX));
        $rows = $page instanceof ProQuotesPage ? $page->items : [];
        $audit->record(new AuditRecord(id: $ids->next(), occurredAt: new \DateTimeImmutable(), actorType: 'user', actorId: $userId, action: 'quote.exported', subjectType: 'user', subjectId: $userId, context: ['rows' => \count($rows)]));

        $response = new StreamedResponse(static function () use ($rows): void {
            $out = fopen('php://output', 'w');
            \assert(false !== $out);
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Numéro', 'Demande', 'Client', 'Commune', 'Métier', 'Montant TTC', 'Date d’envoi', 'Validité', 'Statut', 'Version'], self::CSV_SEPARATOR, '"', '');
            foreach ($rows as $row) {
                \assert($row instanceof ProQuoteRow);
                fputcsv($out, array_map(self::cell(...), [
                    $row->number ?? '',
                    $row->requestNumber.' — '.$row->title,
                    $row->clientFirstName ?? '',
                    $row->city,
                    $row->jobName,
                    FrenchNumber::money($row->ttcCents),
                    $row->sentAt?->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y') ?? '',
                    $row->validUntil?->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y') ?? '',
                    $row->status->label(),
                    'v'.$row->version,
                ]), self::CSV_SEPARATOR, '"', '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="mes-devis.csv"');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /** Neutralise les formules des tableurs (= + - @) dans les valeurs saisies par des tiers. */
    private static function cell(string $value): string
    {
        return '' !== $value && \in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    private static function filter(Request $request): ProQuoteFilter
    {
        $days = ProQuoteFilter::PERIODS[$request->query->getString('periode')] ?? null;
        $text = static fn (string $key): ?string => '' === trim($request->query->getString($key)) ? null : mb_substr(trim($request->query->getString($key)), 0, 80);

        return new ProQuoteFilter(
            ProQuoteFilter::TAB_WORKS === $request->query->getString('onglet') ? ProQuoteFilter::TAB_WORKS : ProQuoteFilter::TAB_QUOTES,
            $text('q'),
            $text('statut'),
            $days,
            $text('metier'),
        );
    }

    private function userId(): string
    {
        return $this->user->id() ?? throw new AccessDeniedHttpException();
    }
}
