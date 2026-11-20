<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Scheduler;

use App\Shared\Application\Audit\AuditTrail;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * docs/incidents.md (T-906a) — chaque nuit, avant la purge de rétention : recalcul de la chaîne d'empreintes du journal
 * d'audit. Une altération produit un journal CRITICAL, relevé par la supervision (alerte immédiate).
 */
#[AsCronTask('0 3 * * *', timezone: 'Europe/Paris')]
final readonly class AuditIntegrityTask
{
    public function __construct(private AuditTrail $audit, private LoggerInterface $logger)
    {
    }

    public function __invoke(): void
    {
        $broken = $this->audit->verifyChain();
        if (null !== $broken) {
            $this->logger->critical('Journal d’audit altéré : première entrée incohérente {entry}. Appliquer docs/incidents.md.', ['entry' => $broken, 'alert' => 'audit_integrity']);
        }
    }
}
