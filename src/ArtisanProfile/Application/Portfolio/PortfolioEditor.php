<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Portfolio;

use App\ArtisanProfile\Application\Edit\ProfileModeration;
use App\ArtisanProfile\Application\Port\PortfolioStore;
use App\ArtisanProfile\Domain\Profile\ArtisanProfile;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\ArtisanProfile\Domain\Profile\ArtisanStatus;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanPublicProfileUpdated;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Storage\FileShredder;
use App\Shared\Application\Storage\FileUploader;
use App\Shared\Application\Storage\RejectedUpload;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Storage\FilePurpose;
use App\Shared\Domain\Time\Clock;

/**
 * PR08.6 — portfolio : projets (titre, description, ville, date, métier exercé) et photos avant / après. Textes
 * modérés (RG-031) ; photos ré-encodées, débarrassées de leurs métadonnées et analysées par l'antivirus avant toute
 * publication ; une photo supprimée est détruite.
 */
final readonly class PortfolioEditor implements CommandHandler
{
    public const int MAX_PROJECTS = 20;
    public const int MAX_PHOTOS = 10;
    public const array KINDS = ['photo' => 'Photo', 'before' => 'Avant', 'after' => 'Après'];
    private const int TITLE_MAX = 100;
    private const int DESCRIPTION_MAX = 1000;
    private const int CITY_MAX = 100;
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const string SHRED_REASON = 'portfolio_photo_removed';

    public function __construct(
        private ArtisanProfileRepository $profiles,
        private PortfolioStore $portfolio,
        private ProfileModeration $moderation,
        private FileUploader $uploader,
        private FileShredder $shredder,
        private EventBus $events,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(SavePortfolioProject|DeletePortfolioProject|AddPortfolioPhoto|DeletePortfolioPhoto $command): void
    {
        $profile = $this->profiles->findByUserId($command->userId) ?? throw new ArtisanRuleViolation('Profil professionnel introuvable.');
        if (ArtisanStatus::Incomplete === $profile->status()) {
            throw new ArtisanRuleViolation('Terminez d’abord votre inscription.');
        }
        $profileId = $profile->id()->value;
        $now = $this->clock->now();

        if ($command instanceof SavePortfolioProject) {
            $this->save($profile, $command, $now);
        } elseif ($command instanceof DeletePortfolioProject) {
            $this->owned($profileId, $command->projectId);
            foreach ($this->portfolio->deleteProject($profileId, $command->projectId) as $fileId) {
                $this->shredder->shred($fileId, self::SHRED_REASON, $command->userId);
            }
        } elseif ($command instanceof AddPortfolioPhoto) {
            $project = $this->owned($profileId, $command->projectId);
            if (\count($project->photos) >= self::MAX_PHOTOS) {
                throw new ArtisanRuleViolation(\sprintf('%d photos au maximum par réalisation.', self::MAX_PHOTOS));
            }
            if (!\array_key_exists($command->kind, self::KINDS)) {
                throw new ArtisanRuleViolation('Type de photo inconnu.');
            }
            try {
                $file = $this->uploader->upload($command->path, $command->clientName, FilePurpose::PortfolioPhoto);
            } catch (RejectedUpload $e) {
                throw new ArtisanRuleViolation(match ($e->reason) {
                    RejectedUpload::TYPE_NOT_ALLOWED => 'Format non accepté : JPEG, PNG ou WebP.', RejectedUpload::TOO_LARGE => 'Photo trop lourde.', RejectedUpload::IMAGE_TOO_LARGE => 'Image trop grande : réduisez sa résolution.', default => 'Cette photo n’a pas pu être acceptée.',
                });
            }
            $this->portfolio->addPhoto($this->ids->next(), $project->id, $file->id, $command->kind, $now);
            $this->moderation->sample($profileId, 'portfolio_photo', ['project' => $project->id, 'file' => $file->id], $now);
        } else {
            $fileId = 1 === preg_match(self::UUID, $command->photoId) ? $this->portfolio->deletePhoto($profileId, $command->photoId) : null;
            if (null === $fileId) {
                throw new ArtisanRuleViolation('Photo introuvable.');
            }
            $this->shredder->shred($fileId, self::SHRED_REASON, $command->userId);
        }
        $this->events->publish(new ArtisanPublicProfileUpdated($profileId, $profile->userId(), $now));
    }

    private function save(ArtisanProfile $profile, SavePortfolioProject $command, \DateTimeImmutable $now): void
    {
        $profileId = $profile->id()->value;
        if ($command->creation && $this->portfolio->countProjects($profileId) >= self::MAX_PROJECTS) {
            throw new ArtisanRuleViolation(\sprintf('%d réalisations au maximum.', self::MAX_PROJECTS));
        }
        if (!$command->creation) {
            $this->owned($profileId, $command->projectId);
        }
        $title = trim($command->title);
        $description = self::clean($command->description);
        $city = self::clean($command->city);
        if ('' === $title || mb_strlen($title) > self::TITLE_MAX || mb_strlen($description ?? '') > self::DESCRIPTION_MAX || mb_strlen($city ?? '') > self::CITY_MAX) {
            throw new ArtisanRuleViolation(\sprintf('Réalisation : titre obligatoire (%d caractères max), description %d caractères max.', self::TITLE_MAX, self::DESCRIPTION_MAX));
        }
        $doneOn = null;
        if (null !== self::clean($command->doneOn)) {
            $doneOn = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $command->doneOn) ?: throw new ArtisanRuleViolation('Date de réalisation invalide.');
            if ($doneOn > $now) {
                throw new ArtisanRuleViolation('La date de réalisation ne peut pas être dans le futur.');
            }
        }
        $jobId = self::clean($command->jobId);
        $jobs = array_map(static fn ($job): string => $job->value, $profile->jobIds());
        if (null !== $jobId && !\in_array($jobId, $jobs, true)) {
            throw new ArtisanRuleViolation('Choisissez un de vos métiers.');
        }
        $this->moderation->check([$title, $description ?? '', $city ?? '']);
        $this->portfolio->saveProject($command->projectId, $profileId, $title, $description, $city, $doneOn, $jobId, $now);
        $this->moderation->sample($profileId, 'portfolio', ['project' => $command->projectId, 'title' => $title, 'description' => $description, 'city' => $city], $now);
    }

    private function owned(string $profileId, string $projectId): PortfolioProject
    {
        return (1 === preg_match(self::UUID, $projectId) ? $this->portfolio->project($profileId, $projectId) : null) ?? throw new ArtisanRuleViolation('Réalisation introuvable.');
    }

    private static function clean(?string $value): ?string
    {
        $value = null === $value ? '' : trim(str_replace(["\r\n", "\r"], "\n", $value));

        return '' === $value ? null : $value;
    }
}
