<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\Client\ChangeClientAvatar;
use App\Identity\Application\Client\ChangeClientType;
use App\Identity\Application\Client\ClientProfilePage;
use App\Identity\Application\Client\ClientProfileRejected;
use App\Identity\Application\Client\ClientProfileView;
use App\Identity\Application\Client\UpdateClientProfile;
use App\Identity\Domain\Client\ClientInterest;
use App\Notification\Application\Preference\ChangeEmailPreferences;
use App\Notification\Application\Preference\EmailPreferences;
use App\Notification\Application\Preference\NotificationTopic;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Client\ClientActivity;
use App\Shared\Application\Client\ClientActivityCounts;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Application\Storage\FileReader;
use App\Shared\Application\Storage\StoredFileNotFound;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * C07 « Mon profil » (Figma 231:12840) : synthèse (photo, identité, coordonnées, type de compte), informations
 * personnelles, adresse et rayon de recherche, domaines d'intérêt et notifications e-mail, complétude, badges, activité.
 */
#[Route('/espace-client/profil')]
final class ClientProfileController extends AbstractController
{
    private const string CSRF = 'client_profile';
    /** Badge « Fidèle TrouveMoi » : nombre de devis acceptés. */
    private const int LOYAL_ACCEPTED_QUOTES = 3;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
        private readonly CurrentUser $user,
        private readonly SettingsProvider $settings,
    ) {
    }

    #[Route('', name: 'client_profile', methods: ['GET', 'POST'])]
    public function index(Request $request, FileReader $files): Response
    {
        $userId = $this->userId();
        $profile = $this->profile($userId);
        $errors = [];
        $submitted = null;
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);
            $submitted = $request->request->all();
            $radius = $request->request->getString('searchRadiusKm');
            /** @var list<string> $interests */
            $interests = array_values(array_filter($request->request->all('interests'), \is_string(...)));
            $notifications = $request->request->all('notifications');
            try {
                $this->commands->dispatch(new UpdateClientProfile(
                    $userId,
                    $request->request->getString('firstName'),
                    $request->request->getString('lastName'),
                    $request->request->getString('phone'),
                    $request->request->getString('postalCode'),
                    '' === $radius ? null : (int) $radius,
                    $interests,
                    $request->request->getBoolean('newsletter'),
                ));
                $topics = [];
                foreach (NotificationTopic::profileTopics() as $topic) {
                    $topics[$topic->value] = isset($notifications[$topic->value]);
                }
                $this->commands->dispatch(new ChangeEmailPreferences($userId, $topics));
                $this->addFlash('success', 'Vos modifications sont enregistrées.');

                return $this->redirectToRoute('client_profile', [], Response::HTTP_SEE_OTHER);
            } catch (ClientProfileRejected $e) {
                $errors[$e->field] = $e->getMessage();
            }
        }

        $activity = $this->queries->ask(new ClientActivity($userId));
        \assert($activity instanceof ClientActivityCounts);
        $emailPreferences = $this->queries->ask(new EmailPreferences($userId));
        $avatarReady = false;
        if (null !== $profile->avatarFileId) {
            try {
                $files->available($profile->avatarFileId);
                $avatarReady = true;
            } catch (StoredFileNotFound) {
            }
        }
        $radii = $this->settings->intList(SettingKey::SearchRadiusOptions);
        $completion = [
            'Informations personnelles' => null !== $profile->phone,
            'Adresse et zone' => null !== $profile->searchRadiusKm,
            'Préférences' => [] !== $profile->interests,
        ];

        return $this->render('client/profile/index.html.twig', [
            'profile' => $profile,
            'submitted' => $submitted,
            'errors' => $errors,
            'interests' => ClientInterest::cases(),
            'topics' => NotificationTopic::profileTopics(),
            'email_preferences' => $emailPreferences,
            'activity' => $activity,
            'badges' => [
                ['label' => 'Membre vérifié', 'icon' => 'check', 'earned' => $profile->emailVerified && null !== $profile->phone, 'tone' => 'green', 'hint' => 'Adresse e-mail validée et téléphone renseigné'],
                ['label' => 'Client actif', 'icon' => 'calCheck', 'earned' => $activity->requests > 0, 'tone' => 'blue', 'hint' => 'Au moins une demande déposée'],
                ['label' => 'Fidèle TrouveMoi', 'icon' => 'handshake', 'earned' => $activity->acceptedQuotes >= self::LOYAL_ACCEPTED_QUOTES, 'tone' => 'orange', 'hint' => self::LOYAL_ACCEPTED_QUOTES.' devis acceptés'],
            ],
            'completion' => $completion,
            'completion_percent' => (int) round(100 * \count(array_filter($completion)) / \count($completion)),
            'avatar_ready' => $avatarReady,
            'radius_min' => [] === $radii ? 1 : min($radii),
            'radius_max' => [] === $radii ? 100 : max($radii),
            'radius_default' => $this->settings->int(SettingKey::SearchDefaultRadiusKm),
            'csrf_id' => self::CSRF,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/photo', name: 'client_profile_avatar', methods: ['POST'])]
    public function changeAvatar(Request $request): Response
    {
        $this->assertCsrf($request);
        $file = $request->files->get('avatar');
        $remove = $request->request->getBoolean('remove');
        if (!$remove && !$file instanceof UploadedFile) {
            $this->addFlash('error', 'Choisissez une photo.');

            return $this->redirectToRoute('client_profile', [], Response::HTTP_SEE_OTHER);
        }
        try {
            $this->commands->dispatch(new ChangeClientAvatar($this->userId(), $remove || !$file instanceof UploadedFile ? null : $file->getPathname(), $file instanceof UploadedFile ? $file->getClientOriginalName() : null));
            $this->addFlash('success', $remove ? 'Votre photo est retirée.' : 'Votre photo est enregistrée ; elle s’affiche après la vérification antivirus.');
        } catch (ClientProfileRejected $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('client_profile', [], Response::HTTP_SEE_OTHER);
    }

    /** Photo du titulaire, servie une fois l'analyse antivirus passée. */
    #[Route('/photo', name: 'client_profile_avatar_show', methods: ['GET'])]
    public function avatar(Request $request, FileReader $files): StreamedResponse
    {
        $userId = $this->userId();
        $fileId = $this->profile($userId)->avatarFileId ?? throw new NotFoundHttpException();
        try {
            $file = $files->available($fileId);
            $stream = $files->openForActor($file->id, 'user', $userId, $request->getClientIp());
        } catch (StoredFileNotFound) {
            throw new NotFoundHttpException();
        }

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, Response::HTTP_OK, [
            'Content-Type' => $file->type->value,
            'Content-Length' => (string) $file->size,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    #[Route('/type', name: 'client_profile_type', methods: ['POST'])]
    public function changeType(Request $request): Response
    {
        $this->assertCsrf($request);
        $business = 'professionnel' === $request->request->getString('type');
        try {
            $this->commands->dispatch(new ChangeClientType($this->userId(), $business, $request->request->getString('companyName'), $request->request->getString('siret')));
            $this->addFlash('success', $business ? 'Votre compte est désormais un compte professionnel.' : 'Votre compte est désormais un compte particulier.');
        } catch (ClientProfileRejected $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('client_profile', [], Response::HTTP_SEE_OTHER);
    }

    private function profile(string $userId): ClientProfileView
    {
        $profile = $this->queries->ask(new ClientProfilePage($userId));
        if (!$profile instanceof ClientProfileView) {
            throw new NotFoundHttpException();
        }

        return $profile;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }
    }

    private function userId(): string
    {
        return $this->user->id() ?? throw new AccessDeniedHttpException();
    }
}
