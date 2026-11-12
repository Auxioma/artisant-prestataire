<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Verification;

use App\ArtisanProfile\Domain\Profile\ArtisanProfileId;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\ArtisanProfile\Domain\Profile\RejectionReason;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * Transitions RG-043 décidées par un administrateur (écrans BO03 : T-210) ou par le système. Chaque décision est
 * horodatée avec son auteur (RG-130) et journalisée ; les suites (e-mails, crédit gratuit, visibilité) suivent les événements.
 */
final readonly class ChangeArtisanStatusHandler implements CommandHandler
{
    public function __construct(
        private ArtisanProfileRepository $profiles,
        private EventBus $events,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(ApproveArtisan|RejectArtisan|RequestArtisanInfo|SuspendArtisan|ReinstateArtisan $command): void
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}$/', $command->profileId)) {
            throw new ArtisanRuleViolation('Profil professionnel introuvable.');
        }
        $profile = $this->profiles->find(new ArtisanProfileId($command->profileId)) ?? throw new ArtisanRuleViolation('Profil professionnel introuvable.');
        $now = $this->clock->now();
        $from = $profile->status()->value;
        $context = [];

        if ($command instanceof ApproveArtisan) {
            $profile->approve($command->adminId, $now);
            $action = 'artisan.verified';
        } elseif ($command instanceof RejectArtisan) {
            $reason = RejectionReason::tryFrom($command->reason) ?? throw new ArtisanRuleViolation('Motif de refus inconnu.');
            $profile->reject($command->adminId, $reason, $command->comment, $now);
            $action = 'artisan.rejected';
            $context = ['reason' => $reason->value];
        } elseif ($command instanceof RequestArtisanInfo) {
            $profile->requestMoreInfo($command->adminId, $command->message, $now);
            $action = 'artisan.more_info_requested';
        } elseif ($command instanceof SuspendArtisan) {
            $profile->suspend($command->adminId, $command->reason, $now);
            $action = 'artisan.suspended';
        } else {
            $profile->reinstate($command->adminId, $now);
            $action = 'artisan.reinstated';
        }

        $this->events->publish(...$profile->releaseEvents());
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: null === $command->adminId ? 'system' : 'admin',
            actorId: $command->adminId,
            action: $action,
            subjectType: 'artisan_profile',
            subjectId: $profile->id()->value,
            context: ['from' => $from, 'to' => $profile->status()->value] + $context,
        ));
    }
}
