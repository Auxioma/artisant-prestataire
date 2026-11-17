<?php

declare(strict_types=1);

namespace App\Quote\Application\Pdf;

use App\ArtisanProfile\Application\Quote\QuoteIssuerView;
use App\Quote\Application\Port\QuotePdfRenderer;
use App\Quote\Application\Port\QuotePdfStore;
use App\Quote\Domain\Quote\QuoteContent;
use App\Quote\Domain\Quote\QuotePdf;
use App\Quote\Domain\Quote\QuoteRuleViolation;
use App\QuoteRequest\Application\Pro\ProRequestView;
use App\Shared\Application\Storage\FileReader;
use App\Shared\Application\Storage\StoredFileNotFound;
use App\Shared\Domain\Identifier\IdGenerator;

/**
 * PR04.4 — aperçu d'un brouillon (non stocké, filigrane) ; PDF figé d'une version envoyée (stockage privé, empreinte
 * SHA-256 conservée) ; relecture avec contrôle d'empreinte (ADR-0008).
 */
final readonly class QuotePdfService
{
    public function __construct(private QuotePdfRenderer $renderer, private QuotePdfStore $store, private FileReader $files, private IdGenerator $ids)
    {
    }

    public function document(QuoteIssuerView $issuer, ProRequestView $request, QuoteContent $content, ?string $number, int $version, \DateTimeImmutable $issuedAt, bool $preview): QuotePdfDocument
    {
        $client = trim(($request->clientFirstName ?? 'Client').' · '.$request->card->city);

        return new QuotePdfDocument(
            $issuer,
            $this->logo($issuer->logoFileId),
            $client,
            $request->card->number,
            $content,
            $number,
            $version,
            $issuedAt,
            $issuedAt->modify(\sprintf('+%d days', $content->conditions->validityDays)),
            $preview,
        );
    }

    public function preview(QuotePdfDocument $document): string
    {
        return $this->renderer->render($document);
    }

    /** Rend, stocke et renvoie la référence du PDF figé de la version (à poser à l'envoi ou à la révision). */
    public function freeze(QuotePdfDocument $document): QuotePdf
    {
        $bytes = $this->renderer->render($document);
        $sha = hash('sha256', $bytes);
        $key = $this->ids->next(); // clé opaque (UUID) : le stockage n'accepte aucun nom construit
        $this->store->put($key, $bytes);

        return new QuotePdf($key, $sha);
    }

    /** PDF d'une version envoyée, refusé si son empreinte ne correspond plus (altération du stockage). */
    public function read(QuotePdf $pdf): string
    {
        $bytes = $this->store->get($pdf->key);
        if (!hash_equals($pdf->sha256, hash('sha256', $bytes))) {
            throw new QuoteRuleViolation('Le PDF de ce devis est altéré : téléchargement bloqué.');
        }

        return $bytes;
    }

    private function logo(?string $fileId): ?string
    {
        if (null === $fileId) {
            return null;
        }
        try {
            $file = $this->files->available($fileId);
            $stream = $this->files->openPublic($fileId);
        } catch (StoredFileNotFound) {
            return null;
        }
        $bytes = stream_get_contents($stream);
        fclose($stream);

        return false === $bytes ? null : 'data:'.$file->type->value.';base64,'.base64_encode($bytes);
    }
}
