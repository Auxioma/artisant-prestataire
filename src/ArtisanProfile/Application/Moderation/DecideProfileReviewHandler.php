<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Moderation;

use App\ArtisanProfile\Application\Port\PortfolioStore;
use App\ArtisanProfile\Application\Port\ProfileReviews;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileId;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanPublicProfileUpdated;
use App\ArtisanProfile\Domain\Profile\PublicPresentation;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Moderation\DecideProfileReview;
use App\Shared\Application\Moderation\ModerationDecisionRejected;
use App\Shared\Application\Storage\FileShredder;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * RG-031 / BO05 — relecture humaine d'un contenu de profil : validé, ou retiré du profil public (présentation vidée,
 * prestations retirées, réalisation ou photo supprimée et fichiers détruits). Journalisé ; fiche et recherche
 * recalculées par ArtisanPublicProfileUpdated.
 */
final readonly class DecideProfileReviewHandler implements CommandHandler
{
    private const string SHRED_REASON = 'profile_moderation';

    public function __construct(
        private ProfileReviews $reviews,
        private ArtisanProfileRepository $profiles,
        private PortfolioStore $portfolio,
        private FileShredder $shredder,
        private EventBus $events,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(DecideProfileReview $command): void
    {
        $review = $this->reviews->find($command->reviewId);
        if (null === $review || 'pending' !== $review['status']) {
            throw new ModerationDecisionRejected('Ce contenu a déjà été traité ou n’existe plus.');
        }
        if (!$command->approve && !\array_key_exists($command->reason ?? '', DecideProfileReview::REASONS)) {
            throw new ModerationDecisionRejected('Choisissez un motif pour retirer ce contenu.');
        }
        $now = $this->clock->now();
        if (!$command->approve) {
            $this->withdraw($review['artisan_id'], $review['section'], $review['content'], $now);
        }
        $this->reviews->decide($command->reviewId, $command->approve, $command->adminId, $command->approve ? null : $command->reason, $now);
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'admin',
            actorId: $command->adminId,
            action: $command->approve ? 'moderation.profile_content_approved' : 'moderation.profile_content_withdrawn',
            subjectType: 'artisan_profile',
            subjectId: $review['artisan_id'],
            context: array_filter(['section' => $review['section'], 'reason' => $command->approve ? null : $command->reason]),
        ));
    }

    /** @param array<string, mixed> $content */
    private function withdraw(string $profileId, string $section, array $content, \DateTimeImmutable $now): void
    {
        $profile = $this->profiles->find(new ArtisanProfileId($profileId)) ?? throw new ModerationDecisionRejected('Profil introuvable.');
        $files = [];
        match ($section) {
            'presentation' => $profile->updatePresentation($profile->tradeName(), PublicPresentation::fromArray([]), $now),
            'services' => $profile->updateServices([], $now),
            'portfolio' => $files = \is_string($content['project'] ?? null) ? $this->portfolio->deleteProject($profileId, $content['project']) : [],
            default => $files = $this->deletePhoto($profileId, \is_string($content['file'] ?? null) ? $content['file'] : ''),
        };
        foreach ($files as $fileId) {
            $this->shredder->shred($fileId, self::SHRED_REASON);
        }
        $this->events->publish(...$profile->releaseEvents());
        if (\in_array($section, ['portfolio', 'portfolio_photo'], true)) {
            $this->events->publish(new ArtisanPublicProfileUpdated($profileId, $profile->userId(), $now));
        }
    }

    /** @return list<string> */
    private function deletePhoto(string $profileId, string $fileId): array
    {
        $photo = '' === $fileId ? null : $this->portfolio->photoOfFile($fileId);
        $file = null === $photo ? null : $this->portfolio->deletePhoto($profileId, $photo);

        return null === $file ? [] : [$file];
    }
}
