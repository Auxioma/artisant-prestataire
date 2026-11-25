<?php

declare(strict_types=1);

namespace App\Shared\UI\Html;

use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\ValueObject\Slug;

/**
 * Sommaire ancré d'un contenu HTML déjà assaini (documents légaux P17.1, articles de blog P15.2) : un identifiant unique
 * est posé sur chaque titre H2 ; le texte des titres est échappé dans le sommaire.
 */
final class HeadingToc
{
    private const string FALLBACK = 'section';

    /** @return array{0: string, 1: list<array{id: string, label: string}>} */
    public static function build(string $html): array
    {
        $toc = [];
        $used = [];
        $result = preg_replace_callback('#<h2(\s[^>]*)?>(.*?)</h2>#is', static function (array $m) use (&$toc, &$used): string {
            $label = trim(html_entity_decode(strip_tags($m[2]), \ENT_QUOTES | \ENT_HTML5));
            try {
                $base = Slug::fromText($label)->value;
            } catch (InvalidValue) {
                $base = self::FALLBACK;
            }
            $id = $base;
            for ($n = 2; isset($used[$id]); ++$n) {
                $id = $base.'-'.$n;
            }
            $used[$id] = true;
            $toc[] = ['id' => $id, 'label' => $label];
            $attributes = preg_replace('#\sid="[^"]*"#i', '', $m[1]) ?? '';

            return '<h2 id="'.$id.'"'.$attributes.'>'.$m[2].'</h2>';
        }, $html);

        return [$result ?? $html, $toc];
    }
}
