<?php

declare(strict_types=1);

namespace App\Content\Application\Home;

use App\Content\Domain\Home\HomeBlockType;
use App\Content\Domain\Home\HomeContentViolation;

/**
 * Champs éditables de chaque bloc de l'accueil (CDC P01, BO10.1) et leur validation. Les textes acceptent les variables
 * {max_devis} et {free_credits}, toujours lues dans les paramètres au rendu (RG-014).
 */
final class HomeBlockSchema
{
    public const array VARIABLES = ['{max_devis}', '{free_credits}'];
    /** Pictogrammes proposés (sprite du design system). */
    public const array ICONS = ['shield', 'checkC', 'zap', 'calendar', 'calCheck', 'star', 'search', 'file', 'users', 'userPlus', 'tag', 'leaf', 'pin', 'handshake', 'clock', 'trophy', 'rocket', 'home', 'briefcase', 'msg', 'lock', 'heart'];
    /** Cibles des liens (route publique) : un lien dont la page n'existe pas ou est désactivée est masqué au rendu. */
    public const array TARGETS = [
        'search' => ['route' => 'public_search', 'label' => 'Recherche (P02)'],
        'request' => ['route' => 'request_new', 'label' => 'Demande de devis (P11)'],
        'pro_signup' => ['route' => 'identity_register_pro', 'label' => 'Inscription pro'],
        'top_rated' => ['route' => 'search_top_rated', 'label' => 'Les mieux notés (P07)'],
        'availability' => ['route' => 'search_availability', 'label' => 'Disponibilités (P08)'],
        'offers' => ['route' => 'search_offers', 'label' => 'Offres & promotions (P09)'],
        'directory' => ['route' => 'search_directory', 'label' => 'Nos professionnels (P06)'],
        'categories' => ['route' => 'catalog_categories', 'label' => 'Catégories (P03)'],
        'how_it_works' => ['route' => 'content_how_it_works', 'label' => 'Comment ça marche ? (P12)'],
    ];
    public const array LINK_STYLES = ['blue' => 'Dégradé bleu', 'orange' => 'Dégradé orange', 'secondary' => 'Secondaire'];
    public const array AUDIENCES = ['all' => 'Tous', 'guest' => 'Visiteurs', 'client' => 'Clients', 'artisan' => 'Artisans'];
    public const array PROS_MODES = ['proximity' => 'Proximité', 'top_rated' => 'Mieux notés'];
    public const array CTA_BACKGROUNDS = ['navy' => 'Marine', 'blue' => 'Dégradé bleu'];
    public const array MOBILE_LAYOUTS = ['carousel' => 'Carrousel', 'grid' => 'Grille 2 × 2'];

    private const int TITLE = 120;
    private const int TEXT = 300;
    private const int LABEL = 60;
    private const int VEIL_MAX = 60;
    private const int CARDS_MAX = 48;
    private const int RADIUS_MAX = 200;

