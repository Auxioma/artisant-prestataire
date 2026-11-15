<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Storage\FileShredder;
use App\Shared\Application\Storage\FileUploader;
use App\Shared\Application\Storage\RejectedUpload;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Storage\FilePurpose;
use App\Shared\Domain\Time\Clock;

/** BO10.7 — dépôt, modification (titre, texte alternatif, point focal) et suppression d'une image ; journalisé. */
final readonly class MediaHandler implements CommandHandler
{
    private const int TITLE_MAX = 120;
    private const int ALT_MAX = 250;

    public function __construct(
        private MediaLibrary $library,
        private FileUploader $uploader,
        private FileShredder $shredder,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(UploadMedia|UpdateMedia|DeleteMedia $command): void
    {
        $now = $this->clock->now();
        if ($command instanceof DeleteMedia) {
            $media = $this->library->find($command->mediaId) ?? throw new MediaRejected('media', 'Image introuvable.');
            $this->library->delete($media->id);
            $files = [$media->fileId];
            foreach ($media->variants as $byWidth) {
                $files = [...$files, ...array_values($byWidth)];
            }
            foreach ($files as $file) {
                $this->shredder->shred($file, 'media_deleted', $command->adminId);
            }
            $this->record($command->adminId, 'content.media_deleted', $media->id, $now);

            return;
        }
        [$title, $alt, $focal] = $this->validate($command->title, $command->alt, $command->focal, $command instanceof UploadMedia ? $command->clientName : null);
        if ($command instanceof UpdateMedia) {
            $this->library->find($command->mediaId) ?? throw new MediaRejected('media', 'Image introuvable.');
            $this->library->update($command->mediaId, $title, $alt, $focal, $now);
            $this->record($command->adminId, 'content.media_updated', $command->mediaId, $now);

            return;
        }
        try {
            $file = $this->uploader->upload($command->path, $command->clientName, FilePurpose::Media);
        } catch (RejectedUpload $e) {
            throw new MediaRejected('file', match ($e->reason) {
                RejectedUpload::TOO_LARGE, RejectedUpload::IMAGE_TOO_LARGE => 'Image trop lourde ou trop grande.', RejectedUpload::TYPE_NOT_ALLOWED => 'Format non accepté : JPG, PNG ou WebP.', default => 'Image illisible.',
            });
        }
        $this->library->add($command->mediaId, $file->id, $title, $alt, $focal, $command->adminId, $now);
        $this->record($command->adminId, 'content.media_uploaded', $command->mediaId, $now);
    }

    /** @return array{string, string, string} */
    private function validate(string $title, string $alt, string $focal, ?string $clientName): array
    {
        $alt = trim((string) preg_replace('/\s+/u', ' ', $alt));
        if ('' === $alt) {
            throw new MediaRejected('alt', 'Le texte alternatif est obligatoire : décrivez ce que montre l’image.');
        }
        if (mb_strlen($alt) > self::ALT_MAX) {
            throw new MediaRejected('alt', \sprintf('Texte alternatif : %d caractères au maximum.', self::ALT_MAX));
        }
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ('' === $title && null !== $clientName) {
            $title = pathinfo($clientName, \PATHINFO_FILENAME);
        }
        $title = mb_substr('' === $title ? $alt : $title, 0, self::TITLE_MAX);
        if (!\array_key_exists($focal, MediaView::FOCALS)) {
            throw new MediaRejected('focal', 'Point focal inconnu.');
        }

        return [$title, $alt, $focal];
    }

    private function record(?string $adminId, string $action, string $mediaId, \DateTimeImmutable $now): void
    {
        $this->audit->record(new AuditRecord(id: $this->ids->next(), occurredAt: $now, actorType: 'admin', actorId: $adminId, action: $action, subjectType: 'media', subjectId: $mediaId));
    }
}
