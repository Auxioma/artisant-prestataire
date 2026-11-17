<?php

declare(strict_types=1);

namespace App\Shared\Application\Settings;

enum SettingType: string
{
    case Integer = 'int';
    case NullableInteger = 'nullable_int';
    case Boolean = 'bool';
    case IntegerList = 'int_list';
    case StringList = 'string_list';
    case Choice = 'choice';
    case NullableDecimal = 'nullable_decimal';
    case WeightMap = 'weight_map';
    case NullableUrl = 'nullable_url';
}
