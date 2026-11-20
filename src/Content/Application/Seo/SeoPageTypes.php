<?php

declare(strict_types=1);

namespace App\Content\Application\Seo;

/**
 * BO12.1 — types de pages dont le title et la description sont réglables par gabarit, et leurs variables. Un gabarit
 * vide laisse le texte par défaut de la page ; une variable sans valeur est retirée.
 */
final class SeoPageTypes
{
    public const array TYPES = [
        'category' => ['label' => 'Catégorie (P04)', 'variables' => ['{categorie}', '{nb_pros}']],
        'job' => ['label' => 'Métier (P05)', 'variables' => ['{metier}', '{categorie}', '{nb_pros}']],
        'job_city' => ['label' => 'Métier × ville (P05)', 'variables' => ['{metier}', '{ville}', '{a_ville}', '{cp}', '{departement}', '{nb_pros}']],
        'city' => ['label' => 'Ville (P05)', 'variables' => ['{ville}', '{a_ville}', '{cp}', '{departement}', '{nb_pros}']],
        'department' => ['label' => 'Département (P05)', 'variables' => ['{departement}', '{code}', '{nb_pros}']],
        'artisan' => ['label' => 'Fiche artisan (P10)', 'variables' => ['{nom}', '{metier}', '{ville}', '{a_ville}']],
    ];
    /** BO12.1 — valeurs d'exemple (fictives) de l'aperçu du résultat de recherche, et l'adresse affichée. */
    public const array SAMPLES = [
        'category' => ['path' => '/categories/plomberie', 'values' => ['categorie' => 'Plomberie', 'nb_pros' => 48]],
        'job' => ['path' => '/metiers/plombier', 'values' => ['metier' => 'Plombier', 'categorie' => 'Plomberie', 'nb_pros' => 48]],
        'job_city' => ['path' => '/plombier/le-havre-76600', 'values' => ['metier' => 'Plombier', 'ville' => 'Le Havre', 'a_ville' => 'au Havre', 'cp' => '76600', 'departement' => 'Seine-Maritime', 'nb_pros' => 24]],
        'city' => ['path' => '/villes/le-havre-76600', 'values' => ['ville' => 'Le Havre', 'a_ville' => 'au Havre', 'cp' => '76600', 'departement' => 'Seine-Maritime', 'nb_pros' => 312]],
        'department' => ['path' => '/departements/76-seine-maritime', 'values' => ['departement' => 'Seine-Maritime', 'code' => '76', 'nb_pros' => 1240]],
        'artisan' => ['path' => '/pro/atelier-exemple', 'values' => ['nom' => 'Atelier Exemple (fictif)', 'metier' => 'Plombier', 'ville' => 'Le Havre', 'a_ville' => 'au Havre']],
    ];
    /** Longueurs conseillées affichées par les compteurs (au-delà, Google tronque). */
    public const int TITLE_ADVISED = 60;
    public const int DESCRIPTION_ADVISED = 155;
    public const int TITLE_MAX = 200;
    public const int DESCRIPTION_MAX = 320;

    /**
     * @param array<string, string|int|null> $values variables sans accolades (« metier » => « Plombier »)
     */
    public static function render(?string $template, array $values): ?string
    {
        if (null === $template || '' === trim($template)) {
            return null;
        }
        $replacements = [];
        foreach ($values as $name => $value) {
            $replacements['{'.$name.'}'] = null === $value ? '' : (string) $value;
        }
        $text = (string) preg_replace('/\{[a-z_]+\}/', '', strtr($template, $replacements));

        return trim((string) preg_replace('/\s{2,}/', ' ', $text)) ?: null;
    }
}
