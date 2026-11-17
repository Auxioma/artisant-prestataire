<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\Legal\LegalDocumentVersion;
use App\Identity\Application\Password\PasswordPolicy;
use App\Identity\Application\Password\WeakPassword;
use App\Identity\Application\Port\LegalDocuments;
use App\Identity\Application\Registration\RegisterClient;
use App\Identity\Application\Registration\RegistrationRejected;
use App\Identity\Domain\Legal\LegalDocumentType;
use App\Identity\UI\Form\ClientRegistrationData;
use App\Identity\UI\Form\ClientRegistrationType;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Application\Security\HumanVerification;
use App\Shared\Application\Security\IpAnonymizer;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/** A01 (choix du type de compte) et A02 (inscription client). */
final class RegistrationController extends AbstractController
{
    public const string SESSION_EMAIL = 'identity.signup_email';
    private const string SESSION_TRACKED = 'identity.signup_track';
    private const string TURNSTILE_FIELD = 'cf-turnstile-response';
    private const string CAPTCHA_ACTION = 'signup';

    public function __construct(
        private readonly CommandBus $commands,
        private readonly LegalDocuments $legal,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly HumanVerification $humans,
        private readonly IpAnonymizer $ipAnonymizer,
        private readonly IdGenerator $ids,
        private readonly Clock $clock,
        #[Autowire(service: 'limiter.signup')]
        private readonly RateLimiterFactoryInterface $signupLimiter,
    ) {
    }

    #[Route('/inscription', name: 'identity_register_choice', methods: ['GET'])]
    public function choice(): Response
    {
        return $this->render('auth/register_choice.html.twig');
    }

    #[Route('/inscription/client', name: 'identity_register_client', methods: ['GET', 'POST'])]
    public function client(Request $request): Response
    {
        $documents = $this->currentDocuments();
        if (null === $documents) {
            return $this->render('auth/register_unavailable.html.twig', [], new Response(status: Response::HTTP_SERVICE_UNAVAILABLE));
        }

        $data = new ClientRegistrationData();
        $data->legalVersions = implode(',', array_map(static fn (LegalDocumentVersion $d): string => $d->id, $documents));
        $form = $this->createForm(ClientRegistrationType::class, $data, ['password_min_length' => $this->passwordPolicy->minLength()]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $limit = $this->signupLimiter->create($request->getClientIp() ?? 'unknown')->consume();
            if (!$limit->isAccepted()) {
                $form->addError(new FormError('Trop de tentatives d’inscription depuis votre connexion. Réessayez dans une heure.'));
            } elseif ($form->isValid() && $this->humanVerified($request, $form)) {
                $response = $this->register($request, $data, $form);
                if (null !== $response) {
                    return $response;
                }
            }
        }

        return $this->render('auth/register_client.html.twig', [
            'form' => $form,
            'documents' => $documents,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/inscription/verifiez-vos-emails', name: 'identity_register_check_email', methods: ['GET'])]
    public function checkEmail(Request $request): Response
    {
        $session = $request->getSession();
        $email = $session->get(self::SESSION_EMAIL);
        if (!\is_string($email)) {
            return $this->redirectToRoute('identity_register_choice');
        }
        $track = true === $session->remove(self::SESSION_TRACKED);

        return $this->render('auth/register_check_email.html.twig', ['email' => $email, 'track' => $track]);
    }

    /** @param FormInterface<ClientRegistrationData> $form */
    private function register(Request $request, ClientRegistrationData $data, FormInterface $form): ?Response
    {
        $ip = $request->getClientIp();
        try {
            $this->commands->dispatch(new RegisterClient(
                userId: $this->ids->next(),
                email: (string) $data->email,
                password: (string) $data->password,
                firstName: (string) $data->firstName,
                lastName: (string) $data->lastName,
                phone: $data->phone,
                postalCode: trim((string) $data->postalCode),
                clientType: $data->clientType,
                companyName: $data->companyName,
                siret: $data->siret,
                acceptedDocumentIds: $data->legalVersionIds(),
                newsletter: $data->newsletter,
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
            $this->rejection($form, $e);

            return null;
        }

        // Même issue que l'e-mail soit nouveau ou déjà inscrit (anti-énumération, A02).
        $request->getSession()->set(self::SESSION_EMAIL, (string) $data->email);
        $request->getSession()->set(self::SESSION_TRACKED, true);

        return $this->redirectToRoute('identity_register_check_email', status: Response::HTTP_SEE_OTHER);
    }

    /** @param FormInterface<ClientRegistrationData> $form */
    private function rejection(FormInterface $form, RegistrationRejected $e): void
    {
        [$field, $message] = match ($e->reason) {
            RegistrationRejected::EMAIL_DISPOSABLE => ['email', 'Les adresses temporaires ne sont pas acceptées.'],
            RegistrationRejected::POSTAL_CODE_UNKNOWN => ['postalCode', 'Code postal inconnu.'],
            RegistrationRejected::SIRET_UNKNOWN => ['siret', 'Ce SIRET est inconnu du répertoire de l’INSEE.'],
            RegistrationRejected::SIRET_CLOSED => ['siret', 'Cet établissement est fermé selon l’INSEE.'],
            default => ['terms', 'Nos conditions viennent d’être mises à jour : relisez-les puis acceptez-les à nouveau.'],
        };
        $form->get($field)->addError(new FormError($message));
    }

    /**
     * Turnstile (cadrage §5.8). Indisponible : inscription refusée pour l'instant (P-39, refus par défaut à l'inscription).
     *
     * @param FormInterface<ClientRegistrationData> $form
     */
    private function humanVerified(Request $request, FormInterface $form): bool
    {
        try {
            if ($this->humans->verify($request->request->getString(self::TURNSTILE_FIELD), $request->getClientIp(), self::CAPTCHA_ACTION)) {
                return true;
            }
            $form->addError(new FormError('La vérification anti-robot a échoué. Merci de réessayer.'));
        } catch (ExternalServiceUnavailable) {
            $form->addError(new FormError('Le service de vérification anti-robot est momentanément indisponible. Merci de réessayer dans quelques minutes.'));
        }

        return false;
    }

    /** @return list<LegalDocumentVersion>|null */
    private function currentDocuments(): ?array
    {
        $now = $this->clock->now();
        $documents = [];
        foreach ([LegalDocumentType::TermsOfUse, LegalDocumentType::Privacy] as $type) {
            $document = $this->legal->current($type, $now);
            if (null === $document) {
                return null;
            }
            $documents[] = $document;
        }

        return $documents;
    }
}
