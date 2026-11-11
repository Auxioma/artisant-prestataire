<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Storage\FileShredder;
use App\Shared\Application\Storage\FileUploader;
use App\Shared\Application\Storage\RejectedUpload;
use App\Shared\Domain\Storage\FilePurpose;
use App\Shared\Domain\Time\Clock;

final readonly class UploadLogoHandler implements CommandHandler
{
    public function __construct(
        private ArtisanProfileRepository $profiles,
        private FileUploader $uploader,
        private FileShredder $shredder,
        private Clock $clock,
    ) {
    }

    public function __invoke(UploadLogo $command): void
    {
        $profile = $this->profiles->findByUserId($command->userId) ?? throw new ArtisanRuleViolation('Déclarez d’abord votre entreprise.');
        $previous = $profile->logoFileId();
        try {
            $file = $this->uploader->upload($command->path, $command->clientName, FilePurpose::ArtisanLogo);
        } catch (RejectedUpload $e) {
            throw new SignupRejected('logo', SignupRejected::FILE_REJECTED, $e->reason);
        }
        $profile->changeLogo($file->id, $this->clock->now());
        if (null !== $previous) {
            $this->shredder->shred($previous, 'artisan_logo_replaced');
        }
    }
}
