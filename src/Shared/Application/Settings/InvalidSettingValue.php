<?php

declare(strict_types=1);

namespace App\Shared\Application\Settings;

final class InvalidSettingValue extends \InvalidArgumentException
{
    public static function for(SettingKey $key, string $reason): self
    {
        return new self(\sprintf('Valeur invalide pour « %s » : %s', $key->value, $reason));
    }
}
