<?php

declare(strict_types=1);

namespace App\ArtisanProfile\UI\Http;

use App\ArtisanProfile\Application\Signup\DefineServiceArea;
use App\ArtisanProfile\Application\Signup\SignupRejected;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\Catalog\Application\Geo\Query\AreaCoverage;
use App\Catalog\Application\Geo\Query\CommunesByInsee;
use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Geo\Query\Coverage;
use App\Catalog\Application\Geo\Query\Departments;
use App\Catalog\Application\Geo\Query\FindCommunes;
use App\Shared\Application\Settings\SettingKey;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A03.4 — zone d'intervention (Figma 228:12011). Point de départ = une commune (centroïde, RG-022) : l'adresse exacte
 * n'est ni demandée ni conservée (CDC > Figma, P-47). Fonctionne sans JavaScript (choix de la commune parmi les
 * homonymes, aperçu mis à jour à l'envoi) ; avec JavaScript : suggestions et aperçu en direct.
 */
#[Route('/inscription/pro')]
final class ServiceAreaController extends AbstractSignupController
{
    private const int SUGGESTIONS = 8;
    /** Rayon proposé par défaut, ramené dans les bornes paramétrées (Figma : 30 km). */
    private const int DEFAULT_RADIUS_KM = 30;

    #[Route('/zone', name: 'artisan_signup_area', methods: ['GET', 'POST'])]
    public function area(Request $request): Response
    {
        $state = $this->state();
        if (null !== $redirect = $this->guardStep($state, 4)) {
            return $redirect;
        }
        [$min, $max] = [$this->settings->int(SettingKey::ProRadiusMinKm), $this->settings->int(SettingKey::ProRadiusMaxKm)];
        $values = [
            'mode' => $state->areaMode ?? 'radius',
            'center_insee' => $state->areaCenterInsee,
            'center' => '',
            'radius' => $state->areaRadiusKm ?? max($min, min($max, self::DEFAULT_RADIUS_KM)),
            'departments' => $state->areaDepartments,
            'communes' => $state->areaCommunes,
            'commune_search' => '',
        ];
        $centerChoices = [];
        $communeChoices = [];
        $error = null;

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'artisan_area');
            $action = $request->request->getString('action');
            $values['mode'] = 'list' === $request->request->getString('mode') ? 'list' : 'radius';
            $values['center'] = trim($request->request->getString('center'));
            $values['center_insee'] = self::insee($request->request->getString('center_pick')) ?? self::insee($request->request->getString('center_insee'));
            $values['radius'] = max($min, min($max, $request->request->getInt('radius', $values['radius'])));
            $values['departments'] = array_values(array_filter($request->request->all('departments'), \is_string(...)));
            $values['communes'] = array_values(array_unique(array_filter(array_map(self::insee(...), array_filter($request->request->all('communes'), \is_string(...))))));
            $values['commune_search'] = trim($request->request->getString('commune_search'));

            // Commune de départ saisie sans être choisie dans la liste : résolution par nom ou code postal.
            if ('radius' === $values['mode'] && '' !== $values['center'] && $values['center'] !== $this->label($values['center_insee'])) {
                [$values['center_insee'], $centerChoices] = $this->resolve($values['center']);
            }
            if ('add_commune' === $action) {
                $picked = self::insee($request->request->getString('commune_pick'));
                [$found, $communeChoices] = null !== $picked ? [$picked, []] : $this->resolve($values['commune_search']);
                if (null !== $found) {
                    $values['communes'] = array_values(array_unique([...$values['communes'], $found]));
                    $values['commune_search'] = '';
                } elseif ([] === $communeChoices) {
                    $error = 'Aucune commune ne correspond à « '.$values['commune_search'].' ».';
                }
            }
            if (str_starts_with($action, 'remove_commune:')) {
                $values['communes'] = array_values(array_diff($values['communes'], [substr($action, 15)]));
            }

