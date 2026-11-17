<?php

declare(strict_types=1);

namespace App\Identity\Application\Admin;

use App\Shared\Application\Bus\Query;

/** BO02.1 — comptes clients filtrés (nom, e-mail, téléphone ; statut ; type), paginés. Réponse : ClientListPage. */
final readonly class ClientList implements Query
{
    public function __construct(public string $search = '', public ?string $status = null, public ?string $type = null, public int $page = 1, public int $perPage = 25)
    {
    }
}
