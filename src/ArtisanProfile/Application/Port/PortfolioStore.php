<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Port;

use App\ArtisanProfile\Application\Portfolio\PortfolioProject;

interface PortfolioStore
{
    /** @return list<PortfolioProject> projets de l'artisan, dans l'ordre ; `$publicOnly` : photos analysées seulement, projets sans photo exclus */
    public function projects(string $profileId, bool $publicOnly): array;

    public function project(string $profileId, string $projectId): ?PortfolioProject;

    public function countProjects(string $profileId): int;

    public function saveProject(string $id, string $profileId, string $title, ?string $description, ?string $city, ?\DateTimeImmutable $doneOn, ?string $jobId, \DateTimeImmutable $at): void;

    /** @return list<string> fichiers des photos du projet supprimé (à détruire) */
    public function deleteProject(string $profileId, string $projectId): array;

    public function addPhoto(string $id, string $projectId, string $fileId, string $kind, \DateTimeImmutable $at): void;

    /** @return string|null fichier de la photo supprimée */
    public function deletePhoto(string $profileId, string $photoId): ?string;

    /** Photos analysées et publiables (jauge PR08.1). */
    public function availablePhotos(string $profileId): int;

    /** Photo du portfolio portée par ce fichier (relecture BO05). */
    public function photoOfFile(string $fileId): ?string;

    /** @return array{profile_id: string, user_id: string}|null artisan propriétaire d'une photo (fichier) du portfolio */
    public function ownerOfFile(string $fileId): ?array;

    /** @return array{file_id: string, profile_id: string}|null photo publiable (média public P10) */
    public function publicPhoto(string $photoId): ?array;
}
