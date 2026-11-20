<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

use App\Shared\Application\Bus\EventHandler;
use App\Shared\Application\Storage\FileReader;
use App\Shared\Application\Storage\GeneratedFileStore;
use App\Shared\Domain\Storage\FileAvailable;
use App\Shared\Domain\Storage\FilePurpose;
use App\Shared\Domain\Storage\FileType;
use App\Shared\Domain\Time\Clock;

/** Après l'analyse antivirus : variantes WebP et AVIF aux largeurs usuelles (BO10.7, CDC §8 images). */
final readonly class GenerateMediaVariants implements EventHandler
{
    public const array WIDTHS = [480, 960, 1600];
    public const array FORMATS = ['avif', 'webp'];

    public function __construct(private MediaLibrary $library, private FileReader $files, private ImageVariants $images, private GeneratedFileStore $store, private Clock $clock)
    {
    }

    public function __invoke(FileAvailable $event): void
    {
        if (FilePurpose::Media->value !== $event->purpose) {
            return;
        }
        $media = $this->library->findByFile($event->fileId);
        if (null === $media || 'processing' !== $media->status) {
            return;
        }
        $source = $this->files->openPublic($event->fileId);
        try {
            $result = $this->images->generate($source, self::WIDTHS, self::FORMATS);
        } catch (\RuntimeException) {
            $this->library->markFailed($media->id, $this->clock->now());

            return;
        } finally {
            \is_resource($source) && fclose($source);
        }
        $variants = [];
        foreach ($result['files'] as $file) {
            try {
                $stored = $this->store->store($file['path'], FilePurpose::Media, 'avif' === $file['format'] ? FileType::Avif : FileType::Webp, $media->title.'-'.$file['width'].'.'.$file['format']);
                $variants[$file['format']][$file['width']] = $stored->id;
            } finally {
                is_file($file['path']) && unlink($file['path']);
            }
        }
        $this->library->markReady($media->id, $result['width'], $result['height'], $variants, $this->clock->now());
    }
}
