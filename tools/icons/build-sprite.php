<?php

declare(strict_types=1);

/*
 * Génère assets/icons/sprite.svg à partir de Lucide (ISC), version figée.
 * Identifiants = noms des composants « TM / Icônes » de la bibliothèque Figma V2 (nœud 77:245),
 * associés aux icônes Lucide correspondantes (vérifié visuellement sur la planche Figma).
 *
 * Usage : php tools/icons/build-sprite.php   (le résultat est versionné ; aucune dépendance Node)
 */

const LUCIDE_VERSION = '0.460.0';
const ICONS = [
    'search' => 'search', 'pin' => 'map-pin', 'locate' => 'locate-fixed', 'check' => 'check',
    'shield' => 'shield-check', 'star' => 'star', 'heart' => 'heart', 'clock' => 'clock',
    'calendar' => 'calendar', 'arrowR' => 'arrow-right', 'chevL' => 'chevron-left', 'chevR' => 'chevron-right',
    'chevD' => 'chevron-down', 'menu' => 'menu', 'bell' => 'bell', 'msg' => 'message-square',
    'user' => 'user', 'x' => 'x', 'loader' => 'loader-circle', 'upload' => 'upload',
    'wrench' => 'wrench', 'zap' => 'zap', 'paint' => 'paintbrush', 'home' => 'house',
    'flame' => 'flame', 'leaf' => 'leaf', 'tag' => 'tag', 'plus' => 'plus',
    'file' => 'file-text', 'briefcase' => 'briefcase', 'trophy' => 'trophy', 'alert' => 'circle-alert',
    'info' => 'info', 'eye' => 'eye', 'camera' => 'camera', 'sliders' => 'sliders-horizontal',
    'grid' => 'layout-grid', 'mail' => 'mail', 'share' => 'share-2',
];

$symbols = [];
foreach (ICONS as $id => $lucide) {
    $url = sprintf('https://cdn.jsdelivr.net/npm/lucide-static@%s/icons/%s.svg', LUCIDE_VERSION, $lucide);
    $svg = file_get_contents($url);
    if (false === $svg) {
        fwrite(\STDERR, "Échec du téléchargement : $url\n");
        exit(1);
    }
    $doc = new DOMDocument();
    $doc->loadXML($svg, \LIBXML_NONET);
    $root = $doc->documentElement;
    if (null === $root || '0 0 24 24' !== $root->getAttribute('viewBox')) {
        fwrite(\STDERR, "SVG inattendu pour $lucide\n");
        exit(1);
    }
    $inner = '';
    foreach ($root->childNodes as $child) {
        if (\XML_ELEMENT_NODE === $child->nodeType) {
            $inner .= $doc->saveXML($child);
        }
    }
    // Attributs portés par chaque symbole : un <use> n'hérite pas des attributs de la racine du sprite.
    $symbols[] = sprintf('  <symbol id="%s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">%s</symbol>', $id, $inner);
}

$sprite = sprintf(
    "<svg xmlns=\"http://www.w3.org/2000/svg\" aria-hidden=\"true\">\n  <!-- Lucide %s (ISC, voir assets/icons/LICENSE-lucide.txt) — généré par tools/icons/build-sprite.php -->\n%s\n</svg>\n",
    LUCIDE_VERSION,
    implode("\n", $symbols),
);
file_put_contents(__DIR__.'/../../assets/icons/sprite.svg', $sprite);
copy(sprintf('https://cdn.jsdelivr.net/npm/lucide-static@%s/LICENSE', LUCIDE_VERSION), __DIR__.'/../../assets/icons/LICENSE-lucide.txt');
printf("%d icônes écrites dans assets/icons/sprite.svg\n", count($symbols));
