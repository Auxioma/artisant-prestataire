<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

/**
 * Sujets de notification que le titulaire peut couper (C07 « Mes notifications », Figma 231:12840). Les messages
 * indispensables (sécurité, acceptation de devis, rendez-vous proposé / confirmé) n'appartiennent à aucun sujet : ils
 * partent toujours (CDC C07.3).
 */
enum NotificationTopic: string
{
    case NewQuotes = 'nouveaux_devis';
    case RequestFollowUp = 'suivi_demandes';
    case AppointmentReminders = 'rappels_rdv';

    private const array CODES = [
        'nouveaux_devis' => ['E-12', 'E-12b'],
        'suivi_demandes' => ['E-10', 'E-19', 'E-19b'],
        'rappels_rdv' => ['E-15'],
    ];

    public static function of(string $code): ?self
    {
        foreach (self::CODES as $topic => $codes) {
            if (\in_array($code, $codes, true)) {
                return self::from($topic);
            }
        }

        return null;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return self::CODES[$this->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::NewQuotes => 'Nouveaux devis',
            self::RequestFollowUp => 'Suivi de mes demandes',
            self::AppointmentReminders => 'Rappels de rendez-vous',
        };
    }
}