            if ('continue' === $action) {
                if ([] !== $centerChoices) {
                    $error = 'Plusieurs communes correspondent : choisissez votre commune de départ.';
                } else {
                    try {
                        $radius = 'radius' === $values['mode'];
                        $this->commands->dispatch(new DefineServiceArea($this->userId(), $values['mode'], $radius ? $values['center_insee'] : null, $radius ? $values['radius'] : null, $radius ? [] : $values['departments'], $radius ? [] : $values['communes']));

                        return $this->redirectToRoute('artisan_signup_insurance', status: Response::HTTP_SEE_OTHER);
                    } catch (SignupRejected|ArtisanRuleViolation $e) {
                        $error = $e->getMessage();
                    }
                }
            }
        }
        if ('' === $values['center']) {
            $values['center'] = $this->label($values['center_insee']) ?? '';
        }
        $departments = $this->queries->ask(new Departments());

        return $this->render('pro_signup/area.html.twig', [
            'steps' => self::STEPS,
            'values' => $values,
            'radius_min' => $min,
            'radius_max' => $max,
            'center_choices' => $centerChoices,
            'commune_choices' => $communeChoices,
            'selected_communes' => [] === $values['communes'] ? [] : $this->communes($values['communes']),
            'departments' => \is_array($departments) ? $departments : [],
            'coverage' => $this->coverage($values['mode'], $values['center_insee'], $values['radius'], $values['departments'], $values['communes']),
            'error' => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /** Aperçu en direct (nombre de communes et d'habitants couverts). */
    #[Route('/zone/apercu', name: 'artisan_signup_area_preview', methods: ['GET'])]
    public function preview(Request $request): JsonResponse
    {
        $this->userId();
        $all = $request->query->all();
        $list = static fn (string $key): array => \is_array($all[$key] ?? null) ? array_values(array_filter($all[$key], \is_string(...))) : [];
        [$min, $max] = [$this->settings->int(SettingKey::ProRadiusMinKm), $this->settings->int(SettingKey::ProRadiusMaxKm)];
        $coverage = $this->coverage(
            'list' === $request->query->getString('mode') ? 'list' : 'radius',
            self::insee($request->query->getString('center')),
            max($min, min($max, $request->query->getInt('radius', $min))),
            \array_slice($list('departments'), 0, 101),
            \array_slice($list('communes'), 0, 500),
        );

        return new JsonResponse(['communes' => $coverage->communes, 'population' => $coverage->population], headers: ['Cache-Control' => 'private, no-store']);
    }

    /** Suggestions de communes (nom ou code postal) pour la saisie assistée. */
    #[Route('/zone/communes', name: 'artisan_signup_area_communes', methods: ['GET'])]
    public function communesLookup(Request $request): JsonResponse
    {
        $this->userId();
        $found = $this->queries->ask(new FindCommunes(mb_substr($request->query->getString('q'), 0, 60), self::SUGGESTIONS));
        $suggestions = [];
        foreach (\is_array($found) ? $found : [] as $commune) {
            if ($commune instanceof CommuneSummary) {
                $suggestions[] = ['insee' => $commune->insee, 'label' => self::format($commune)];
            }
        }

        return new JsonResponse($suggestions, headers: ['Cache-Control' => 'private, max-age=300']);
    }

    /**
     * @return array{0: ?string, 1: list<CommuneSummary>} commune retenue, ou homonymes entre lesquels choisir
     */
    private function resolve(string $term): array
    {
        $found = $this->queries->ask(new FindCommunes($term, self::SUGGESTIONS));
        $found = array_values(array_filter(\is_array($found) ? $found : [], static fn (mixed $c): bool => $c instanceof CommuneSummary));
        $normalized = mb_strtolower(trim($term));
        $exact = array_values(array_filter($found, static fn (CommuneSummary $c): bool => mb_strtolower($c->name) === $normalized || mb_strtolower(self::format($c)) === $normalized));

        return match (true) {
            1 === \count($found) => [$found[0]->insee, []],
            1 === \count($exact) => [$exact[0]->insee, []],
            default => [null, $found],
        };
    }

    private function label(?string $insee): ?string
    {
        if (null === $insee) {
            return null;
        }
        $communes = $this->communes([$insee]);

        return [] === $communes ? null : self::format($communes[0]);
    }

    /**
     * @param list<string> $insee
     *
     * @return list<CommuneSummary>
     */
    private function communes(array $insee): array
    {
        $found = $this->queries->ask(new CommunesByInsee($insee));

        return array_values(array_filter(\is_array($found) ? $found : [], static fn (mixed $c): bool => $c instanceof CommuneSummary));
    }

    /**
     * @param list<string> $departments
     * @param list<string> $communes
     */
    private function coverage(string $mode, ?string $center, int $radius, array $departments, array $communes): Coverage
    {
        $query = 'radius' === $mode
            ? (null === $center ? null : new AreaCoverage($center, $radius))
            : new AreaCoverage(departments: $departments, communes: $communes);
        $coverage = null === $query ? null : $this->queries->ask($query);

        return $coverage instanceof Coverage ? $coverage : new Coverage(0, 0);
    }

    private static function format(CommuneSummary $commune): string
    {
        return [] === $commune->postalCodes ? $commune->name : \sprintf('%s (%s)', $commune->name, min($commune->postalCodes));
    }

    private static function insee(string $value): ?string
    {
        return 1 === preg_match('/^(\d{2}|2A|2B)\d{3}$/', $value) ? $value : null;
    }
}
