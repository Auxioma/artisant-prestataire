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
    // Hors planche d'icônes, utilisées par d'autres composants Figma (« icon/cookie » du bandeau CMP, 158:1512)
    'cookie' => 'cookie',
    // T-202 (A01, A02)
    'checkC' => 'circle-check', 'crown' => 'crown', 'userPlus' => 'user-plus', 'users' => 'users',
    'phone' => 'phone', 'lock' => 'lock', 'eyeOff' => 'eye-off', 'calCheck' => 'calendar-check',
    'rocket' => 'rocket', 'handshake' => 'handshake', 'building' => 'building-2',
    // T-204 (A04)
    'logIn' => 'log-in',
    // T-405b (espace pro : menu, PR02)
    'receipt' => 'receipt', 'layers' => 'layers', 'umbrella' => 'umbrella', 'chart' => 'chart-column',
    'settings' => 'settings', 'help' => 'circle-help', 'power' => 'power', 'euro' => 'euro',
    'dots' => 'circle-ellipsis', 'hourglass' => 'hourglass', 'refresh' => 'refresh-cw', 'bars' => 'chart-no-axes-column',
    'bulb' => 'lightbulb',
];
/** Variantes pleines (fill = currentColor), utilisées par la carte artisan (« icon/star-filled » dans Figma). */
const FILLED_ICONS = ['starFilled' => 'star', 'heartFilled' => 'heart'];

$symbols = [];
foreach (ICONS + FILLED_ICONS as $id => $lucide) {
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
    $fill = array_key_exists($id, FILLED_ICONS) ? 'currentColor' : 'none';
    $symbols[] = sprintf('  <symbol id="%s" viewBox="0 0 24 24" fill="%s" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">%s</symbol>', $id, $fill, $inner);
}

// Icônes propres à la maquette, sans équivalent Lucide (tools/icons/custom/*.svg, viewBox et trait d'origine, currentColor).
foreach (glob(__DIR__.'/custom/*.svg') ?: [] as $file) {
    $doc = new DOMDocument();
    $doc->loadXML((string) file_get_contents($file), \LIBXML_NONET);
    $inner = '';
    foreach ($doc->documentElement?->childNodes ?? [] as $child) {
        if (\XML_ELEMENT_NODE === $child->nodeType) {
            $inner .= $doc->saveXML($child);
        }
    }
    $symbols[] = sprintf('  <symbol id="%s" viewBox="%s" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">%s</symbol>', basename($file, '.svg'), $doc->documentElement?->getAttribute('viewBox') ?? '0 0 24 24', $inner);
}

// Icônes de marque locales (tools/icons/brands/*.svg) : tracés déjà nettoyés, couleur currentColor.
foreach (glob(__DIR__.'/brands/*.svg') ?: [] as $file) {
    $doc = new DOMDocument();
    $doc->loadXML((string) file_get_contents($file), \LIBXML_NONET);
    $inner = '';
    foreach ($doc->documentElement?->childNodes ?? [] as $child) {
        if (\XML_ELEMENT_NODE === $child->nodeType) {
            $inner .= $doc->saveXML($child);
        }
    }
    $symbols[] = sprintf('  <symbol id="brand-%s" viewBox="0 0 24 24" fill="none">%s</symbol>', basename($file, '.svg'), $inner);
}

$sprite = sprintf(
    "<svg xmlns=\"http://www.w3.org/2000/svg\" aria-hidden=\"true\">\n  <!-- Lucide %s (ISC, voir assets/icons/LICENSE-lucide.txt) — généré par tools/icons/build-sprite.php -->\n%s\n</svg>\n",
    LUCIDE_VERSION,
    implode("\n", $symbols),
);
file_put_contents(__DIR__.'/../../assets/icons/sprite.svg', $sprite);
copy(sprintf('https://cdn.jsdelivr.net/npm/lucide-static@%s/LICENSE', LUCIDE_VERSION), __DIR__.'/../../assets/icons/LICENSE-lucide.txt');
printf("%d icônes écrites dans assets/icons/sprite.svg\n", count($symbols));
