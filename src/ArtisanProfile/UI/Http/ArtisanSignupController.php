<?php

declare(strict_types=1);

namespace App\ArtisanProfile\UI\Http;

use App\ArtisanProfile\Application\Signup\ChooseJobs;
use App\ArtisanProfile\Application\Signup\CompanyLookupResult;
use App\ArtisanProfile\Application\Signup\DeclareCompany;
use App\ArtisanProfile\Application\Signup\LookupCompany;
use App\ArtisanProfile\Application\Signup\SignupProgress;
use App\ArtisanProfile\Application\Signup\SignupRejected;
use App\ArtisanProfile\Application\Signup\SignupState;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A03 — étapes 2 (entreprise, Figma 228:11720) et 3 (métiers, 228:11875) du tunnel d'inscription professionnelle.
 * Accès réservé aux comptes artisan connectés (security.yaml). Progression conservée (« Terminer plus tard »).
 */
#[Route('/inscription/pro')]
final class ArtisanSignupController extends AbstractController
{
    public const array STEPS = ['Compte', 'Entreprise', 'Métiers', 'Zone', 'Assurances', 'Justificatifs', 'Profil & récapitulatif'];
    private const array MESSAGES = [
        SignupRejected::SIRET_INVALID => 'Ce numéro SIRET est invalide.',
        SignupRejected::SIRET_TAKEN => 'Cette entreprise est déjà inscrite. Contactez-nous si vous pensez qu’il s’agit d’une erreur.',
        SignupRejected::SIRET_CLOSED => 'Cet établissement est indiqué comme fermé.',
        SignupRejected::MANUAL_REQUIRED => 'Le répertoire des entreprises ne répond pas : saisissez la raison sociale et le code postal du siège (vérification différée).',
    ];

    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
        private readonly CurrentUser $currentUser,
        private readonly SettingsProvider $settings,
    ) {
    }

    #[Route('/reprendre', name: 'artisan_signup_resume', methods: ['GET'])]
    public function resume(): Response
    {
        $step = $this->state()->completedStep;

        return $this->redirectToRoute(match (true) {
            $step < 2 => 'artisan_signup_company',
            $step < 3 => 'artisan_signup_jobs',
            default => 'artisan_signup_next',
        });
    }

    #[Route('/entreprise', name: 'artisan_signup_company', methods: ['GET', 'POST'])]
    public function company(Request $request): Response
    {
        $userId = $this->userId();
        $state = $this->state();
        $values = [
            'siret' => $state->siret ?? '',
            'trade_name' => $state->tradeName ?? '',
            'vat_number' => $state->vatNumber ?? '',
            'vat_status' => $state->vatStatus ?? 'assujetti',
            'company_name' => $state->companyName ?? '',
            'postal_code' => $state->hqPostalCode ?? '',
            'city' => $state->hqCity ?? '',
        ];
        $lookup = null;
        $error = null;

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'artisan_company');
            foreach (array_keys($values) as $key) {
                $values[$key] = trim($request->request->getString($key));
            }
            $values['siret'] = (string) preg_replace('/\D/', '', $values['siret']);
            $lookup = $this->queries->ask(new LookupCompany($userId, $values['siret']));
            \assert($lookup instanceof CompanyLookupResult);
            if ('' === $values['vat_number'] && null !== $lookup->suggestedVatNumber) {
                $values['vat_number'] = $lookup->suggestedVatNumber;
            }

            if ('continue' === $request->request->getString('action')) {
                try {
                    $this->commands->dispatch(new DeclareCompany($userId, $values['siret'], $values['trade_name'], $values['vat_number'], $values['vat_status'], $values['company_name'], $values['postal_code'], $values['city']));

                    return $this->redirectToRoute('artisan_signup_jobs', status: Response::HTTP_SEE_OTHER);
                } catch (SignupRejected $e) {
                    $error = self::MESSAGES[$e->reason] ?? $e->getMessage();
                } catch (ArtisanRuleViolation $e) {
                    $error = $e->getMessage();
                }
            }
        } elseif (null !== $state->siret) {
            $lookup = $this->queries->ask(new LookupCompany($userId, $state->siret));
        }

        return $this->render('pro_signup/company.html.twig', [
            'steps' => self::STEPS,
            'values' => $values,
            'lookup' => $lookup,
            'error' => $error,
            'state' => $state,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/metiers', name: 'artisan_signup_jobs', methods: ['GET', 'POST'])]
    public function jobs(Request $request): Response
    {
        $state = $this->state();
        if ($state->completedStep < 2) {
            return $this->redirectToRoute('artisan_signup_company');
        }
        $selected = $state->jobIds;
        $main = $state->mainJobId;
        $error = null;

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'artisan_jobs');
            $selected = array_values(array_filter($request->request->all('jobs'), \is_string(...)));
            $main = $request->request->getString('main') ?: ($selected[0] ?? null);
            if ([] === $selected) {
                $error = 'Choisissez au moins un métier.';
            } else {
                try {
                    $this->commands->dispatch(new ChooseJobs($this->userId(), $selected, (string) $main));

                    return $this->redirectToRoute('artisan_signup_next', status: Response::HTTP_SEE_OTHER);
                } catch (SignupRejected|ArtisanRuleViolation $e) {
                    $error = $e->getMessage();
                }
            }
        }

        $entries = $this->queries->ask(new JobIndexEntries());
        $categories = [];
        foreach (\is_array($entries) ? $entries : [] as $entry) {
            if ($entry instanceof JobIndexEntry && $entry->searchable) {
                $categories[$entry->categoryName][] = $entry;
            }
        }

        return $this->render('pro_signup/jobs.html.twig', [
            'steps' => self::STEPS,
            'categories' => $categories,
            'selected' => $selected,
            'main' => $main,
            'max_jobs' => $this->settings->int(SettingKey::ProMaxJobs),
            'error' => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /** Étapes 4 à 7 (zone, assurances, justificatifs, profil) : T-207b. Progression enregistrée. */
    #[Route('/suite', name: 'artisan_signup_next', methods: ['GET'])]
    public function next(): Response
    {
        return $this->render('pro_signup/next.html.twig', ['steps' => self::STEPS, 'state' => $this->state()]);
    }

    private function userId(): string
    {
        return $this->currentUser->id() ?? throw new AccessDeniedHttpException();
    }

    private function state(): SignupState
    {
        $state = $this->queries->ask(new SignupProgress($this->userId()));
        \assert($state instanceof SignupState);

        return $state;
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
    }
}
