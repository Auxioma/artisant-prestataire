<?php

declare(strict_types=1);

namespace App\ArtisanProfile\UI\Http\Agenda;

use App\ArtisanProfile\Application\Agenda\AddAbsence;
use App\ArtisanProfile\Application\Agenda\AgendaCalendar;
use App\ArtisanProfile\Application\Agenda\AgendaCalendarView;
use App\ArtisanProfile\Application\Agenda\IcalLinkStatus;
use App\ArtisanProfile\Application\Agenda\IssueIcalLink;
use App\ArtisanProfile\Application\Agenda\PauseAvailability;
use App\ArtisanProfile\Application\Agenda\RemoveAbsence;
use App\ArtisanProfile\Application\Agenda\SaveAgendaSettings;
use App\ArtisanProfile\Application\Agenda\SaveWeeklyRules;
use App\ArtisanProfile\Domain\Agenda\AbsenceKind;
use App\ArtisanProfile\Domain\Agenda\AgendaRuleViolation;
use App\ArtisanProfile\Domain\Agenda\AgendaSettings;
use App\ArtisanProfile\Domain\Agenda\FrenchHolidays;
use App\ArtisanProfile\Domain\Agenda\SlotPlanner;
use App\ArtisanProfile\Domain\Agenda\WeeklyRule;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * PR06 du CDC / PR16 Figma (383:17652) — /espace-pro/agenda « Mes disponibilités » : planning (semaine, mois, liste),
 * horaires récurrents et réglages, indisponibilités, jours fériés, synchronisation (lien iCal privé, PR06.3), statut (mode
 * pause). Écarts : P-110, P-111, P-112.
 */
