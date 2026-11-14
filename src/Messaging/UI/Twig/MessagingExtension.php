<?php

declare(strict_types=1);

namespace App\Messaging\UI\Twig;

use App\Messaging\Application\Query\UnreadMessages;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `tm_unread_messages()` : fils non lus de l'utilisateur connecté (pastilles « Mes messages » des espaces). Compté une
 * fois par requête ; remis à zéro entre deux requêtes (noyau réutilisé, mode worker).
 */
final class MessagingExtension extends AbstractExtension implements ResetInterface
{
    private ?int $count = null;

    public function __construct(private readonly QueryBus $queries, private readonly CurrentUser $user)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('tm_unread_messages', $this->unread(...))];
    }

    public function reset(): void
    {
        $this->count = null;
    }

    public function unread(): int
    {
        $userId = $this->user->id();
        if (null === $userId) {
            return 0;
        }
        if (null === $this->count) {
            $count = $this->queries->ask(new UnreadMessages($userId));
            $this->count = \is_int($count) ? $count : 0;
        }

        return $this->count;
    }
}
