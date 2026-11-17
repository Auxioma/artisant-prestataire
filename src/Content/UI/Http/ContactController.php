<?php

declare(strict_types=1);

namespace App\Content\UI\Http;

use App\Content\Application\Contact\ContactRuleViolation;
use App\Content\Application\Contact\SubmitContactMessage;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Application\Security\HumanVerification;
use App\Shared\Application\Security\IpAnonymizer;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Identifier\IdGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P16 (Figma 162:2178) — formulaire de contact : motif (liste administrable), coordonnées (pré-remplies et verrouillées
 * pour un compte connecté), n° de demande, message (20 à 5 000 caractères), pièce jointe, consentement ; captcha
 * invisible et limite d'envoi par IP ; accusé E-90 et alerte au support. Sans JavaScript, le formulaire fonctionne.
 */
final class ContactController extends AbstractController
{
    private const string TURNSTILE_FIELD = 'cf-turnstile-response';
    private const string CAPTCHA_ACTION = 'contact';
    private const string FLASH = 'contact_sent';
    private const int PREFILL_MAX = 500;

    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
        private readonly SettingsProvider $settings,
        private readonly CurrentUser $currentUser,
        private readonly HumanVerification $humans,
        private readonly IpAnonymizer $ipAnonymizer,
        private readonly IdGenerator $ids,
        #[Autowire(service: 'limiter.contact')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/contact', name: 'content_contact', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $reasons = $this->settings->stringList(SettingKey::ContactReasons);
        $account = $this->account();
        $data = [
            'reason' => $request->query->getString('motif'),
            'name' => $account->firstName ?? '',
            'email' => $account->email ?? '',
            'phone' => '',
            'request_reference' => $request->query->getString('reference'),
            'message' => mb_substr($request->query->getString('message'), 0, self::PREFILL_MAX),
            'consent' => false,
        ];
        $errors = [];
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            foreach (['reason', 'name', 'email', 'phone', 'request_reference', 'message'] as $field) {
                $data[$field] = trim($request->request->getString($field));
            }
            if (null !== $account) {
                $data['name'] = $account->firstName;
                $data['email'] = $account->email;
            }
            $data['consent'] = $request->request->getBoolean('consent');
            $errors = $this->guard($request);
            if ([] === $errors) {
                $file = $request->files->get('attachment');
                $file = $file instanceof UploadedFile && $file->isValid() ? $file : null;
                try {
                    $this->commands->dispatch(new SubmitContactMessage(
                        $this->ids->next(),
                        $data['reason'],
                        $data['name'],
                        $data['email'],
                        $data['phone'],
                        $data['request_reference'],
                        $data['message'],
                        $data['consent'],
                        $file?->getPathname(),
                        $file?->getClientOriginalName(),
                        $this->currentUser->id(),
                        $this->ipAnonymizer->hash($request->getClientIp()),
                    ));
                    $this->addFlash(self::FLASH, $data['reason']);

                    return $this->redirectToRoute('content_contact', ['envoye' => 1], Response::HTTP_SEE_OTHER);
                } catch (ContactRuleViolation $e) {
                    $errors[$e->field] = $e->getMessage();
                }
            }
            $status = isset($errors['_limit']) ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        $sent = $request->query->has('envoye') ? $this->sentReason($request) : null;

        return $this->render('content/contact.html.twig', [
            'reasons' => $reasons,
            'data' => $data,
            'errors' => $errors,
            'locked' => null !== $account,
            'sent' => $sent,
            'reply_hours' => $this->settings->int(SettingKey::ContactReplyHours),
            'max_file_mb' => $this->settings->int(SettingKey::ContactMaxFileMb),
        ], new Response(status: $status));
    }

    /** @return array<string, string> erreurs de contrôle (jeton, limite d'envoi, captcha) */
    private function guard(Request $request): array
    {
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            return ['_form' => 'La page a expiré : merci de renvoyer le formulaire.'];
        }
        if (!$this->limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return ['_limit' => 'Vous avez déjà envoyé plusieurs messages : merci de réessayer dans une heure.'];
        }
        try {
            if (!$this->humans->verify($request->request->getString(self::TURNSTILE_FIELD), $request->getClientIp(), self::CAPTCHA_ACTION)) {
                return ['_form' => 'La vérification anti-robot a échoué. Merci de réessayer.'];
            }
        } catch (ExternalServiceUnavailable) {
            return ['_form' => 'Le service de vérification anti-robot est momentanément indisponible. Merci de réessayer dans quelques minutes.'];
        }

        return [];
    }

    private function account(): ?UserContactDetails
    {
        $id = $this->currentUser->id();
        $details = null === $id ? null : $this->queries->ask(new UserContact($id));

        return $details instanceof UserContactDetails ? $details : null;
    }

    private function sentReason(Request $request): ?string
    {
        $session = $request->getSession();
        $flash = $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag()->get(self::FLASH) : [];
        $reason = $flash[0] ?? null;

        return \is_string($reason) ? $reason : null;
    }
}
