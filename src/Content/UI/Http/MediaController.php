<?php

declare(strict_types=1);

namespace App\Content\UI\Http;

use App\Content\Application\Media\MediaList;
use App\Content\Application\Media\MediaView;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Storage\FileReader;
use App\Shared\Application\Storage\StoredFileNotFound;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** BO10.7 — variantes publiques de la médiathèque (immuables : une nouvelle image a un nouvel identifiant). */
final class MediaController extends AbstractController
{
    private const int CACHE_SECONDS = 31_536_000;

    #[Route('/medias/{id}/{width}.{format}', name: 'content_media', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}', 'width' => '\d{2,4}', 'format' => 'avif|webp'], methods: ['GET'])]
    public function variant(string $id, int $width, string $format, QueryBus $queries, FileReader $files): StreamedResponse
    {
        $media = $queries->ask(new MediaList($id));
        $fileId = $media instanceof MediaView && $media->ready() ? $media->variant($format, $width) : null;
        if (null === $fileId) {
            throw new NotFoundHttpException();
        }
        try {
            $file = $files->available($fileId);
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
            'Cache-Control' => 'public, max-age='.self::CACHE_SECONDS.', immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
