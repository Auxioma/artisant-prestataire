<?php

declare(strict_types=1);

namespace App\Identity\UI\Console;

use App\Identity\Application\Legal\PublishLegalDocument;
use App\Identity\Domain\Legal\LegalDocumentType;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Identifier\IdGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Publie une nouvelle version d'un document légal (en attendant l'éditeur BO > CMS > Pages légales, T-8xx). */
#[AsCommand(name: 'app:legal:publish', description: 'Publie une nouvelle version d’un document légal (CGU, CGV, confidentialité)')]
final class PublishLegalDocumentCommand extends Command
{
    public function __construct(private readonly CommandBus $commands, private readonly IdGenerator $ids)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::REQUIRED, 'cgu | cgv | cgv_pro | privacy')
            ->addArgument('file', InputArgument::REQUIRED, 'Fichier HTML du document')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Titre affiché')
            ->addOption('substantial', null, InputOption::VALUE_NONE, 'Modification substantielle : ré-acceptation exigée (RG-002)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $typeArgument = $input->getArgument('type');
        $file = $input->getArgument('file');
        $type = \is_string($typeArgument) ? LegalDocumentType::tryFrom($typeArgument) : null;
        $file = \is_string($file) ? $file : '';
        $html = is_file($file) ? file_get_contents($file) : false;
        if (null === $type || false === $html) {
            $io->error('Type inconnu ou fichier illisible.');

            return Command::INVALID;
        }
        $title = $input->getOption('title');
        $this->commands->dispatch(new PublishLegalDocument($this->ids->next(), $type->value, \is_string($title) && '' !== $title ? $title : $type->label(), $html, (bool) $input->getOption('substantial')));
        $io->success(\sprintf('%s publiée.', $type->label()));

        return Command::SUCCESS;
    }
}
