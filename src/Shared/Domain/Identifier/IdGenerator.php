<?php

declare(strict_types=1);

namespace App\Shared\Domain\Identifier;

interface IdGenerator
{
    /**
     * @return string UUID v7 en minuscules
     */
    public function next(): string;
}