#[Route('/espace-pro/agenda')]
final class AgendaController extends AbstractController
{
    public const array TABS = ['planning' => 'Mon planning', 'horaires' => 'Horaires récurrents', 'indisponibilites' => 'Indisponibilités', 'feries' => 'Jours fériés', 'synchronisation' => 'Synchronisation'];
    private const array VIEWS = ['semaine' => 'Semaine', 'mois' => 'Mois', 'liste' => 'Liste'];
    private const string CSRF = 'pro_agenda';
    /** Plages proposées par jour dans le formulaire des horaires récurrents. */
    private const int RANGES_PER_DAY = 3;
    private const int ICAL_TOKEN_BYTES = 24;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
        private readonly CurrentUser $user,
        private readonly IdGenerator $ids,
        private readonly Clock $clock,
    ) {
    }

    #[Route('', name: 'pro_availability', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->page($request);
    }

    /** PR06.3 — onglet Synchronisation (raccourci « Synchroniser avec Google Agenda ou Outlook »). */
    #[Route('/synchronisation', name: 'pro_agenda_sync', methods: ['GET'])]
    public function sync(Request $request): Response
    {
        $request->query->set('onglet', 'synchronisation');

        return $this->page($request);
    }

    /** Nouveau lien iCal privé (l'ancien cesse de fonctionner) : affiché une seule fois, jamais mis en cache. */
    #[Route('/synchronisation/lien', name: 'pro_agenda_ical_issue', methods: ['POST'])]
    public function issueIcal(Request $request): Response
    {
        $userId = $this->checked($request);
        if ('revoke' === $request->request->getString('action')) {
            $this->commands->dispatch(new IssueIcalLink($userId, null));
            $this->addFlash('success', 'Lien iCal révoqué : vos agendas externes ne reçoivent plus vos rendez-vous.');

            return $this->redirectToRoute('pro_agenda_sync', [], Response::HTTP_SEE_OTHER);
        }
        $token = bin2hex(random_bytes(self::ICAL_TOKEN_BYTES));
        $this->commands->dispatch(new IssueIcalLink($userId, $token));
        $request->query->set('onglet', 'synchronisation');
        $response = $this->page($request, $this->generateUrl('agenda_ical', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function page(Request $request, ?string $icalUrl = null): Response
    {
        $tab = \array_key_exists($request->query->getString('onglet'), self::TABS) ? $request->query->getString('onglet') : 'planning';
        $view = \array_key_exists($request->query->getString('vue'), self::VIEWS) ? $request->query->getString('vue') : 'semaine';
        $zone = new \DateTimeZone(SlotPlanner::ZONE);
        $today = $this->clock->now()->setTimezone($zone)->setTime(0, 0);
        $anchor = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('date'), $zone) ?: $today;
        if ('mois' === $view) {
            $first = $anchor->modify('first day of this month');
            [$from, $to] = [$first->modify('monday this week'), $first->modify('last day of this month')->modify('sunday this week')];
            [$prev, $next] = [$first->modify('-1 month'), $first->modify('+1 month')];
        } else {
            $from = $anchor->modify('monday this week');
            $to = $from->modify('+6 days');
            [$prev, $next] = [$from->modify('-7 days'), $from->modify('+7 days')];
        }
        $calendar = $this->calendar($from, $to);
        // Indicateurs : semaine affichée en vue semaine, sinon les 7 prochains jours.
        $week = 'planning' === $tab && 'semaine' === $view ? $calendar : $this->calendar($today, $today->modify('+6 days'));

        return $this->render('pro/agenda/index.html.twig', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'view' => $view,
            'views' => self::VIEWS,
            'calendar' => $calendar,
            'week' => $week,
            'grid' => 'semaine' === $view ? AgendaGrid::build($calendar->days) : null,
            'from' => $from,
            'to' => $to,
            'month' => $anchor->modify('first day of this month'),
            'prev' => $prev,
            'next' => $next,
            'today' => $today,
            'days' => WeeklyRule::DAYS,
            'ranges_per_day' => self::RANGES_PER_DAY,
            'slot_choices' => AgendaSettings::SLOT_MINUTES,
            'max_notice' => AgendaSettings::MAX_NOTICE_HOURS,
            'kinds' => array_combine(array_map(static fn (AbsenceKind $k): string => $k->value, AbsenceKind::cases()), array_map(static fn (AbsenceKind $k): string => $k->label(), AbsenceKind::cases())),
            'holidays' => FrenchHolidays::of((int) $today->format('Y')) + FrenchHolidays::of((int) $today->format('Y') + 1),
            'csrf_id' => self::CSRF,
            'ical_url' => $icalUrl,
            'ical_since' => $this->queries->ask(new IcalLinkStatus($this->userId())),
        ]);
    }

    /** Horaires récurrents et réglages (durée des créneaux, prévenance) : un seul enregistrement. */
    #[Route('/horaires', name: 'pro_agenda_rules', methods: ['POST'])]
    public function rules(Request $request): Response
    {
        $userId = $this->checked($request);
        $rules = [];
        $input = $request->request->all('plages');
        $copy = $request->request->getString('copier');
        foreach (WeeklyRule::DAYS as $weekday => $label) {
            $source = '' !== $copy && $weekday <= 5 ? (int) $copy : $weekday;
            foreach (\is_array($input[$source] ?? null) ? $input[$source] : [] as $range) {
                $start = \is_array($range) && \is_string($range['debut'] ?? null) ? trim($range['debut']) : '';
                $end = \is_array($range) && \is_string($range['fin'] ?? null) ? trim($range['fin']) : '';
                if ('' !== $start || '' !== $end) {
                    $rules[] = ['weekday' => $weekday, 'start' => $start, 'end' => $end];
                }
            }
        }
        $settings = $this->calendarOf($userId)->settings;
        try {
            $this->commands->dispatch(new SaveWeeklyRules($userId, $rules));
            $this->commands->dispatch(new SaveAgendaSettings($userId, $request->request->getInt('duree', $settings->slotMinutes), $request->request->getInt('prevenance', $settings->minNoticeHours), $settings->closedOnHolidays));
            $this->addFlash('success', '' !== $copy ? 'Horaires du '.mb_strtolower(WeeklyRule::DAYS[(int) $copy] ?? '').' appliqués du lundi au vendredi.' : 'Disponibilités enregistrées : vos créneaux sont à jour.');
        } catch (AgendaRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('pro_availability', ['onglet' => 'horaires'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/statut', name: 'pro_agenda_status', methods: ['POST'])]
    public function status(Request $request): Response
    {
        $userId = $this->checked($request);
        $available = '1' === $request->request->getString('disponible');
        $this->commands->dispatch(new PauseAvailability($userId, !$available));
        $this->addFlash('success', $available ? 'Vous êtes de nouveau disponible pour de nouvelles demandes.' : 'Mode pause activé : plus de demande directe ni de badge « Disponible ».');

        return $this->redirect($this->back($request), Response::HTTP_SEE_OTHER);
    }

    #[Route('/feries', name: 'pro_agenda_holidays', methods: ['POST'])]
    public function holidays(Request $request): Response
    {
        $userId = $this->checked($request);
        $settings = $this->calendarOf($userId)->settings;
        $closed = '1' === $request->request->getString('fermes');
        $this->commands->dispatch(new SaveAgendaSettings($userId, $settings->slotMinutes, $settings->minNoticeHours, $closed));
        $this->addFlash('success', $closed ? 'Aucun créneau ne sera ouvert les jours fériés.' : 'Vos horaires s’appliquent aussi les jours fériés.');

        return $this->redirectToRoute('pro_availability', ['onglet' => 'feries'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/absences', name: 'pro_agenda_absence_add', methods: ['POST'])]
    public function addAbsence(Request $request): Response
    {
        $userId = $this->checked($request);
        $from = $request->request->getString('debut');
        try {
            $this->commands->dispatch(new AddAbsence($userId, $this->ids->next(), $from, $request->request->getString('fin') ?: $from, $request->request->getString('type'), $request->request->getString('libelle')));
            $this->addFlash('success', 'Absence enregistrée : aucun créneau n’est proposé ces jours-là.');
        } catch (AgendaRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($this->back($request), Response::HTTP_SEE_OTHER);
    }

    #[Route('/absences/{id}/supprimer', name: 'pro_agenda_absence_delete', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['POST'])]
    public function removeAbsence(Request $request, string $id): Response
    {
        $userId = $this->checked($request);
        try {
            $this->commands->dispatch(new RemoveAbsence($userId, $id));
            $this->addFlash('success', 'Absence supprimée : vos créneaux sont rouverts.');
        } catch (AgendaRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($this->back($request), Response::HTTP_SEE_OTHER);
    }

    private function calendar(\DateTimeImmutable $from, \DateTimeImmutable $to): AgendaCalendarView
    {
        return $this->calendarOf($this->userId(), $from, $to);
    }

    private function calendarOf(string $userId, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): AgendaCalendarView
    {
        $today = $this->clock->now();
        $view = $this->queries->ask(new AgendaCalendar($userId, $from ?? $today, $to ?? $today));

        return $view instanceof AgendaCalendarView ? $view : throw new NotFoundHttpException();
    }

    private function userId(): string
    {
        return $this->user->id() ?? throw new AccessDeniedHttpException();
    }

    private function checked(Request $request): string
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }

        return $this->userId();
    }

    /** Retour vers une page de l'espace pro (champ `retour`), sinon l'agenda. */
    private function back(Request $request): string
    {
        $back = $request->request->getString('retour');

        return str_starts_with($back, '/espace-pro/') && !str_starts_with($back, '//') ? $back : $this->generateUrl('pro_availability');
    }
}
