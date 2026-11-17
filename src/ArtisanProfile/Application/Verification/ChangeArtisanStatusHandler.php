<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Verification;

use App\ArtisanProfile\Application\Port\VerificationDecisions;
use App\ArtisanProfile\Application\Signup\DocumentSlot;
use App\ArtisanProfile\Application\Signup\SignupRequirements;
use App\ArtisanProfile\Domain\Document\ArtisanDocumentRepository;
use App\ArtisanProfile\Domain\Document\DocumentStatus;
use App\ArtisanProfile\Domain\Profile\ArtisanProfile;
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
        private ArtisanDocumentRepository $documents,
        private SignupRequirements $requirements,
        private VerificationDecisions $decisions,
        private EventBus $events,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(ApproveArtisan|RejectArtisan|RequestArtisanInfo|SuspendArtisan|ReinstateArtisan $command): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $command->profileId)) {
            throw new ArtisanRuleViolation('Profil professionnel introuvable.');
        }
        $profile = $this->profiles->find(new ArtisanProfileId($command->profileId)) ?? throw new ArtisanRuleViolation('Profil professionnel introuvable.');
        $now = $this->clock->now();
        $from = $profile->status()->value;
        $context = [];

        if ($command instanceof ApproveArtisan) {
            $this->approveDocuments($profile, $command->adminId, $now);
            $profile->approve($command->adminId, $now);
            $this->decisions->record($this->ids->next(), $profile->id()->value, $command->adminId, 'approved', null, null, $now);
            $action = 'artisan.verified';
        } elseif ($command instanceof RejectArtisan) {
            $reason = RejectionReason::tryFrom($command->reason) ?? throw new ArtisanRuleViolation('Motif de refus inconnu.');
            $profile->reject($command->adminId, $reason, $command->comment, $now);
            $this->decisions->record($this->ids->next(), $profile->id()->value, $command->adminId, 'rejected', $reason->value, $profile->statusComment(), $now);
            $action = 'artisan.rejected';
            $context = ['reason' => $reason->value];
        } elseif ($command instanceof RequestArtisanInfo) {
            $profile->requestMoreInfo($command->adminId, $command->message, $now);
            $this->decisions->record($this->ids->next(), $profile->id()->value, $command->adminId, 'more_info', null, $profile->statusComment(), $now);
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

    /**
     * Validation du dossier : toutes les pièces obligatoires doivent être déposées, analysées et non refusées ; les
     * pièces en attente de contrôle sont validées avec le dossier (une seule notification : E-21).
     */
    private function approveDocuments(ArtisanProfile $profile, string $adminId, \DateTimeImmutable $now): void
    {
        $current = $this->documents->currentOf($profile->id()->value);
        $blocking = array_filter($this->requirements->checklist($profile, $current), static fn (DocumentSlot $s): bool => $s->required && !\in_array($s->status, [DocumentStatus::PendingReview->value, DocumentStatus::Approved->value], true));
        if ([] !== $blocking) {
            throw new ArtisanRuleViolation('Dossier incomplet : '.implode(', ', array_map(static fn (DocumentSlot $s): string => $s->label.' ('.match ($s->status) {
                null => 'absente', DocumentStatus::Quarantined->value => 'analyse en cours', default => 'refusée',
            }.')', $blocking)).'. Demandez un complément.');
        }
        foreach ($current as $document) {
            if (DocumentStatus::PendingReview === $document->status()) {
                $document->approve($adminId, $now);
                $document->releaseEvents(); // pas d'E-24 pièce par pièce : la validation du dossier est notifiée (E-21)
            }
        }
    }
}
