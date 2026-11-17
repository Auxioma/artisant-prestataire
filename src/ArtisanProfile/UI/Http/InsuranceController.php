<?php

declare(strict_types=1);

namespace App\ArtisanProfile\UI\Http;

use App\ArtisanProfile\Application\Signup\DeclareInsurances;
use App\ArtisanProfile\Application\Signup\SignupInsuranceRequirements;
use App\ArtisanProfile\Application\Signup\SignupRejected;
use App\ArtisanProfile\Domain\Profile\ArtisanProfile;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\ArtisanProfile\Domain\Profile\InsuranceRequirements;
use App\Shared\Domain\Time\Clock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A03.5 — assurances et qualifications (Figma 230:11914). Décennale / RC Pro exigées selon les métiers (Q16) ;
 * n° de police demandé pour les deux (CDC > Figma, P-47) ; date de fin passée refusée.
 */
#[Route('/inscription/pro')]
final class InsuranceController extends AbstractSignupController
{
    private const string EXPIRED = 'Votre attestation d’assurance semble expirée.';
    private const array KINDS = ['decennial', 'rc_pro'];

    #[Route('/assurances', name: 'artisan_signup_insurance', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, Clock $clock): Response
    {
        $state = $this->state();
        if (null !== $redirect = $this->guardStep($state, 5)) {
            return $redirect;
        }
        $required = $this->queries->ask(new SignupInsuranceRequirements($this->userId()));
        \assert($required instanceof InsuranceRequirements);

        $empty = ['insurer' => '', 'policy' => '', 'valid_until' => ''];
        $insurances = ['decennial' => $empty, 'rc_pro' => $empty];
        foreach ($state->insurances as $insurance) {
            $insurances[$insurance['kind']] = $insurance;
        }
        $labels = $state->qualifications;
        $errors = [];
        $error = null;

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'artisan_insurance');
            $action = $request->request->getString('action');
            $posted = $request->request->all('insurances');
            foreach (self::KINDS as $kind) {
                $insurances[$kind] = self::fields(\is_array($posted[$kind] ?? null) ? $posted[$kind] : [], ['insurer', 'policy', 'valid_until']);
            }
            $labels = [];
            foreach ($request->request->all('labels') as $label) {
                if (\is_array($label)) {
                    $labels[] = self::fields($label, ['name', 'number', 'valid_until']);
                }
            }
            if ('add_label' === $action && \count($labels) < ArtisanProfile::MAX_QUALIFICATIONS) {
                $labels[] = ['name' => '', 'number' => '', 'valid_until' => ''];
            }
            if (str_starts_with($action, 'remove_label:')) {
                unset($labels[(int) substr($action, 13)]);
                $labels = array_values($labels);
            }

            if ('continue' === $action) {
                $today = $clock->now()->format('Y-m-d');
                $declared = [];
                foreach (self::KINDS as $kind) {
                    $values = $insurances[$kind];
                    $mandatory = 'decennial' === $kind ? $required->decennial : $required->liability;
                    if (!$mandatory && '' === implode('', $values)) {
                        continue; // assurance facultative non renseignée
                    }
                    if ('' !== $values['valid_until'] && $values['valid_until'] < $today) {
                        $errors[$kind.'_valid_until'] = self::EXPIRED;
                    }
                    $declared[] = ['kind' => $kind, 'insurer' => $values['insurer'], 'policy' => $values['policy'], 'valid_until' => $values['valid_until']];
                }
                $labels = array_values(array_filter($labels, static fn (array $l): bool => '' !== implode('', $l)));
                $qualifications = array_map(static fn (array $l): array => ['name' => $l['name'], 'number' => $l['number'], 'valid_until' => $l['valid_until']], $labels);
                if ([] === $errors) {
                    try {
                        $this->commands->dispatch(new DeclareInsurances($this->userId(), $declared, $qualifications));
                        if (!$state->isOpen()) {
                            $this->addFlash('info', ['title' => 'Assurances mises à jour', 'message' => 'Déposez maintenant les nouvelles attestations : elles seront contrôlées par notre équipe.']);
                        }

                        return $this->redirectToRoute('artisan_signup_documents', status: Response::HTTP_SEE_OTHER);
                    } catch (SignupRejected|ArtisanRuleViolation $e) {
                        $error = $e->getMessage();
                    }
                } else {
                    $error = self::EXPIRED;
                }
            }
        }

        return $this->render('pro_signup/insurance.html.twig', [
            'steps' => self::STEPS,
            'insurances' => $insurances,
            'labels' => $labels,
            'required' => $required,
            'max_labels' => ArtisanProfile::MAX_QUALIFICATIONS,
            'errors' => $errors,
            'error' => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private static function fields(array $data, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = \is_string($data[$key] ?? null) ? trim($data[$key]) : '';
        }

        return $values;
    }
}
