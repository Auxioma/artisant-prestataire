<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\ArtisanProfile\Domain\Document\ArtisanDocumentRepository;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\Shared\Application\Bus\QueryHandler;

final readonly class DocumentChecklistHandler implements QueryHandler
{
    public function __construct(
        private ArtisanProfileRepository $profiles,
        private ArtisanDocumentRepository $documents,
        private SignupRequirements $requirements,
    ) {
    }

    /** @return list<DocumentSlot> */
    public function __invoke(DocumentChecklist $query): array
    {
        $profile = $this->profiles->findByUserId($query->userId);

        return null === $profile ? [] : $this->requirements->checklist($profile, $this->documents->currentOf($profile->id()->value));
    }
}
