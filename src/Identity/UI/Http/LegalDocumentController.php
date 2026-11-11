<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\Port\LegalDocuments;
use App\Identity\Domain\Legal\LegalDocumentType;
use App\Shared\Domain\Time\Clock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Pages légales versionnées (P17, RG-002) : version en vigueur, numéro et date de publication affichés. */
final class LegalDocumentController extends AbstractController
{
    public function __construct(private readonly LegalDocuments $documents, private readonly Clock $clock)
    {
    }

    #[Route('/{slug}', name: 'identity_legal_document', requirements: ['slug' => 'cgu|cgv|cgv-professionnels|politique-de-confidentialite'], methods: ['GET'], priority: 10)]
    public function show(string $slug): Response
    {
        $type = LegalDocumentType::fromSlug($slug) ?? throw new NotFoundHttpException();
        $document = $this->documents->current($type, $this->clock->now()) ?? throw new NotFoundHttpException();

        return $this->render('legal/document.html.twig', ['document' => $document]);
    }
}
