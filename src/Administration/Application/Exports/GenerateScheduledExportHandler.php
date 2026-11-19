<?php

declare(strict_types=1);

namespace App\Administration\Application\Exports;

use App\Shared\Application\Admin\ScheduledExportSource;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Storage\FileShredder;
use App\Shared\Application\Storage\GeneratedFileStore;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Storage\FilePurpose;
use App\Shared\Domain\Storage\FileType;
use App\Shared\Domain\Time\Clock;

/**
 * BO15.2 — écrit le CSV de la source dans un fichier temporaire, le confie au stockage chiffré, l'enregistre pour
 * RETENTION_DAYS jours, puis détruit les fichiers des exports arrivés à expiration.
 */
final readonly class GenerateScheduledExportHandler implements CommandHandler
{
    /** Durée de conservation d'un export planifié : trois mois, le temps des clôtures comptables (P-145). */
    public const int RETENTION_DAYS = 90;
    private const string SEPARATOR = ';';

    /** @param iterable<ScheduledExportSource> $sources */
    public function __construct(
        private iterable $sources,
        private GeneratedFileStore $files,
        private FileShredder $shredder,
        private ScheduledExportStore $exports,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(GenerateScheduledExport $command): void
    {
        $now = $this->clock->now();
        foreach ($this->exports->expired($now) as $old) {
            $this->shredder->shred($old->fileId, 'scheduled_export_expired');
            $this->exports->delete($old->id);
        }
        $source = $this->source($command->kind);
        $path = tempnam(sys_get_temp_dir(), 'tm-export-');
        $out = false === $path ? false : fopen($path, 'w');
        if (false === $path || false === $out) {
            throw new \RuntimeException('Fichier temporaire indisponible.');
        }
        try {
            fwrite($out, "\u{FEFF}");
            fputcsv($out, $source->header(), self::SEPARATOR, '"', '');
            $rows = 0;
            foreach ($source->rows($now) as $row) {
                fputcsv($out, $row, self::SEPARATOR, '"', '');
                ++$rows;
            }
            fclose($out);
            $period = $source->periodLabel($now);
            $file = $this->files->store($path, FilePurpose::ScheduledExport, FileType::Csv, $source->key().'-'.$now->format('Y-m-d').'.csv');
            $this->exports->add(new ScheduledExport($this->ids->next(), $source->key(), $period, $file->id, $rows, $now, $now->modify(\sprintf('+%d days', self::RETENTION_DAYS))));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function source(string $kind): ScheduledExportSource
    {
        foreach ($this->sources as $source) {
            if ($source->key() === $kind) {
                return $source;
            }
        }

        throw new \InvalidArgumentException(\sprintf('Export planifié inconnu : %s.', $kind));
    }
}
