<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Security\ImpersonationContext;
use App\Shared\Domain\Audit\AuditRecord;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/** ADR-0013 — pendant une connexion assistée, chaque action de l'utilisateur est attribuée aussi à l'administrateur. */
#[AsDecorator(AuditTrail::class)]
final readonly class ImpersonationAwareAuditTrail implements AuditTrail
{
    public function __construct(#[AutowireDecorated] private AuditTrail $inner, private ImpersonationContext $impersonation)
    {
    }

    public function record(AuditRecord $record): void
    {
        $session = null === $record->impersonatorId && 'user' === $record->actorType ? $this->impersonation->current() : null;
        $this->inner->record(null === $session ? $record : new AuditRecord(
            $record->id,
            $record->occurredAt,
            $record->actorType,
            $record->actorId,
            $record->action,
            $record->subjectType,
            $record->subjectId,
            $record->ip,
            $session->adminId,
            $record->context,
        ));
    }

    public function verifyChain(): ?string
    {
        return $this->inner->verifyChain();
    }
}
