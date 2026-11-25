<?php

declare(strict_types=1);

namespace App\Search\Application\Port;

/** Moteur de recherche injoignable ou en erreur : l'appelant bascule sur le repli PostgreSQL (ADR-0004). */
final class SearchUnavailable extends \RuntimeException
{
}
