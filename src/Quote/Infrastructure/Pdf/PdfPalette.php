<?php

declare(strict_types=1);

namespace App\Quote\Infrastructure\Pdf;

/**
 * Couleurs du PDF de devis (PR04.4 « couleurs neutres ») : un PDF n'a pas accès aux jetons CSS du site, les teintes
 * sont fixées ici (même principe qu'`EmailPalette` pour les e-mails).
 */
final class PdfPalette
{
    public const array COLORS = [
        'text' => '#222222',
        'heading' => '#111111',
        'label' => '#444444',
        'muted' => '#666666',
        'soft' => '#555555',
        'rule' => '#bbbbbb',
        'strong_rule' => '#999999',
        'thin_rule' => '#dddddd',
        'header_bg' => '#eeeeee',
        'section_bg' => '#f5f5f5',
        'total_rule' => '#333333',
        'watermark' => '#e6e6e6',
        'footer' => '#777777',
    ];
}
