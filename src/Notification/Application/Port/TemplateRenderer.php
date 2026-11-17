<?php

declare(strict_types=1);

namespace App\Notification\Application\Port;

use App\Notification\Application\RenderedEmail;
use App\Notification\Application\Template;

/** Rendu en bac à sable (modèles saisis en back-office : aucune balise, fonction ou méthode hors liste blanche). */
interface TemplateRenderer
{
    /**
     * @param array<string, scalar|\DateTimeInterface|null> $variables
     *
     * @throws \RuntimeException modèle invalide ou opération interdite
     */
    public function renderEmail(Template $template, array $variables): RenderedEmail;

    /** @param array<string, scalar|\DateTimeInterface|null> $variables */
    public function renderInApp(Template $template, array $variables): string;
}
