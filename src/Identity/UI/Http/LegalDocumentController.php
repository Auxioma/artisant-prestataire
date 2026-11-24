<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\Legal\LegalDocumentVersion;
use App\Identity\Application\Port\LegalDocuments;
use App\Identity\Domain\Legal\LegalDocumentType;
use App\Shared\Domain\Time\Clock;
use App\Shared\UI\Html\HeadingToc;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P17 — pages légales versionnées (RG-002) : /cgu, /cgv, /cgv-professionnels, /confidentialite, /mentions-legales,
 * /cookies. Sommaire ancré, version et date, « Versions précédentes » ; /cookies affiche aussi le registre des traceurs
 * et le bouton « Gérer mes cookies ».
 */
final class LegalDocumentController extends AbstractController
{
    private const string SLUGS = 'cgu|cgv|cgv-professionnels|confidentialite|mentions-legales|cookies';

    public function __construct(private readonly LegalDocuments $documents, private readonly Clock $clock)
    {
    }

    #[Route('/{slug}', name: 'identity_legal_document', requirements: ['slug' => self::SLUGS], methods: ['GET'], priority: 10)]
    public function show(string $slug): Response
    {
        $type = self::type($slug);
        $document = $this->documents->current($type, $this->clock->now()) ?? throw new NotFoundHttpException();

        return $this->page($document, $this->documents->versions($type, $this->clock->now()), false);
    }

    #[Route('/politique-de-confidentialite', name: 'identity_legal_document_legacy', methods: ['GET'], priority: 10)]
    public function legacy(): RedirectResponse
    {
        return $this->redirectToRoute('identity_legal_document', ['slug' => LegalDocumentType::Privacy->slug()], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/{slug}/versions', name: 'identity_legal_versions', requirements: ['slug' => self::SLUGS], methods: ['GET'], priority: 10)]
    public function versions(string $slug): Response
    {
        $type = self::type($slug);
        $versions = $this->documents->versions($type, $this->clock->now());
        if ([] === $versions) {
            throw new NotFoundHttpException();
        }

        return $this->render('legal/versions.html.twig', ['type' => $type, 'versions' => $versions]);
    }

    /** Version archivée : consultable (preuve de ce qui a été accepté), jamais indexée. */
    #[Route('/{slug}/versions/{version}', name: 'identity_legal_version', requirements: ['slug' => self::SLUGS, 'version' => '[1-9][0-9]{0,3}'], methods: ['GET'], priority: 10)]
    public function version(string $slug, int $version): Response
    {
        $type = self::type($slug);
        $versions = $this->documents->versions($type, $this->clock->now());
        $document = array_values(array_filter($versions, static fn (LegalDocumentVersion $v): bool => $v->version === $version))[0] ?? throw new NotFoundHttpException();
        $response = $this->page($document, $versions, $document->id !== $versions[0]->id);
        $response->headers->set('X-Robots-Tag', 'noindex, follow');

        return $response;
    }

    /** @param list<LegalDocumentVersion> $versions */
    private function page(LegalDocumentVersion $document, array $versions, bool $archived): Response
    {
        [$html, $toc] = HeadingToc::build($document->html);

        return $this->render('legal/document.html.twig', [
            'document' => $document,
            'html' => $html,
            'toc' => $toc,
            'versions_count' => \count($versions),
            'archived' => $archived,
            'current_version' => $versions[0]->version ?? $document->version,
        ]);
    }

    private static function type(string $slug): LegalDocumentType
    {
        return LegalDocumentType::fromSlug($slug) ?? throw new NotFoundHttpException();
    }
}
