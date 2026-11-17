<?php

declare(strict_types=1);

namespace App\Identity\Application\Legal;

use App\Identity\Application\Port\LegalAcceptanceLog;
use App\Identity\Application\Port\LegalDocuments;
use App\Identity\Domain\Legal\LegalDocumentType;
use App\Identity\Domain\User\User;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserKind;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Time\Clock;

final readonly class LegalReacceptanceHandler implements QueryHandler
{
    public function __construct(private UserRepository $users, private LegalDocuments $documents, private LegalAcceptanceLog $acceptances, private Clock $clock)
    {
    }

    /** @return list<LegalDocumentVersion> */
    public function __invoke(PendingLegalAcceptances $query): array
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $query->userId)) {
            return [];
        }
        $user = $this->users->find(new UserId($query->userId));

        return null === $user ? [] : $this->pending($user);
    }

    /** @return list<LegalDocumentVersion> */
    public function pending(User $user): array
    {
        $now = $this->clock->now();
        $pending = [];
        foreach (self::types($user->kind()) as $type) {
            $substantial = $this->documents->latestSubstantial($type, $now);
            $current = $this->documents->current($type, $now);
            if (null !== $substantial && null !== $current && !$this->acceptances->hasAcceptedSince($user->id()->value, $substantial)) {
                $pending[] = $current;
            }
        }

        return $pending;
    }

    /** @return list<LegalDocumentType> */
    private static function types(UserKind $kind): array
    {
        return UserKind::Artisan === $kind
            ? [LegalDocumentType::TermsOfUse, LegalDocumentType::ProTermsOfSale]
            : [LegalDocumentType::TermsOfUse];
    }
}
