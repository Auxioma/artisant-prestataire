<?php

declare(strict_types=1);

namespace App\ArtisanProfile\UI\Http\Agenda;

use App\ArtisanProfile\Application\Agenda\AgendaCalendar;
use App\ArtisanProfile\Application\Agenda\AgendaCalendarView;
use App\ArtisanProfile\Application\Agenda\ArtisanAbsences;
use App\ArtisanProfile\Domain\Agenda\Absence;
use App\ArtisanProfile\Domain\Agenda\AbsenceKind;
use App\ArtisanProfile\Domain\Agenda\FrenchHolidays;
use App\ArtisanProfile\Domain\Agenda\SlotPlanner;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Domain\Time\Clock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PR19 « Mes congés » (Figma 387:17558), adapté à un artisan indépendant : congés et absences déclarés (pas de solde ni
 * de validation), planning de la semaine, congés à venir et historique, jours fériés. L'enregistrement passe par les
 * actions de l'agenda (`pro_agenda_absence_add` / `_delete`). Écarts : P-110, P-113.
 */
final class LeaveController extends AbstractController
{
    public const array TABS = ['a-venir' => 'Mes congés', 'historique' => 'Historique', 'feries' => 'Jours fériés'];

    public function __construct(private readonly QueryBus $queries, private readonly CurrentUser $user, private readonly Clock $clock)
    {
    }

    #[Route('/espace-pro/conges', name: 'pro_leave', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $userId = $this->user->id() ?? throw new AccessDeniedHttpException();
        $tab = \array_key_exists($request->query->getString('onglet'), self::TABS) ? $request->query->getString('onglet') : 'a-venir';
        $zone = new \DateTimeZone(SlotPlanner::ZONE);
        $today = $this->clock->now()->setTimezone($zone)->setTime(0, 0);
        $anchor = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('date'), $zone) ?: $today;
        $from = $anchor->modify('monday this week');
        $calendar = $this->queries->ask(new AgendaCalendar($userId, $from, $from->modify('+6 days')));
        $absences = $this->queries->ask(new ArtisanAbsences($userId));
        if (!$calendar instanceof AgendaCalendarView || !\is_array($absences)) {
            throw new NotFoundHttpException();
        }
        $absences = array_values(array_filter($absences, static fn (mixed $a): bool => $a instanceof Absence));
        $key = $today->format('Y-m-d');
        $upcoming = array_values(array_filter($absences, static fn (Absence $a): bool => $a->to->format('Y-m-d') >= $key));
        $past = array_reverse(array_values(array_filter($absences, static fn (Absence $a): bool => $a->to->format('Y-m-d') < $key)));
        $year = (int) $today->format('Y');
        $holidays = array_filter(FrenchHolidays::of($year) + FrenchHolidays::of($year + 1), static fn (string $d): bool => $d >= $key, \ARRAY_FILTER_USE_KEY);

        return $this->render('pro/leave/index.html.twig', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'calendar' => $calendar,
            'grid' => AgendaGrid::build($calendar->days),
            'from' => $from,
            'to' => $from->modify('+6 days'),
            'today' => $today,
            'upcoming' => $upcoming,
            'past' => $past,
            'holidays' => $holidays,
            'stats' => [
                'year' => $year,
                'taken' => self::leaveDaysIn($absences, $year, null, $key),
                'planned' => self::leaveDaysIn($absences, $year, $key, null),
                'periods' => \count(array_filter($upcoming, static fn (Absence $a): bool => AbsenceKind::Leave === $a->kind)),
                'holidays_left' => \count(array_filter(FrenchHolidays::of($year), static fn (string $d): bool => $d >= $key, \ARRAY_FILTER_USE_KEY)),
            ],
            'next' => $upcoming[0] ?? null,
            'kinds' => [AbsenceKind::Leave->value => AbsenceKind::Leave->label(), AbsenceKind::Unavailable->value => AbsenceKind::Unavailable->label()],
            'csrf_id' => 'pro_agenda',
        ]);
    }

    /**
     * Jours de congé de l'année, avant `$before` (exclu) ou à partir de `$after` (inclus).
     *
     * @param list<Absence> $absences
     */
    private static function leaveDaysIn(array $absences, int $year, ?string $after, ?string $before): int
    {
        $days = 0;
        foreach ($absences as $a) {
            if (AbsenceKind::Leave !== $a->kind) {
                continue;
            }
            for ($d = $a->from; $d <= $a->to; $d = $d->modify('+1 day')) {
                $k = $d->format('Y-m-d');
                if ((int) $d->format('Y') === $year && (null === $after || $k >= $after) && (null === $before || $k < $before)) {
                    ++$days;
                }
            }
        }

        return $days;
    }
}
