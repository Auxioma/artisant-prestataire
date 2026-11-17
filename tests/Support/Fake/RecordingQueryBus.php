<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Shared\Application\Bus\Query;
use App\Shared\Application\Bus\QueryBus;

/** Bus de requêtes de test : enregistre chaque requête et délègue la réponse à une fonction. */
final class RecordingQueryBus implements QueryBus
{
    /** @var list<Query> */
    public array $asked = [];

    /** @param \Closure(Query): mixed $responder */
    public function __construct(private readonly \Closure $responder)
    {
    }

    public function ask(Query $query): mixed
    {
        $this->asked[] = $query;

        return ($this->responder)($query);
    }
}
