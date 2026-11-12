<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Questionnaire;

/** P11.3 / BO06 — types de champ du constructeur de questionnaire. */
enum FieldType: string
{
    /** Une seule réponse parmi des options. */
    case Choice = 'choice';
    /** Plusieurs réponses parmi des options. */
    case Multi = 'multi';
    case YesNo = 'yesno';
    case Text = 'text';
    /** Nombre entier (surface, quantité, étage…), borné. */
    case Number = 'number';

    public function hasOptions(): bool
    {
        return self::Choice === $this || self::Multi === $this;
    }
}
