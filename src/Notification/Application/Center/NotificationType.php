<?php

declare(strict_types=1);

namespace App\Notification\Application\Center;

/** C08 — familles de notifications pour le filtre et l'icône : devis, rendez-vous, messages, avis, compte. */
enum NotificationType: string
{
    case Quote = 'devis';
    case Appointment = 'rendez-vous';
    case Message = 'messages';
    case Review = 'avis';
    case Account = 'compte';

    private const array CODES = [
        'devis' => ['E-10', 'E-10b', 'E-11', 'E-12', 'E-12b', 'E-13', 'E-19', 'E-19b', 'E-19c', 'E-30', 'E-31', 'E-32', 'E-33', 'E-34', 'E-35', 'E-35b'],
        'rendez-vous' => ['E-14', 'E-15', 'E-36'],
        'messages' => ['E-17'],
        'avis' => ['E-16', 'E-18', 'E-37', 'E-38'],
    ];

    public static function of(string $code): self
    {
        foreach (self::CODES as $type => $codes) {
            if (\in_array($code, $codes, true)) {
                return self::from($type);
            }
        }

        return self::Account;
    }

    /** @return list<string>|null codes de la famille ; null pour « Compte » (tous les autres) */
    public function codes(): ?array
    {
        return self::CODES[$this->value] ?? null;
    }

    /** @return list<string> codes de toutes les familles explicites (« Compte » = le reste) */
    public static function knownCodes(): array
    {
        return array_merge(...array_values(self::CODES));
    }

    public function label(): string
    {
        return match ($this) {
            self::Quote => 'Devis',
            self::Appointment => 'Rendez-vous',
            self::Message => 'Messages',
            self::Review => 'Avis',
            self::Account => 'Compte',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Quote => 'file',
            self::Appointment => 'calendar',
            self::Message => 'msg',
            self::Review => 'star',
            self::Account => 'shield',
        };
    }
}
