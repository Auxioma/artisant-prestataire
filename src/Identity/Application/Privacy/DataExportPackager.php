<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

/** Construit l'archive ZIP (donnees.json + donnees.pdf) dans un fichier temporaire ; l'appelant le supprime. */
interface DataExportPackager
{
    /** @param list<array{key: string, title: string, rows: list<array<string, mixed>>}> $sections */
    public function package(string $holderName, \DateTimeImmutable $generatedAt, array $sections): string;
}
