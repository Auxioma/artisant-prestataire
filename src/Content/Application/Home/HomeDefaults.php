<?php

declare(strict_types=1);

namespace App\Content\Application\Home;

use App\Content\Domain\Home\HomeBlockType;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;

/**
 * Contenu initial de l'accueil, repris du CDC (P01.2 à P01.9) tant qu'aucune version n'est publiée en BO10.1. Les
 * nombres (cartes, rayon) viennent des paramètres ; le nombre de devis reste une variable (RG-014).
 */
final readonly class HomeDefaults
{
    /** Ordre par défaut des blocs (maquette « Accueil — Desktop 1440 · V2 »). */
    private const array ORDER = [
        HomeBlockType::Header, HomeBlockType::Hero, HomeBlockType::Reassurance, HomeBlockType::QuickLinks, HomeBlockType::Categories,
        HomeBlockType::TopRated, HomeBlockType::Pros, HomeBlockType::HowItWorks, HomeBlockType::Cta, HomeBlockType::WhyUs, HomeBlockType::Footer,
    ];

    public function __construct(private SettingsProvider $settings)
    {
    }

    /** @return list<array{type: string, enabled: bool, settings: array<string, mixed>}> */
    public function blocks(): array
    {
        /** @var array<string, array<string, mixed>> $settings */
        $settings = [
            HomeBlockType::Hero->value => [
                'title' => 'Trouvez l’artisan de confiance près de chez vous',
                'subtitle' => 'Comparez jusqu’à {max_devis} devis gratuits de professionnels vérifiés, sans engagement.',
                'image_alt' => '',
                'job_placeholder' => 'Quel service recherchez-vous ?',
                'job_example' => 'Ex : Plomberie, Rénovation, Jardin…',
                'place_placeholder' => 'Où ? (ville, code postal)',
                'place_example' => 'Ex : Forges-les-Eaux (76440)',
                'button' => 'Rechercher',
                'veil' => 0,
            ],
            HomeBlockType::Reassurance->value => [
                'mobile_layout' => 'carousel',
                'items' => [
                    ['icon' => 'shield', 'label' => 'Professionnels vérifiés', 'target' => ''],
                    ['icon' => 'zap', 'label' => 'Devis rapides et gratuits', 'target' => ''],
                    ['icon' => 'calendar', 'label' => 'Disponibilités en ligne', 'target' => ''],
                    ['icon' => 'star', 'label' => 'Avis clients certifiés', 'target' => 'how_it_works'],
                ],
            ],
            HomeBlockType::QuickLinks->value => [
                'items' => [
                    ['label' => 'Trouver un professionnel', 'icon' => 'search', 'target' => 'search', 'style' => 'blue', 'audience' => 'all'],
                    ['label' => 'Déposer ma demande de devis', 'icon' => 'file', 'target' => 'request', 'style' => 'orange', 'audience' => 'all'],
                    ['label' => 'Créer mon compte pro', 'icon' => 'userPlus', 'target' => 'pro_signup', 'style' => 'secondary', 'audience' => 'all'],
                    ['label' => 'Les mieux notés', 'icon' => 'star', 'target' => 'top_rated', 'style' => 'secondary', 'audience' => 'all'],
                    ['label' => 'Voir les disponibilités', 'icon' => 'calendar', 'target' => 'availability', 'style' => 'secondary', 'audience' => 'all'],
                    ['label' => 'Nos offres & promotions', 'icon' => 'tag', 'target' => 'offers', 'style' => 'secondary', 'audience' => 'all'],
                ],
            ],
            HomeBlockType::Categories->value => [
                'title' => 'Explorez nos catégories de services',
                'link_label' => 'Voir toutes les catégories',
                'max' => $this->settings->int(SettingKey::HomeCategoriesMax),
                'show_counts' => false,
            ],
            HomeBlockType::TopRated->value => [
                'title' => 'Les mieux notés',
                'text' => 'Découvrez les professionnels les mieux évalués par nos clients',
                'link_label' => 'Voir le classement complet',
                'count' => $this->settings->int(SettingKey::HomeProsCount),
            ],
            HomeBlockType::Pros->value => [
                'title' => 'Des professionnels près de chez vous',
                'count' => $this->settings->int(SettingKey::HomeProsCount),
                'radius' => $this->settings->int(SettingKey::SearchDefaultRadiusKm),
                'mode' => 'proximity',
            ],
            HomeBlockType::HowItWorks->value => [
                'title' => 'Comment ça marche ?',
                'link_label' => '',
                'items' => [
                    ['icon' => 'search', 'title' => 'Je recherche un professionnel', 'text' => 'Par métier, ville ou code postal.'],
                    ['icon' => 'file', 'title' => 'Je demande un devis gratuit', 'text' => 'Décrivez votre projet en quelques clics.'],
                    ['icon' => 'calendar', 'title' => 'Je planifie mon intervention', 'text' => 'Le professionnel vous recontacte rapidement.'],
                ],
            ],
            HomeBlockType::Cta->value => [
                'title' => 'Un projet en tête ?',
                'highlight' => 'Des travaux à réaliser ?',
                'text' => 'Déposez votre demande, recevez jusqu’à {max_devis} devis gratuits et choisissez le professionnel qui vous convient.',
                'button' => 'Déposer ma demande de devis',
                'background' => 'navy',
            ],
            HomeBlockType::WhyUs->value => [
                'title' => 'Pourquoi choisir TrouveMoi ?',
                'items' => [
                    ['icon' => 'shield', 'title' => 'Des professionnels sélectionnés et évalués', 'text' => ''],
                    ['icon' => 'users', 'title' => 'Un réseau local et national', 'text' => ''],
                    ['icon' => 'euro', 'title' => 'Des devis gratuits et sans engagement', 'text' => ''],
                    ['icon' => 'leaf', 'title' => 'Une solution simple, rapide et sécurisée', 'text' => ''],
                ],
            ],
        ];

        $blocks = [];
        foreach (self::ORDER as $type) {
            $blocks[] = ['type' => $type->value, 'enabled' => true, 'settings' => $settings[$type->value] ?? []];
        }

        return $blocks;
    }

    /**
     * Complète une version enregistrée avant l'arrivée d'un nouveau type de bloc (ex. « Les mieux notés ») : le bloc
     * manquant est inséré à sa place par défaut, avec son contenu initial, jusqu'à ce qu'il soit réglé et publié.
     *
     * @param list<array{type: string, enabled: bool, settings: array<string, mixed>}> $blocks
     *
     * @return list<array{type: string, enabled: bool, settings: array<string, mixed>}>
     */
    public function complete(array $blocks): array
    {
        $present = array_column($blocks, 'type');
        foreach ($this->blocks() as $index => $default) {
            if (\in_array($default['type'], $present, true)) {
                continue;
            }
            $after = $index > 0 ? array_search(self::ORDER[$index - 1]->value, array_column($blocks, 'type'), true) : -1;
            array_splice($blocks, false === $after ? \count($blocks) : $after + 1, 0, [$default]);
        }

        return array_values($blocks);
    }
}