    /**
     * @return array<string, array<string, mixed>> définition des champs : type (text, textarea, int, choice, bool, items)
     */
    public static function fields(HomeBlockType $type): array
    {
        $item = static fn (array $fields, int $min, int $max): array => ['type' => 'items', 'label' => 'Éléments', 'fields' => $fields, 'min' => $min, 'max' => $max];

        return match ($type) {
            HomeBlockType::Hero => [
                'title' => ['type' => 'text', 'label' => 'Titre H1', 'max' => self::TITLE, 'required' => true],
                'subtitle' => ['type' => 'textarea', 'label' => 'Sous-titre', 'max' => self::TEXT, 'required' => true, 'variables' => true],
                'image_alt' => ['type' => 'text', 'label' => 'Texte alternatif de l’image (vide : image décorative)', 'max' => self::TITLE],
                'job_placeholder' => ['type' => 'text', 'label' => 'Placeholder service', 'max' => self::LABEL, 'required' => true],
                'place_placeholder' => ['type' => 'text', 'label' => 'Placeholder lieu', 'max' => self::LABEL, 'required' => true],
                'button' => ['type' => 'text', 'label' => 'Libellé du bouton', 'max' => self::LABEL, 'required' => true],
                'veil' => ['type' => 'int', 'label' => 'Opacité du voile marine (%)', 'min' => 0, 'max' => self::VEIL_MAX],
            ],
            HomeBlockType::Reassurance => [
                'mobile_layout' => ['type' => 'choice', 'label' => 'Affichage mobile', 'choices' => self::MOBILE_LAYOUTS],
                'items' => $item([
                    'icon' => ['type' => 'choice', 'label' => 'Picto', 'choices' => array_combine(self::ICONS, self::ICONS)],
                    'label' => ['type' => 'text', 'label' => 'Libellé', 'max' => self::LABEL, 'required' => true],
                    'target' => ['type' => 'choice', 'label' => 'Lien', 'choices' => ['' => 'Aucun'] + array_map(static fn (array $t): string => $t['label'], self::TARGETS)],
                ], 2, 6),
            ],
            HomeBlockType::QuickLinks => [
                'items' => $item([
                    'label' => ['type' => 'text', 'label' => 'Libellé', 'max' => self::LABEL, 'required' => true],
                    'icon' => ['type' => 'choice', 'label' => 'Picto', 'choices' => array_combine(self::ICONS, self::ICONS)],
                    'target' => ['type' => 'choice', 'label' => 'Lien', 'choices' => array_map(static fn (array $t): string => $t['label'], self::TARGETS)],
                    'style' => ['type' => 'choice', 'label' => 'Style', 'choices' => self::LINK_STYLES],
                    'audience' => ['type' => 'choice', 'label' => 'Visible par', 'choices' => self::AUDIENCES],
                ], 1, 6),
            ],
            HomeBlockType::Categories => [
                'title' => ['type' => 'text', 'label' => 'Titre', 'max' => self::TITLE, 'required' => true],
                'link_label' => ['type' => 'text', 'label' => 'Libellé du lien', 'max' => self::LABEL, 'required' => true],
                'max' => ['type' => 'int', 'label' => 'Nombre maximum de catégories', 'min' => 1, 'max' => self::CARDS_MAX],
                'show_counts' => ['type' => 'bool', 'label' => 'Afficher le nombre de professionnels'],
            ],
            HomeBlockType::Pros => [
                'title' => ['type' => 'text', 'label' => 'Titre', 'max' => self::TITLE, 'required' => true],
                'count' => ['type' => 'int', 'label' => 'Nombre de cartes', 'min' => 1, 'max' => self::CARDS_MAX],
                'radius' => ['type' => 'int', 'label' => 'Rayon par défaut (km)', 'min' => 1, 'max' => self::RADIUS_MAX],
                'mode' => ['type' => 'choice', 'label' => 'Mode', 'choices' => self::PROS_MODES],
            ],
            HomeBlockType::HowItWorks => [
                'title' => ['type' => 'text', 'label' => 'Titre', 'max' => self::TITLE, 'required' => true],
                'link_label' => ['type' => 'text', 'label' => 'Libellé du lien « En savoir plus »', 'max' => self::LABEL, 'required' => true],
                'items' => $item([
                    'icon' => ['type' => 'choice', 'label' => 'Picto', 'choices' => array_combine(self::ICONS, self::ICONS)],
                    'title' => ['type' => 'text', 'label' => 'Titre', 'max' => self::LABEL, 'required' => true],
                    'text' => ['type' => 'text', 'label' => 'Texte', 'max' => self::TITLE, 'required' => true],
                ], 3, 5),
            ],
            HomeBlockType::Cta => [
                'title' => ['type' => 'text', 'label' => 'Titre', 'max' => self::TITLE, 'required' => true],
                'text' => ['type' => 'textarea', 'label' => 'Texte', 'max' => self::TEXT, 'required' => true, 'variables' => true],
                'button' => ['type' => 'text', 'label' => 'Libellé du bouton', 'max' => self::LABEL, 'required' => true],
                'background' => ['type' => 'choice', 'label' => 'Couleur de fond', 'choices' => self::CTA_BACKGROUNDS],
            ],
            HomeBlockType::WhyUs => [
                'title' => ['type' => 'text', 'label' => 'Titre', 'max' => self::TITLE, 'required' => true],
                'items' => $item([
                    'icon' => ['type' => 'choice', 'label' => 'Picto', 'choices' => array_combine(self::ICONS, self::ICONS)],
                    'title' => ['type' => 'text', 'label' => 'Titre', 'max' => self::LABEL, 'required' => true],
                    'text' => ['type' => 'text', 'label' => 'Texte', 'max' => self::TITLE],
                ], 2, 6),
            ],
            HomeBlockType::Header, HomeBlockType::Footer => [],
        };
    }

