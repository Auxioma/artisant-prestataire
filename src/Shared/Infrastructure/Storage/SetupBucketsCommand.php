<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use AsyncAws\S3\S3Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Crée les buckets privés s'ils manquent (développement, CI). En production, buckets, chiffrement serveur, blocage
 * de l'accès public et cycle de vie de la quarantaine sont provisionnés par l'infrastructure (T-906).
 */
#[AsCommand(name: 'app:storage:setup', description: 'Crée les buckets de stockage privés (quarantaine, documents)')]
final class SetupBucketsCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'app.s3_client')]
        private readonly S3Client $s3,
        #[Autowire('%env(S3_BUCKET_QUARANTINE)%')]
        private readonly string $quarantineBucket,
        #[Autowire('%env(S3_BUCKET_DOCUMENTS)%')]
        private readonly string $documentsBucket,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        foreach ([$this->quarantineBucket, $this->documentsBucket] as $bucket) {
            if ($this->s3->bucketExists(['Bucket' => $bucket])->isSuccess()) {
                $io->writeln(\sprintf('Bucket « %s » déjà présent.', $bucket));
                continue;
            }
            $this->s3->createBucket(['Bucket' => $bucket])->resolve();
            $io->writeln(\sprintf('Bucket « %s » créé.', $bucket));
        }
        $io->success('Stockage prêt.');

        return Command::SUCCESS;
    }
}
