<?php

declare(strict_types=1);

namespace App\Review\UI\Http;

use App\Review\Application\Query\ReviewPhoto;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Application\Storage\FileReader;
use App\Shared\Application\Storage\StoredFileNotFound;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Photo d'un avis (P10.9, C06, PR09) : avis publié, ou son auteur ; fichier analysé seulement. */
final class ReviewPhotoController extends AbstractController
{
    public function __construct(private readonly QueryBus $queries, private readonly CurrentUser $user, private readonly FileReader $files)
    {
    }

    #[Route('/avis/{review}/photos/{file}', name: 'review_photo', requirements: ['review' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}', 'file' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'])]
    public function __invoke(string $review, string $file): Response
    {
        if (true !== $this->queries->ask(new ReviewPhoto($review, $file, $this->user->id()))) {
            throw new NotFoundHttpException();
        }
        try {
            $stored = $this->files->available($file);
            $stream = $this->files->openPublic($file);
        } catch (StoredFileNotFound) {
            throw new NotFoundHttpException();
        }

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, Response::HTTP_OK, [
            'Content-Type' => $stored->type->value,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
