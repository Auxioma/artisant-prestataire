<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\ArtisanProfile\Domain\Document\ArtisanDocumentRepository;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Time\Clock;

final readonly class CompleteDocumentsStepHandler implements CommandHandler
{
    public function __construct(
        private ArtisanProfileRepository $profiles,
        private ArtisanDocumentRepository $documents,
        private SignupRequirements $requirements,
        private Clock $clock,
    ) {
    }

    public function __invoke(CompleteDocumentsStep $command): void
    {
        $profile = $this->profiles->findByUserId($command->userId) ?? throw new ArtisanRuleViolation('Déclarez d’abord votre entreprise.');
        if (!$command->later) {
            $missing = array_filter($this->requirements->checklist($profile, $this->documents->currentOf($profile->id()->value)), static fn (DocumentSlot $s): bool => $s->required && !$s->provided());
            if ([] !== $missing) {
                throw new SignupRejected('documents', SignupRejected::DOCUMENTS_MISSING, 'Il manque : '.implode(', ', array_map(static fn (DocumentSlot $s): string => $s->label, $missing)).'. Déposez-les ou choisissez « Terminer plus tard ».');
            }
        }
        $profile->completeDocumentsStep($this->clock->now());
    }
}