    /**
     * Valide et normalise la saisie d'un bloc (lignes de liste vides ignorées).
     *
     * @param array<array-key, mixed> $input
     *
     * @return array<string, mixed>
     */
    public static function normalize(HomeBlockType $type, array $input): array
    {
        $settings = [];
        foreach (self::fields($type) as $name => $field) {
            $settings[$name] = self::value($field, $input[$name] ?? null, self::label($field));
        }

        return $settings;
    }

    /** @param array<string, mixed> $field */
    private static function value(array $field, mixed $raw, string $label): mixed
    {
        switch ($field['type']) {
            case 'items':
                $rows = [];
                foreach (\is_array($raw) ? $raw : [] as $row) {
                    $texts = array_filter(self::subFields($field), static fn (array $sub): bool => 'text' === $sub['type']);
                    if (!\is_array($row) || [] === array_filter(array_keys($texts), static fn (string $name): bool => \is_string($row[$name] ?? null) && '' !== trim($row[$name]))) {
                        continue; // ligne vide : aucun texte saisi (les listes déroulantes gardent leur valeur par défaut)
                    }
                    $item = [];
                    foreach (self::subFields($field) as $name => $sub) {
                        $item[$name] = self::value($sub, $row[$name] ?? null, $label.' — '.self::label($sub));
                    }
                    $rows[] = $item;
                }
                $count = \count($rows);
                if ($count < self::int($field['min']) || $count > self::int($field['max'])) {
                    throw new HomeContentViolation(\sprintf('%s : entre %d et %d éléments.', $label, self::int($field['min']), self::int($field['max'])));
                }

                return $rows;
            case 'int':
                if (!\is_string($raw) && !\is_int($raw) || !is_numeric($raw) || (int) $raw < self::int($field['min']) || (int) $raw > self::int($field['max'])) {
                    throw new HomeContentViolation(\sprintf('%s : nombre entre %d et %d attendu.', $label, self::int($field['min']), self::int($field['max'])));
                }

                return (int) $raw;
            case 'bool':
                return \in_array($raw, [true, '1', 'on'], true);
            case 'choice':
                $choices = \is_array($field['choices']) ? $field['choices'] : [];
                $value = \is_string($raw) ? $raw : '';
                if (!\array_key_exists($value, $choices)) {
                    throw new HomeContentViolation(\sprintf('%s : choix invalide.', $label));
                }

                return $value;
            default:
                $text = \is_string($raw) ? trim(str_replace(["\r\n", "\r"], "\n", $raw)) : '';
                if (true === ($field['required'] ?? false) && '' === $text) {
                    throw new HomeContentViolation(\sprintf('%s : champ obligatoire.', $label));
                }
                if (mb_strlen($text) > self::int($field['max'])) {
                    throw new HomeContentViolation(\sprintf('%s : %d caractères au maximum.', $label, self::int($field['max'])));
                }

                return $text;
        }
    }

    /**
     * @param array<string, mixed> $field
     *
     * @return array<string, array<string, mixed>>
     */
    private static function subFields(array $field): array
    {
        $fields = [];
        foreach (\is_array($field['fields']) ? $field['fields'] : [] as $name => $sub) {
            if (\is_string($name) && \is_array($sub)) {
                /** @var array<string, mixed> $sub */
                $fields[$name] = $sub;
            }
        }

        return $fields;
    }

    /** @param array<string, mixed> $field */
    private static function label(array $field): string
    {
        return \is_string($field['label'] ?? null) ? $field['label'] : '';
    }

    private static function int(mixed $value): int
    {
        return \is_int($value) ? $value : 0;
    }
}
