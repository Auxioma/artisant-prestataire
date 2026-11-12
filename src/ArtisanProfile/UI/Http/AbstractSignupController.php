<?php

declare(strict_types=1);

namespace App\ArtisanProfile\UI\Http;

use App\ArtisanProfile\Application\Signup\SignupProgress;
use App\ArtisanProfile\Application\Signup\SignupState;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Socle des étapes du tunnel A03 (accès : comptes artisan connectés, security.yaml). */
abstract class AbstractSignupController extends AbstractController
{
    public const array STEPS = ['Compte', 'Entreprise', 'Métiers', 'Zone', 'Assurances', 'Justificatifs', 'Profil & récapitulatif'];
    /** Route de chaque étape, par numéro d'étape. */
    protected const array STEP_ROUTES = [
        2 => 'artisan_signup_company',
        3 => 'artisan_signup_jobs',
        4 => 'artisan_signup_area',
        5 => 'artisan_signup_insurance',
        6 => 'artisan_signup_documents',
        7 => 'artisan_signup_profile',
    ];

    /** Étapes accessibles à un compte vérifié : zone (RG-042) et justificatifs (renouvellement, RG-130). */
    protected const array EDITABLE_AFTER_VERIFICATION = [4, 6];

    public function __construct(
        protected readonly CommandBus $commands,
        protected readonly QueryBus $queries,
        protected readonly CurrentUser $currentUser,
        protected readonly SettingsProvider $settings,
    ) {
    }

    protected function userId(): string
    {
        return $this->currentUser->id() ?? throw new AccessDeniedHttpException();
    }

    protected function state(): SignupState
    {
        $state = $this->queries->ask(new SignupProgress($this->userId()));
        \assert($state instanceof SignupState);

        return $state;
    }

    /** Étape non encore accessible : retour à la première étape à compléter. */
    protected function guardStep(SignupState $state, int $step): ?RedirectResponse
    {
        // Après décision (RG-043) : zone (RG-042) et justificatifs d'un compte vérifié restent modifiables, le reste non.
        if (!$state->isOpen() && !('verified' === $state->status && \in_array($step, self::EDITABLE_AFTER_VERIFICATION, true))) {
            return $this->redirectToRoute('artisan_signup_submitted');
        }
        if ($state->completedStep >= $step - 1) {
            return null;
        }

        return $this->redirectToRoute(self::STEP_ROUTES[max(2, $state->completedStep + 1)]);
    }

    protected function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
    }

    /** Corps de requête supérieur à post_max_size : PHP vide le formulaire (jeton compris) ; le signaler plutôt qu'un refus CSRF. */
    protected function isOversizedPost(Request $request): bool
    {
        return $request->isMethod('POST') && 0 === $request->request->count() && 0 === $request->files->count() && $request->server->getInt('CONTENT_LENGTH') > 0;
    }
}
