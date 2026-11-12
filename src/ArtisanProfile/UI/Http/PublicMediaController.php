<?php

declare(strict_types=1);

namespace App\ArtisanProfile\UI\Http;

use App\ArtisanProfile\Application\Search\SearchableArtisan;
use App\ArtisanProfile\Application\Search\SearchableArtisanOf;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Storage\FileReader;
use App\Shared\Application\Storage\StoredFileNotFound;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Logo public d'un artisan (cartes P02, fiche P10) : seulement pour un artisan public (vérifié, non masqué) et un
 * fichier sain, ré-encodé (métadonnées retirées, ADR-0008). Mis en cache côté navigateur.
 */
final class PublicMediaController extends AbstractController
{
    private const int CACHE_SECONDS = 3600;

    #[Route('/media/artisans/{id}/logo', name: 'public_artisan_logo', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'])]
    public function logo(string $id, QueryBus $queries, FileReader $files): StreamedResponse
    {
        $artisan = $queries->ask(new SearchableArtisanOf($id));
        if (!$artisan instanceof SearchableArtisan || null === $artisan->logoFileId) {
            throw new NotFoundHttpException();
        }
        try {
            $file = $files->available($artisan->logoFileId);
            $stream = $files->openPublic($file->id);
        } catch (StoredFileNotFound) {
            throw new NotFoundHttpException();
        }

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, Response::HTTP_OK, [
            'Content-Type' => $file->type->value,
            'Content-Length' => (string) $file->size,
            'Cache-Control' => 'public, max-age='.self::CACHE_SECONDS,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
