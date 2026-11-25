<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Application\Bus\Query;
use App\Shared\Application\Bus\QueryBus;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerQueryBus implements QueryBus
{
    use HandleTrait;

    public function __construct(MessageBusInterface $bus)
    {
        $this->messageBus = $bus;
    }

    public function ask(Query $query): mixed
    {
        try {
            return $this->handle($query);
        } catch (HandlerFailedException $e) {
            throw $e->getPrevious() ?? $e;
        }
    }
}
