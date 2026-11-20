<?php

declare(strict_types=1);

namespace App\Administration\UI\Console;

use App\Shared\Application\Audit\AuditTrail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Procédure d'incident (docs/incidents.md, T-905c) : recalcule la chaîne d'empreintes du journal d'audit depuis le
 * dernier point d'ancrage de purge. Code de sortie 1 et identifiant de la première entrée incohérente si une entrée a
 * été modifiée, insérée ou supprimée au milieu de la chaîne. À lancer au début de toute investigation et chaque jour
 * par la supervision.
 */
#[AsCommand(name: 'app:audit:verify', description: 'Vérifie l’intégrité du journal d’audit (chaîne d’empreintes)')]
final class VerifyAuditChainCommand extends Command
{
    public function __construct(private readonly AuditTrail $audit)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $broken = $this->audit->verifyChain();
        if (null !== $broken) {
            $io->error(\sprintf('Journal d’audit altéré : première entrée incohérente %s. Préserver les preuves (docs/incidents.md).', $broken));

            return Command::FAILURE;
        }
        $io->success('Journal d’audit intègre depuis le dernier point d’ancrage.');

        return Command::SUCCESS;
    }
}
