<?php

declare(strict_types=1);

namespace App\ArtisanProfile\UI\Http;

use App\ArtisanProfile\Application\Edit\EditablePublicProfile;
use App\ArtisanProfile\Application\Edit\EditablePublicProfileView;
use App\ArtisanProfile\Application\Edit\UpdateAvailability;
use App\ArtisanProfile\Application\Edit\UpdatePublicPresentation;
use App\ArtisanProfile\Application\Edit\UpdateServices;
use App\ArtisanProfile\Application\Portfolio\AddPortfolioPhoto;
use App\ArtisanProfile\Application\Portfolio\DeletePortfolioPhoto;
use App\ArtisanProfile\Application\Portfolio\DeletePortfolioProject;
use App\ArtisanProfile\Application\Portfolio\PortfolioEditor;
use App\ArtisanProfile\Application\Portfolio\SavePortfolioProject;
use App\ArtisanProfile\Application\Signup\SignupRejected;
use App\ArtisanProfile\Application\Signup\UploadLogo;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\ArtisanProfile\Domain\Profile\OpeningHours;
use App\ArtisanProfile\Domain\Profile\PublicPresentation;
use App\ArtisanProfile\Domain\Profile\ServiceOffer;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Domain\Identifier\IdGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PR08 (/espace-pro/profil) — édition du profil public : jauge de complétude, identité visuelle (logo, nom commercial,
 * slogan), présentation, prestations et tarifs indicatifs, horaires et contact (option téléphone, RG-030) ; métiers,
 * zone, assurances et justificatifs via les écrans du dossier ; aperçu de la fiche. Une saisie refusée (RG-031) est
 * réaffichée telle quelle avec le motif.
 */
#[Route('/espace-pro/profil')]
final class ProProfileController extends AbstractController
{
    public const array SECTIONS = ['identite', 'presentation', 'prestations', 'horaires'];
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(private readonly QueryBus $queries, private readonly CommandBus $commands, private readonly CurrentUser $currentUser)
    {
    }

    #[Route('', name: 'pro_profile', methods: ['GET'])]
    public function index(): Response
    {
        return $this->page([], null, null);
    }

