<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Template;

/**
 * Couleurs des e-mails. Les messageries ignorent les variables CSS : les valeurs de assets/styles/tokens.css sont
 * recopiées ici, en un seul endroit (marine, encre, gris secondaire, fond, filet, lien, orange texte, orange bouton).
 * Bouton : aplat orange + texte marine (contraste 6,1:1, audit V2.1).
 */
final class EmailPalette
{
    public const array COLORS = [
        'navy' => '#001568',
        'ink' => '#192c49',
        'muted' => '#5b6b82',
        'bg' => '#f7f9fc',
        'line' => '#e0e7f0',
        'link' => '#0057b8',
        'accent' => '#b84a00',
        'button' => '#ff7900',
        'white' => '#ffffff',
    ];
}
