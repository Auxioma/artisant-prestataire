<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Verification;

use App\ArtisanProfile\Application\Signup\DocumentSlot;

final readonly class DossierView
{
    /**
     * @param list<string>                                                                                                            $jobs
     * @param array<string, ?string>                                                                                                  $company   données déclarées
     * @param array{status: string, name: ?string, active: ?bool, ape: ?string, createdOn: ?\DateTimeImmutable, city: ?string}|null   $sirene    rappel en direct (null : répertoire indisponible)
     * @param list<array{level: string, label: string}>                                                                               $checks    ok | warning | error
     * @param list<array{slot: DocumentSlot, id: ?string, reason: ?string}>                                                           $documents
     * @param list<array{decision: string, reason_code: ?string, comment: ?string, admin_id: string, created_at: \DateTimeImmutable}> $history
     */
    public function __construct(
        public string $profileId,
        public string $status,
        public string $name,
        public ?string $manager,
        public ?string $email,
        public ?\DateTimeImmutable $submittedAt,
        public array $jobs,
        public string $area,
        public array $company,
        public ?array $sirene,
        public array $checks,
        public array $documents,
        public array $history,
        public ?string $statusReason,
        public ?string $statusComment,
        public int $completion,
    ) {
    }
}
