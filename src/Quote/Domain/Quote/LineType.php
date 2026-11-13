<?php

declare(strict_types=1);

namespace App\Quote\Domain\Quote;

/** PR04.2 — type de ligne ; les sections et commentaires ne portent aucun montant. */
enum LineType: string
{
    case Service = 'service';
    case Supply = 'supply';
    case Labour = 'labour';
    case Travel = 'travel';
    case Section = 'section';
    case Comment = 'comment';

    public function isPriced(): bool
    {
        return !\in_array($this, [self::Section, self::Comment], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Prestation',
            self::Supply => 'Fourniture',
            self::Labour => 'Main-d’œuvre',
            self::Travel => 'Déplacement',
            self::Section => 'Section',
            self::Comment => 'Commentaire',
        };
    }
}
