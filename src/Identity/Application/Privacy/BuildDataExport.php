<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

use App\Identity\Application\Port\IdentityMailer;
use App\Identity\Domain\Privacy\DataExportRequested;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Application\Privacy\PersonalDataSource;
use App\Shared\Application\Storage\GeneratedFileStore;
use App\Shared\Domain\Storage\FilePurpose;
use App\Shared\Domain\Storage\FileType;
use App\Shared\Domain\Time\Clock;

/** Worker : rassemble les sections de chaque module, construit l'archive, la stocke chiffrée et prévient (E-07). */
final readonly class BuildDataExport implements EventHandler
{
    public const int LINK_DAYS = 7;

    /** @param iterable<PersonalDataSource> $sources */
    public function __construct(
        private iterable $sources,
        private DataExports $exports,
        private DataExportPackager $packager,
        private GeneratedFileStore $store,
        private UserRepository $users,
        private IdentityMailer $mailer,
        private Clock $clock,
    ) {
    }

    public function __invoke(DataExportRequested $event): void
    {
        $export = $this->exports->find($event->exportId);
        $user = $this->users->find(new UserId($event->userId));
        if (null === $export || 'requested' !== $export->status || null === $user) {
            return;
        }
        $now = $this->clock->now();
        $sections = [];
        foreach ($this->sources as $source) {
            $rows = $source->collect($event->userId);
            if ([] !== $rows) {
                $sections[] = ['key' => $source->key(), 'title' => $source->title(), 'rows' => $rows];
            }
        }
        $path = $this->packager->package($user->firstName().' '.$user->lastName(), $now, $sections);
        try {
            $file = $this->store->store($path, FilePurpose::DataExport, FileType::Zip, 'mes-donnees-trouvemoi.zip');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $expiresAt = $now->modify(\sprintf('+%d days', self::LINK_DAYS));
        $this->exports->markReady($export->id, $file->id, $now, $expiresAt);
        $this->mailer->sendDataExportReady($user->email(), $user->firstName(), $event->userId, self::LINK_DAYS, $expiresAt);
    }
}
