<?php

declare(strict_types=1);

namespace App\Search\UI\Http;

use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Search\Application\Availability\AvailableArtisans;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Time\Clock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P08 « Disponibilités » (maquette Kerry « Disponibilités — Desktop 1440 · V2 ») : métier, lieu, date souhaitée
 * (aujourd'hui, demain, cette semaine, date précise) et créneau (matin, après-midi) ; cartes avec les 3 prochains
 * créneaux ouverts (RG-025). Un créneau ouvre une demande directe pré-remplie (le créneau n'est pas réservé).
 * Vide : « Aucun créneau sous 7 jours » avec élargissement (horizon de l'agenda, rayon maximal).
 */
final class AvailabilityController extends AbstractController
{
    public const array WHEN = ['aujourdhui' => 'Aujourd’hui', 'demain' => 'Demain', 'semaine' => 'Cette semaine', 'date' => 'Date précise'];
    public const array PARTS = [AvailableArtisans::MORNING => 'Matin', AvailableArtisans::AFTERNOON => 'Après-midi'];
    private const string DEFAULT_WHEN = 'semaine';
    private const int WEEK_DAYS = 7;
    private const string TZ = 'Europe/Paris';

    public function __construct(private readonly QueryBus $queries, private readonly SettingsProvider $settings, private readonly PlaceResolver $places, private readonly Clock $clock)
    {
    }

    #[Route('/disponibilites', name: 'search_availability', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $jobs = $this->queries->ask(new JobIndexEntries());
        $jobs = array_values(array_filter(\is_array($jobs) ? $jobs : [], static fn (mixed $j): bool => $j instanceof JobIndexEntry && $j->searchable));
        $slug = $request->query->getString('metier');
        $job = array_values(array_filter($jobs, static fn (JobIndexEntry $j): bool => $j->slug === $slug))[0] ?? null;
        $place = $this->places->resolve($request->query->getString('lieu'), trim($request->query->getString('lieu_libelle')));
        $when = $request->query->getString('quand');
        $when = \array_key_exists($when, self::WHEN) ? $when : self::DEFAULT_WHEN;
        $part = $request->query->getString('creneau');
        $part = \array_key_exists($part, self::PARTS) ? $part : null;
        $wide = $request->query->getBoolean('elargir');

        $today = $this->clock->now()->setTimezone(new \DateTimeZone(self::TZ))->setTime(0, 0);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('date'), $today->getTimezone()) ?: null;
        $horizon = $this->settings->int(SettingKey::AvailabilityHorizonDays);
        if ('date' === $when && (null === $date || $date < $today || $date > $today->modify(\sprintf('+%d days', $horizon)))) {
            $date = null;
            $when = self::DEFAULT_WHEN;
        }
        [$from, $to] = $wide ? [$today, $today->modify(\sprintf('+%d days', $horizon))] : match ($when) {
            'aujourdhui' => [$today, $today->modify('+1 day')],
            'demain' => [$today->modify('+1 day'), $today->modify('+2 days')],
            'date' => [$date ?? $today, ($date ?? $today)->modify('+1 day')],
            default => [$today, $today->modify(\sprintf('+%d days', self::WEEK_DAYS))],
        };
        $radiusOptions = $this->settings->intList(SettingKey::SearchRadiusOptions);
        $maxRadius = [] === $radiusOptions ? $this->settings->int(SettingKey::SearchDefaultRadiusKm) : max($radiusOptions);
        $radius = $wide ? $maxRadius : $this->settings->int(SettingKey::SearchDefaultRadiusKm);

        $cards = $this->queries->ask(new AvailableArtisans(null === $job ? [] : [$job->id], $place?->insee, $radius, $from, $to, $part));
        $names = [];
        foreach ($jobs as $entry) {
            $names[$entry->id] = $entry->name;
        }
        $params = array_filter([
            'metier' => $job?->slug,
            'lieu' => $place?->insee,
            'quand' => $when,
            'date' => 'date' === $when ? $date?->format('Y-m-d') : null,
            'creneau' => $part,
        ], static fn (?string $v): bool => null !== $v);

        $response = $this->render('search/availability.html.twig', [
            'cards' => \is_array($cards) ? $cards : [],
            'jobs' => $jobs,
            'job' => $job,
            'place' => $place,
            'place_label' => null === $place ? trim($request->query->getString('lieu_libelle')) : PlaceResolver::label($place),
            'when' => $when,
            'when_options' => self::WHEN,
            'date' => $date,
            'today' => $today,
            'max_date' => $today->modify(\sprintf('+%d days', $horizon)),
            'part' => $part,
            'parts' => self::PARTS,
            'wide' => $wide,
            'radius' => $radius,
            'max_radius' => $maxRadius,
            'params' => $params,
            'job_names' => $names,
        ]);
        if ($request->query->count() > 0) {
            $response->headers->set('X-Robots-Tag', 'noindex, follow');
        }

        return $response;
    }
}
