<?php

declare(strict_types=1);

namespace App\Administration\UI\Console;

use App\Administration\Application\Team\InvitationToken;
use App\Administration\Application\Team\InviteAdmin;
use App\Administration\Application\Team\TeamRejected;
use App\Administration\Domain\Admin\AdminRole;
use App\Administration\Domain\Admin\AdminRuleViolation;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Identifier\IdGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Amorçage de l'équipe : invite un administrateur depuis le serveur (premier super-admin). Le lien est aussi envoyé
 * par e-mail (I-02) ; il est affiché ici car aucune autre personne ne peut encore inviter.
 */
#[AsCommand(name: 'app:admin:invite', description: 'Invite un administrateur du back-office (lien d’activation)')]
final class InviteAdminCommand extends Command
{
    public function __construct(private readonly CommandBus $commands, private readonly IdGenerator $ids, private readonly UrlGeneratorInterface $urls)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Adresse e-mail')
            ->addArgument('name', InputArgument::REQUIRED, 'Nom affiché')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Rôle : '.implode(', ', array_map(static fn (AdminRole $r): string => $r->value, AdminRole::cases())), AdminRole::SuperAdmin->value);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $token = InvitationToken::generate();
        [$email, $name, $role] = [$input->getArgument('email'), $input->getArgument('name'), $input->getOption('role')];
        if (!\is_string($email) || !\is_string($name) || !\is_string($role)) {
            $io->error('Arguments invalides.');

            return Command::INVALID;
        }
        try {
            $this->commands->dispatch(new InviteAdmin($this->ids->next(), $email, $name, $role, $token, null));
        } catch (TeamRejected|AdminRuleViolation|\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success('Invitation créée et envoyée par e-mail.');
        $io->writeln('Lien d’activation (personnel, à transmettre par un canal sûr) :');
        $io->writeln($this->urls->generate('admin_invitation', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL));

        return Command::SUCCESS;
    }
}
