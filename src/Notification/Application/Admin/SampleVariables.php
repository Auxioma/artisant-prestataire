<?php

declare(strict_types=1);

namespace App\Notification\Application\Admin;

/**
 * Valeurs d'exemple (fictives) des variables d'un modèle, pour l'aperçu, la validation et l'envoi de test : dates pour
 * les noms de date, booléens pour les drapeaux connus, nombres pour les durées et quantités, liens fictifs.
 */
final class SampleVariables
{
    private const array FLAGS = ['refuse', 'suite_donnee', 'changement_adresse', 'sans_mot_de_passe', 'rappel', 'urgent', 'premier'];
    private const array NUMBERS = ['validite_jours', 'validite_heures', 'validite_minutes', 'credits', 'nombre', 'jours', 'max_devis', 'restants'];
    private const array TEXTS = [
        'prenom' => 'Camille', 'nom' => 'Martin', 'artisan' => 'Atelier Exemple', 'metier' => 'Plombier', 'ville' => 'Rouen',
        'numero' => 'DV-2026-000123', 'demande' => 'DM-2026-000042', 'montant' => '1 250,00 €', 'motif' => 'motif d’exemple',
        'contenu' => 'Contenu d’exemple', 'reponse' => 'Réponse d’exemple de l’équipe.', 'appareil' => 'Chrome sur macOS',
    ];
    private const string BASE = 'https://trouvemoi.example';

    /**
     * @param list<string> $names
     *
     * @return array<string, scalar|\DateTimeInterface|null>
     */
    public static function for(array $names, \DateTimeImmutable $now): array
    {
        $values = [];
        foreach ($names as $name) {
            $values[$name] = match (true) {
                \in_array($name, self::FLAGS, true) => false,
                \in_array($name, self::NUMBERS, true) => 7,
                str_starts_with($name, 'lien') => self::BASE.'/exemple',
                str_contains($name, 'date') || str_contains($name, 'expiration') || str_ends_with($name, '_le') => $now,
                default => self::TEXTS[$name] ?? 'exemple',
            };
        }

        return $values;
    }
}
