<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

/** Administrateur connecté au back-office, pour les écrans BO des autres modules (null hors pare-feu admin). */
interface CurrentAdministrator
{
    public function id(): ?string;
}
