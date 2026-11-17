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
                'place_placeholder' => 'Où ? (ville, code postal)',
                'button' => 'Rechercher',
                'veil' => 30,
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
            HomeBlockType::Pros->value => [
                'title' => 'Des professionnels près de chez vous',
                'count' => $this->settings->int(SettingKey::HomeProsCount),
                'radius' => $this->settings->int(SettingKey::SearchDefaultRadiusKm),
                'mode' => 'proximity',
            ],
            HomeBlockType::HowItWorks->value => [
                'title' => 'Comment ça marche ?',
                'link_label' => 'En savoir plus',
                'items' => [
                    ['icon' => 'search', 'title' => 'Je recherche un professionnel', 'text' => 'Par métier, ville ou code postal.'],
                    ['icon' => 'file', 'title' => 'Je demande un devis gratuit', 'text' => 'Décrivez votre projet en quelques clics.'],
                    ['icon' => 'calendar', 'title' => 'Je planifie mon intervention', 'text' => 'Le professionnel vous recontacte rapidement.'],
                ],
            ],
            HomeBlockType::Cta->value => [
                'title' => 'Un projet en tête ? Des travaux à réaliser ?',
                'text' => 'Recevez jusqu’à {max_devis} devis gratuits de professionnels vérifiés.',
                'button' => 'Déposer ma demande de devis',
                'background' => 'navy',
            ],
            HomeBlockType::WhyUs->value => [
                'title' => 'Pourquoi choisir TrouveMoi ?',
                'items' => [
                    ['icon' => 'shield', 'title' => 'Des professionnels sélectionnés et évalués', 'text' => ''],
                    ['icon' => 'users', 'title' => 'Un réseau local et national', 'text' => ''],
                    ['icon' => 'tag', 'title' => 'Des devis gratuits et sans engagement', 'text' => ''],
                    ['icon' => 'leaf', 'title' => 'Une solution simple, rapide et sécurisée', 'text' => ''],
                ],
            ],
        ];

        $blocks = [];
        foreach (HomeBlockType::cases() as $type) {
            $blocks[] = ['type' => $type->value, 'enabled' => true, 'settings' => $settings[$type->value] ?? []];
        }

        return $blocks;
    }
}
