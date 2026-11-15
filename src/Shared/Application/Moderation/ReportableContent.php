<?php

declare(strict_types=1);

namespace App\Shared\Application\Moderation;

/**
 * BO05.2 — contenu que l'on peut signaler (avis, profil, demande) : chaque module décrit les siens. Implémentations
 * marquées `app.reportable_content` (services.yaml) et collectées par le module Moderation.
 */
interface ReportableContent
{
    public function type(): string;

    /** Libellé du type au singulier (« Avis »…). */
    public function label(): string;

    /** Contenu tel que `$viewerUserId` le voit ; null s'il est introuvable ou hors de sa portée (non publié…). */
    public function describe(string $id, string $viewerUserId): ?ReportedContent;

    /**
     * Page d'administration de l'élément (BO), ou null.
     *
     * @return array{route: string, parameters: array<string, string>}|null
     */
    public function adminRoute(string $id): ?array;
}
