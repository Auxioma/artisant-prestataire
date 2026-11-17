<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\Legal\LegalDocumentVersion;
use App\Identity\Application\Password\PasswordPolicy;
use App\Identity\Application\Password\WeakPassword;
use App\Identity\Application\Port\LegalDocuments;
use App\Identity\Application\Registration\AccountExists;
use App\Identity\Application\Registration\RegisterArtisanAccount;
use App\Identity\Application\Registration\RegisterArtisanAccountHandler;
use App\Identity\Application\Registration\RegistrationRejected;
use App\Identity\UI\Form\ArtisanAccountData;
use App\Identity\UI\Form\ArtisanAccountType;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Application\Security\HumanVerification;
use App\Shared\Application\Security\IpAnonymizer;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * A03.1 — inscription artisan, étape 1 (compte du dirigeant). Compte créé → connexion automatique et suite du tunnel
 * (entreprise, métiers… : module ArtisanProfile). Adresse déjà inscrite : même page qu'en A02 (P-46).
 */
final class ArtisanAccountController extends AbstractController
{
    private const string TURNSTILE_FIELD = 'cf-turnstile-response';

    /** @param UserProviderInterface<\Symfony\Component\Security\Core\User\UserInterface> $users */
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
        private readonly LegalDocuments $legal,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly HumanVerification $humans,
        private readonly IpAnonymizer $ipAnonymizer,
        private readonly IdGenerator $ids,
        private readonly Clock $clock,
        private readonly Security $security,
        #[Autowire(service: 'App\Identity\Infrastructure\Security\UserProvider')]
        private readonly UserProviderInterface $users,
        #[Autowire(service: 'limiter.signup')]
        private readonly RateLimiterFactoryInterface $signupLimiter,
    ) {
    }

    #[Route('/inscription/pro', name: 'identity_register_pro', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        if ($this->isGranted('ROLE_ARTISAN')) {
            return $this->redirectToRoute('artisan_signup_resume');
        }
        $documents = $this->documents();
        if (null === $documents) {
            return $this->render('auth/register_unavailable.html.twig', [], new Response(status: Response::HTTP_SERVICE_UNAVAILABLE));
        }

        $data = new ArtisanAccountData();
        $data->legalVersions = implode(',', array_map(static fn (LegalDocumentVersion $d): string => $d->id, $documents));
        $form = $this->createForm(ArtisanAccountType::class, $data, ['password_min_length' => $this->passwordPolicy->minLength()]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if (!$this->signupLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                $form->addError(new FormError('Trop de tentatives d’inscription depuis votre connexion. Réessayez dans une heure.'));
            } elseif ($form->isValid() && $this->humanVerified($request, $form)) {
                $response = $this->register($request, $data, $form);
                if (null !== $response) {
                    return $response;
                }
            }
        }

        return $this->render('pro_signup/account.html.twig', [
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** @param FormInterface<ArtisanAccountData> $form */
    private function register(Request $request, ArtisanAccountData $data, FormInterface $form): ?Response
    {
        $ip = $request->getClientIp();
        $userId = $this->ids->next();
        try {
            $this->commands->dispatch(new RegisterArtisanAccount(
                userId: $userId,
                email: (string) $data->email,
                password: (string) $data->password,
                firstName: (string) $data->firstName,
                lastName: (string) $data->lastName,
                mobile: (string) $data->mobile,
                acceptedDocumentIds: $data->legalVersionIds(),
                ip: $ip,
                ipHash: $this->ipAnonymizer->hash($ip),
            ));
        } catch (WeakPassword $e) {
            $form->get('password')->get('first')->addError(new FormError(match ($e->reason) {
                WeakPassword::COMPROMISED => 'Ce mot de passe est trop courant ou a fuité, choisissez-en un autre.',
                WeakPassword::CONTAINS_EMAIL => 'Votre mot de passe ne doit pas contenir votre adresse e-mail.',
                WeakPassword::TOO_LONG => 'Votre mot de passe ne doit pas dépasser 128 caractères.',
                default => \sprintf('Votre mot de passe doit contenir au moins %d caractères.', $e->minLength),
            }));

            return null;
        } catch (RegistrationRejected $e) {
            [$field, $message] = match ($e->reason) {
                RegistrationRejected::EMAIL_DISPOSABLE => ['email', 'Les adresses temporaires ne sont pas acceptées.'],
                RegistrationRejected::MOBILE_REQUIRED => ['mobile', 'Saisissez un numéro de mobile (06 ou 07).'],
                default => ['terms', 'Nos conditions viennent d’être mises à jour : relisez-les puis acceptez-les à nouveau.'],
            };
            $form->get($field)->addError(new FormError($message));

            return null;
        }

        // Connexion automatique UNIQUEMENT si cette inscription a créé le compte (identifiant tout juste généré) :
        // une adresse déjà inscrite ne donne jamais accès au compte existant.
        if (true === $this->queries->ask(new AccountExists($userId))) {
            $this->security->login($this->users->loadUserByIdentifier((string) $data->email), 'form_login', 'main');

            return $this->redirectToRoute('artisan_signup_company', status: Response::HTTP_SEE_OTHER);
        }

        // Adresse déjà inscrite : même page qu'à l'inscription client, titulaire prévenu par e-mail (I-01).
        $request->getSession()->set(RegistrationController::SESSION_EMAIL, (string) $data->email);

        return $this->redirectToRoute('identity_register_check_email', status: Response::HTTP_SEE_OTHER);
    }

    /** @param FormInterface<ArtisanAccountData> $form */
    private function humanVerified(Request $request, FormInterface $form): bool
    {
        try {
            if ($this->humans->verify($request->request->getString(self::TURNSTILE_FIELD), $request->getClientIp(), 'signup')) {
                return true;
            }
            $form->addError(new FormError('La vérification anti-robot a échoué. Merci de réessayer.'));
        } catch (ExternalServiceUnavailable) {
            $form->addError(new FormError('Le service de vérification anti-robot est momentanément indisponible. Merci de réessayer dans quelques minutes.'));
        }

        return false;
    }

    /** @return list<LegalDocumentVersion>|null */
    private function documents(): ?array
    {
        $documents = [];
        foreach (RegisterArtisanAccountHandler::REQUIRED_DOCUMENTS as $type) {
            $document = $this->legal->current($type, $this->clock->now());
            if (null === $document) {
                return null;
            }
            $documents[] = $document;
        }

        return $documents;
    }
}
