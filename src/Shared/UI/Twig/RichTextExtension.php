<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Mise en forme limitée des textes saisis par les utilisateurs (PR08.3 : gras, listes) : le texte est d'abord échappé,
 * puis « **gras** » et les lignes commençant par « - » sont convertis ; aucune balise saisie n'est interprétée.
 */
final class RichTextExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('tm_rich_text', self::render(...), ['is_safe' => ['html']])];
    }

    public static function render(?string $text): string
    {
        if (null === $text || '' === trim($text)) {
            return '';
        }
        $html = [];
        foreach (preg_split('/\n\s*\n/', trim(str_replace(["\r\n", "\r"], "\n", $text))) ?: [] as $block) {
            $lines = explode("\n", $block);
            $items = array_filter($lines, static fn (string $l): bool => 1 === preg_match('/^\s*[-•*]\s+/', $l));
            if (\count($items) === \count($lines)) {
                $html[] = '<ul>'.implode('', array_map(static fn (string $l): string => '<li>'.self::inline((string) preg_replace('/^\s*[-•*]\s+/', '', $l)).'</li>', $lines)).'</ul>';
            } else {
                $html[] = '<p>'.implode('<br>', array_map(self::inline(...), $lines)).'</p>';
            }
        }

        return implode("\n", $html);
    }

    private static function inline(string $line): string
    {
        $escaped = htmlspecialchars(trim($line), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $escaped);
    }
}
