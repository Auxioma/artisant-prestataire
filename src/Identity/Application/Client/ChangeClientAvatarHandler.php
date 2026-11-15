<?php

declare(strict_types=1);

namespace App\Identity\Application\Client;

use App\Identity\Domain\Client\ClientProfileRepository;
use App\Identity\Domain\User\UserId;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Storage\FileShredder;
use App\Shared\Application\Storage\FileUploader;
use App\Shared\Application\Storage\RejectedUpload;
use App\Shared\Domain\Storage\FilePurpose;
use App\Shared\Domain\Time\Clock;

final readonly class ChangeClientAvatarHandler implements CommandHandler
{
    public function __construct(private ClientProfileRepository $profiles, private FileUploader $uploader, private FileShredder $shredder, private Clock $clock)
    {
    }

    public function __invoke(ChangeClientAvatar $command): void
    {
        $profile = $this->profiles->find(new UserId($command->userId)) ?? throw new ClientProfileRejected('avatar', 'Profil client introuvable.');
        $fileId = null;
        if (null !== $command->path) {
            try {
                $fileId = $this->uploader->upload($command->path, $command->clientName, FilePurpose::ClientAvatar)->id;
            } catch (RejectedUpload $e) {
                throw new ClientProfileRejected('avatar', match ($e->reason) {
                    RejectedUpload::TOO_LARGE, RejectedUpload::IMAGE_TOO_LARGE => 'Photo trop lourde : choisissez une image plus légère.', RejectedUpload::TYPE_NOT_ALLOWED => 'Format non accepté : JPG, PNG ou WebP.', default => 'Photo illisible : choisissez une autre image.',
                });
            }
        }
        $previous = $profile->changeAvatar($fileId, $this->clock->now());
        if (null !== $previous) {
            $this->shredder->shred($previous, 'client_avatar_replaced');
        }
    }
}