    #[Route('/{section}', name: 'pro_profile_save', requirements: ['section' => 'identite|presentation|prestations|horaires'], methods: ['POST'])]
    public function save(Request $request, string $section): Response
    {
        if (!$this->isCsrfTokenValid('pro_profile_'.$section, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
        $posted = $request->request->all();
        try {
            $this->commands->dispatch($this->command($request, $section));
            $this->addFlash('success', 'Profil mis à jour.');

            return $this->redirect($this->generateUrl('pro_profile').'#'.$section, Response::HTTP_SEE_OTHER);
        } catch (ArtisanRuleViolation|SignupRejected $e) {
            return $this->page($posted, $section, $e->getMessage());
        }
    }

    /** PR08.6 — création (identifiant généré) ou modification d'une réalisation. */
    #[Route('/realisations', name: 'pro_portfolio_save', methods: ['POST'])]
    public function saveProject(Request $request, IdGenerator $ids): Response
    {
        $r = $request->request;
        $projectId = $r->getString('project_id');
        $creation = '' === $projectId;

        return $this->portfolio($request, 'pro_portfolio', new SavePortfolioProject(
            $this->userId(),
            $creation ? $ids->next() : $projectId,
            $creation,
            $r->getString('title'),
            $r->getString('description'),
            $r->getString('city'),
            $r->getString('done_on'),
            $r->getString('job_id'),
        ), $creation ? 'Réalisation ajoutée : déposez maintenant ses photos.' : 'Réalisation modifiée.');
    }

    #[Route('/realisations/{id}/supprimer', name: 'pro_portfolio_delete', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function deleteProject(Request $request, string $id): Response
    {
        return $this->portfolio($request, 'pro_portfolio', new DeletePortfolioProject($this->userId(), $id), 'Réalisation supprimée.');
    }

    #[Route('/realisations/{id}/photos', name: 'pro_portfolio_photo', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function addPhoto(Request $request, string $id): Response
    {
        $file = $request->files->get('photo');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', 'Choisissez une photo (JPEG, PNG ou WebP).');

            return $this->redirect($this->generateUrl('pro_profile').'#realisations', Response::HTTP_SEE_OTHER);
        }

        return $this->portfolio($request, 'pro_portfolio', new AddPortfolioPhoto($this->userId(), $id, $file->getPathname(), $file->getClientOriginalName(), $request->request->getString('kind', 'photo')), 'Photo ajoutée : elle apparaîtra sur votre fiche après son analyse de sécurité.');
    }

    #[Route('/photos/{id}/supprimer', name: 'pro_portfolio_photo_delete', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function deletePhoto(Request $request, string $id): Response
    {
        return $this->portfolio($request, 'pro_portfolio', new DeletePortfolioPhoto($this->userId(), $id), 'Photo supprimée.');
    }

    private function portfolio(Request $request, string $tokenId, Command $command, string $success): Response
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
        try {
            $this->commands->dispatch($command);
            $this->addFlash('success', $success);
        } catch (ArtisanRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($this->generateUrl('pro_profile').'#realisations', Response::HTTP_SEE_OTHER);
    }

    private function command(Request $request, string $section): Command
    {
        $userId = $this->userId();
        $r = $request->request;

        return match ($section) {
            'identite' => $this->logo($request) ?? new UpdatePublicPresentation($userId, $r->getString('trade_name'), $r->getString('slogan'), $this->current()->presentation->description, $this->current()->presentation->strengths, $this->current()->presentation->languages, $this->current()->presentation->yearsExperience, $this->current()->presentation->clients),
            'presentation' => new UpdatePublicPresentation(
                $userId,
                $this->current()->tradeName,
                $this->current()->presentation->slogan,
                $r->getString('description'),
                self::strings($r->all('strengths')),
                self::strings($r->all('languages')),
                '' === trim($r->getString('years')) ? null : $r->getInt('years'),
                self::strings($r->all('clients')),
            ),
            'prestations' => new UpdateServices($userId, array_values(array_map(
                static fn (mixed $row): array => ['title' => \is_array($row) && \is_string($row['title'] ?? null) ? $row['title'] : '', 'price' => \is_array($row) && \is_string($row['price'] ?? null) ? $row['price'] : ''],
                $r->all('services'),
            ))),
            default => new UpdateAvailability(
                $userId,
                array_map(static fn (mixed $ranges): array => self::strings(\is_array($ranges) ? $ranges : []), array_intersect_key($r->all('days'), array_flip(OpeningHours::DAYS))),
                $r->getBoolean('emergencies'),
                $r->getString('emergency_note'),
                $r->getBoolean('phone_public'),
            ),
        };
    }

    /** PR08.2 — un nouveau logo déposé remplace l'ancien (même contrôle que l'inscription : assaini, analysé). */
    private function logo(Request $request): ?UploadLogo
    {
        $file = $request->files->get('logo');

        return $file instanceof UploadedFile && $file->isValid() ? new UploadLogo($this->userId(), $file->getPathname(), $file->getClientOriginalName()) : null;
    }

    /** @param array<array-key, mixed> $posted saisie à réafficher après un refus */
    private function page(array $posted, ?string $errorSection, ?string $error): Response
    {
        $profile = $this->current();

        return $this->render('pro/profile.html.twig', [
            'p' => $profile,
            'posted' => $posted,
            'error_section' => $errorSection,
            'error' => $error,
            'languages' => PublicPresentation::LANGUAGES,
            'clients' => PublicPresentation::CLIENTS,
            'days' => OpeningHours::DAYS,
            'photo_kinds' => PortfolioEditor::KINDS,
            'portfolio_limits' => ['projects' => PortfolioEditor::MAX_PROJECTS, 'photos' => PortfolioEditor::MAX_PHOTOS],
            'job_names' => $this->jobNames($profile->jobIds),
            'limits' => [
                'slogan' => PublicPresentation::SLOGAN_MAX, 'description' => PublicPresentation::DESCRIPTION_MAX, 'strengths' => PublicPresentation::STRENGTHS_MAX,
                'strength' => PublicPresentation::STRENGTH_MAX, 'services' => ServiceOffer::MAX, 'service_title' => ServiceOffer::TITLE_MAX, 'service_price' => ServiceOffer::PRICE_MAX,
                'ranges' => OpeningHours::RANGES_PER_DAY, 'emergency_note' => OpeningHours::EMERGENCY_NOTE_MAX,
            ],
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, string>
     */
    private function jobNames(array $ids): array
    {
        $entries = [] === $ids ? [] : $this->queries->ask(new JobIndexEntries($ids));
        $names = [];
        foreach (\is_array($entries) ? $entries : [] as $entry) {
            if ($entry instanceof JobIndexEntry) {
                $names[$entry->id] = $entry->name;
            }
        }

        return $names;
    }

    private function current(): EditablePublicProfileView
    {
        $view = $this->queries->ask(new EditablePublicProfile($this->userId()));

        return $view instanceof EditablePublicProfileView ? $view : throw $this->createNotFoundException('Profil professionnel introuvable.');
    }

    private function userId(): string
    {
        return $this->currentUser->id() ?? throw new AccessDeniedHttpException();
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_filter($values, \is_string(...)));
    }
}
