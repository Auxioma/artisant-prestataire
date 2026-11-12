<?php

declare(strict_types=1);

namespace App\Administration\Application\Port;

/** Administrateur connecté au back-office (null hors pare-feu admin). */
interface CurrentAdmin
{
    public function id(): ?string;

    public function name(): ?string;

    public function roleLabel(): ?string;
}
